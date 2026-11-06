<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real native checkout issues the invoice; staff saves then move the ending's offer revision before payment.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require_once __DIR__.'/Support/staff-panel.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth, DB, Event};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Edit, FeedObserved, Intent, OfferState, Query};
use Paymenter\Extensions\Servers\Domains\Data\{Action, Capabilities, CostUnit, Disposition, DriverResult, Evidence, Money, Query as OrderQuery, QuoteEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AcquisitionOffers, PayCheck, PricingRule, RegistrarRegistry};
require __DIR__.'/Support/offer-commerce.php';
extract(offerCommerceFixture());
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify($environment, array $credentials): string { return $credentials['token']; }
}, static fn ($b) => new Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($b, new Capabilities($b, []),
    new DriverResult($b, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged, new Evidence(available: true, premium: false))));
$pay = new class(new AccountBindings($registry), $clock) extends PayCheck { use OfferCommerceRoute; };
$pay->fixtureOffers = $offers;
$logged = [];
Event::listen(Illuminate\Log\Events\MessageLogged::class, static function ($e) use (&$logged): void { $logged[] = [$e->message, $e->context]; });
$user = commerceUser(); $public = $storefront->offer('com', 'register', 1);
$cart = commerceCart($user); commerceAdd($cart, $public['product'], 'staff-save-placeholder.com'); commerceCheckout($user, $cart);
$op = Operation::latest('id')->firstOrFail(); $invoice = Invoice::findOrFail($op->invoice_id);
$frozen = static fn () => [$op->fresh()->policy, $op->fresh()->cost, (string)$op->fresh()->amount, $invoice->fresh()->status];
$issued = $frozen();
// Staff reads need a staff viewer; the customer stays signed in for pay.
$staff = staffUser([Staff::VIEW]);
$revision = static function () use ($ports, $binding, $staff) {
    $customer = Auth::user(); Auth::setUser($staff);
    try { return $ports->readOffers(new Query($binding, ['com'], 'register'), Context::Staff)[0]->revision; }
    finally { $customer !== null ? Auth::setUser($customer) : Auth::forgetUser(); }
};
$receipt = $op->policy['checkout']['catalogue']['catalogue']['revision'];
// R5: Restore sales saves the same intent, which still moves the ending's offer revision.
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true)), $revision());
expect(get_object_vars($revision()) !== $receipt, 'The staff save moved the offer revision past the invoice receipt');
same(null, $pay->stop($invoice), 'An invoice issued before a staff save on its ending still loads pay');
same($issued, $frozen(), 'Pay check after a staff save keeps the issued invoice and receipt');
// A raised retail price after issue never changes what this invoice charges.
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true),
    PricingRule::validate([...PricingRule::DEFAULT, 'markup' => '50.00'])), $revision());
expect($storefront->offer('com', 'register', 1)['price'] != $public['price'], 'The public price moved after the invoice was issued');
same(null, $pay->stop($invoice), 'A retail change after issue keeps the issued invoice payable');
same($issued, $frozen(), 'The issued invoice amount stands after a retail change');
same([], array_values(array_filter($logged, static fn ($l) => $l[0] === 'Domains pay refused.')), 'Allowed pay logs no refusal');
// A cost rise past the issued total and spending ceiling refuses pay, says what to do, and logs why.
$clock->instant = $now->modify('+1 minute'); $costs = [];
foreach (DB::table('domains_prices')->where('server_id', $binding->serverId)->where('tld', 'com')->whereNotNull('catalogue_observation')->get() as $row) {
    $c = OfferState::cost(OfferState::decode($row->catalogue_observation));
    $costs[] = $c->key() === 'register:1' ? new Cost($c->action, $c->term, true, '20.0000', $c->sourceUnit, $c->source, $clock->now(), $c->freshUntil) : $c;
}
$ports->apply(new FeedObserved($binding, 'com', $costs), $revision());
$refused = $pay->stop($invoice);
same('unavailable', $refused['why'] ?? null, 'A cost above the issued invoice refuses pay without closing it');
same('The price of staff-save-placeholder.com went up after this invoice was issued. You have not been charged. Try again later, or contact us for a new invoice.',
    $refused['text'] ?? null, 'Refused pay shows a plain message with the next step');
same($issued, $frozen(), 'Refused pay leaves the issued invoice and receipt untouched');
$log = array_values(array_filter($logged, static fn ($l) => $l[0] === 'Domains pay refused.'));
same([(int)$invoice->id, 'price_changed'], [$log[0][1]['invoice_id'] ?? null, $log[0][1]['reason'] ?? null], 'Refused pay logs its reason');
// A fixed retail below the new cost withdraws the offer itself; that refusal is still the price message.
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true),
    PricingRule::validate([...PricingRule::DEFAULT, 'mode' => 'fixed', 'fixed' => ['register' => '10.40', 'renew' => '10.40', 'transfer' => '10.40']])), $revision());
commerceReject(fn () => $storefront->offer('com', 'register', 1));
$refused = $pay->stop($invoice);
same(['unavailable', 'The price of staff-save-placeholder.com went up after this invoice was issued. You have not been charged. Try again later, or contact us for a new invoice.'],
    [$refused['why'] ?? null, $refused['text'] ?? null], 'A fixed-price cost refusal shows the plain no-charge message');
$log = array_values(array_filter($logged, static fn ($l) => $l[0] === 'Domains pay refused.'));
same('price_changed', $log[1][1]['reason'] ?? null, 'A fixed-price cost refusal logs the price reason');
same($issued, $frozen(), 'A fixed-price cost refusal leaves the issued invoice untouched');
// The daily cost expires and pay refreshes it with a name quote above the fixed retail: still the price message.
$quoting = new RegistrarRegistry;
$quoting->register('enom', ['token'], new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify($environment, array $credentials): string { return $credentials['token']; }
}, static fn ($b) => new Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($b, new Capabilities($b, []),
    new DriverResult($b, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged, new Evidence(quote: new QuoteEvidence(
        new OrderQuery('staff-save-placeholder.com', Action::Register, 1), new Money('20.0000', 'USD'), CostUnit::TermTotal,
        'enom_product_price', $clock->now(), $clock->now()->modify('+5 minutes'))))));
$clock->instant = $now->modify('+2 minutes'); $costs = [];
foreach (DB::table('domains_prices')->where('server_id', $binding->serverId)->where('tld', 'com')->whereNotNull('catalogue_observation')->get() as $row) {
    $c = OfferState::cost(OfferState::decode($row->catalogue_observation));
    $costs[] = $c->key() === 'register:1' ? new Cost($c->action, $c->term, true, '9.0000', $c->sourceUnit, $c->source, $clock->now(), $clock->now()->modify('+1 second')) : $c;
}
$ports->apply(new FeedObserved($binding, 'com', $costs), $revision());
$clock->instant = $now->modify('+2 minutes 5 seconds');
$pay->fixtureOffers = new AcquisitionOffers($ports, new AccountBindings($quoting), $native);
$refused = $pay->stop($invoice);
same(['unavailable', 'The price of staff-save-placeholder.com went up after this invoice was issued. You have not been charged. Try again later, or contact us for a new invoice.'],
    [$refused['why'] ?? null, $refused['text'] ?? null], 'A refreshed quote above the fixed retail shows the plain no-charge message');
$log = array_values(array_filter($logged, static fn ($l) => $l[0] === 'Domains pay refused.'));
same(['price_changed', Paymenter\Extensions\Servers\Domains\Services\PaymentPriceChanged::class], [$log[2][1]['reason'] ?? null, $log[2][1]['exception_class'] ?? null],
    'A refreshed-quote cost refusal logs the price reason');
same($issued, $frozen(), 'A refreshed-quote cost refusal leaves the issued invoice untouched');
// A fresh over-ceiling acquisition quote survives a later renewal-read failure.
$renew = DB::table('domains_prices')->where('server_id', $binding->serverId)->where('tld', 'com')->where('action', 'renew')->where('term', 1)->sole();
$expired = OfferState::decode($renew->catalogue_observation);
$expired['fresh_until'] = $clock->now()->modify('-1 second')->format(DATE_ATOM);
DB::table('domains_prices')->where('id', $renew->id)->update(['catalogue_observation' => OfferState::json($expired)]);
$refused = $pay->stop($invoice);
same('The price of staff-save-placeholder.com went up after this invoice was issued. You have not been charged. Try again later, or contact us for a new invoice.',
    $refused['text'] ?? null, 'A failed renewal quote preserves the known acquisition price-change refusal');
same($issued, $frozen(), 'Partial refresh failure leaves the issued invoice untouched');
$pay->fixtureOffers = $offers;
// Taking the ending off sale is a temporary refusal, also logged with its cause.
$ports->apply(Edit::override($binding, 'com', new Intent(sell: false)), $revision());
$stopped = $pay->stop($invoice);
same(['unavailable', 'Domains can’t be ordered right now. Please try again later.'], [$stopped['why'] ?? null, $stopped['text'] ?? null],
    'An ending off sale still refuses pay, and a staff stop is not called a price change');
$log = array_values(array_filter($logged, static fn ($l) => $l[0] === 'Domains pay refused.'));
same(['unavailable', DomainException::class], [$log[4][1]['reason'] ?? null, $log[4][1]['exception_class'] ?? null], 'Every refused pay logs its reason');
same('pending', $invoice->fresh()->status, 'Temporary refusals keep the invoice open');
// A missing acquisition observation must preserve the original typed refusal, not fail in the wrapper.
$missing = DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->where('term',1)->sole();
DB::table('domains_prices')->where('id',$missing->id)->update(['catalogue_observation'=>null]);
try {
    $offers->checkoutSelection('missing-cost-placeholder.com','com','register',1,null,
        new Paymenter\Extensions\Servers\Domains\Services\CheckoutRefreshBudget,$binding->serverId,$binding->fingerprint);
    throw new RuntimeException('Missing acquisition observation must refuse checkout');
} catch (DomainException) {}
DB::table('domains_prices')->where('id',$missing->id)->update(['catalogue_observation'=>$missing->catalogue_observation]);
$nativeSuccessful = true;
echo "Offer pay after staff save: ok\n";
