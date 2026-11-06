<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native invoices and encrypted operation evidence are needed to prove payment refusal and unchanged authority.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use App\Models\Invoice;
use Paymenter\Extensions\Servers\Domains\Data\{Action, Capabilities, CostUnit, Disposition, DriverResult, Evidence, Money, Query, QuoteEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AcquisitionRetirement, NativeCancellation, NativeIdentity, NativeIntentSchema, PayCheck, RegistrarRegistry, ReminderSchema};
extract(offerCommerceFixture());
NativeIntentSchema::install(); ReminderSchema::install(); NativeIdentity::boot(); NativeCancellation::boot();
app()->instance(AcquisitionRetirement::class, new AcquisitionRetirement($clock));
$live = (object)['name' => '', 'cost' => '7.0000'];
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify($environment, array $credentials): string { return $credentials['token']; }
}, static fn ($b) => new Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($b, new Capabilities($b, []),
    new DriverResult($b, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
        new Evidence(available: true, premium: false, quote: new QuoteEvidence(new Query($live->name, Action::Register, 1),
            new Money($live->cost, 'USD'), CostUnit::TermTotal, 'enom_product_price', $clock->now(), $clock->now()->modify('+5 minutes'))))));
$pay = new class(new AccountBindings($registry), $clock) extends PayCheck { use OfferCommerceRoute; };
$pay->fixtureOffers = $offers;
$user = commerceUser(); $public = $storefront->offer('com', 'register', 1);
foreach (['7.0000' => true, '6.0000' => false, '5.9900' => false] as $cost => $refused) {
    $live->cost = $cost; $live->name = 'live-ceiling-'.str_replace('.', '-', $cost).'-placeholder.com';
    $cart = commerceCart($user); commerceAdd($cart, $public['product'], $live->name); commerceCheckout($user, $cart);
    $op = Operation::latest('id')->firstOrFail(); $invoice = Invoice::findOrFail($op->invoice_id);
    same('6.0000', $op->cost['estimated'], 'The invoice freezes its acquisition spending ceiling');
    same('7.80', (string)$op->amount, 'The live rise remains below the issued amount');
    $frozen = [$op->policy['checkout'], $op->cost, (string)$op->amount];
    $notice = $pay->stop($invoice);
    same($refused ? 'changed' : null, $notice['why'] ?? null, 'Live quote above the frozen ceiling refuses payment: '.$cost);
    same($frozen, [$op->fresh()->policy['checkout'], $op->fresh()->cost, (string)$op->fresh()->amount], 'Payment keeps frozen invoice authority');
    same($refused ? 'cancelled' : 'pending', $invoice->fresh()->status, 'Only the over-ceiling quote retires unpaid debt');
}
$nativeSuccessful = true;
echo "Live payment quote enforces the frozen ceiling below the invoice total.\n";
