<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real native obligations are required for retirement, frozen evidence and once-only late credit.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Edit, Intent, Query, Context};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement, NativeCancellation, NativeIdentity, NativeIntentSchema, PayCheck, ReminderSchema};
expect(method_exists(PayCheck::class, 'catalogue'), 'PayCheck needs a dormant local offer fence before trusting its minute cache');
require __DIR__.'/Support/offer-commerce.php';
extract(offerCommerceFixture());
$pay = new class($bindings, $clock) extends PayCheck { use OfferCommerceRoute; };
$pay->fixtureOffers = $offers;
$user = commerceUser(); $public = $storefront->offer('com', 'register', 1);
$cart = commerceCart($user); commerceAdd($cart, $public['product'], 'payment-fence-placeholder.com'); commerceCheckout($user, $cart);
$op = Operation::sole(); $invoice = Invoice::findOrFail($op->invoice_id); $before = $reads->calls;
Cache::put('domains.pay-check.'.$op->id, 'clear', 60);
$revision = $ports->readOffers(new Query($binding, ['com'], 'register'), Context::Staff)[0]->revision;
$ports->apply(Edit::override($binding, 'com', new Intent(sell: false)), $revision);
same('unavailable', $pay->stop($invoice)['why'] ?? null, 'Stale local evidence refuses payment instead of trusting the old clear cache');
same(['pending', 'awaiting_payment', $before], [$invoice->fresh()->status, $op->fresh()->state, $reads->calls], 'Local staleness neither retires nor reads the provider');
// Restore public selling, then use the same native fixture for unsent/frozen payment behavior.
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true)),
    $ports->readOffers(new Query($binding, ['com'], 'register'), Context::Staff)[0]->revision);
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class, $invoiceBinding);
NativeIntentSchema::install(); ReminderSchema::install(); NativeIdentity::boot(); NativeCancellation::boot();
app()->instance(AcquisitionRetirement::class, new AcquisitionRetirement($clock));
$live = (object)['available' => true, 'reads' => 0, 'duringRead' => null];
$registry = new Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$registry->register('enom', ['token'], new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify($environment, array $credentials): string { return $credentials['token']; }
}, static function ($b) use ($live) {
    same(0, DB::transactionLevel(), 'Provider construction is outside all locks');
    $driver = new Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($b,
        new Paymenter\Extensions\Servers\Domains\Data\Capabilities($b, []),
        new Paymenter\Extensions\Servers\Domains\Data\DriverResult($b,
            Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted, Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,
            Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,
            new Paymenter\Extensions\Servers\Domains\Data\Evidence(available: $live->available, premium: false)));
    $driver->beforeRead = static function ($method) use ($live): void {
        same(0, DB::transactionLevel(), 'Every provider read is outside all locks');
        if ($method === 'availability') { $live->reads++; if ($live->duringRead) { ($live->duringRead)(); } }
    };
    return $driver;
});
$pay = new class(new Paymenter\Extensions\Servers\Domains\Services\AccountBindings($registry), $clock) extends PayCheck { use OfferCommerceRoute; };
$pay->fixtureOffers = $offers;
$new = static function ($name) use ($user, $public): Operation {
    $cart = commerceCart($user); commerceAdd($cart, $public['product'], $name); commerceCheckout($user, $cart);
    return Operation::latest('id')->firstOrFail();
};
$op = $new('cache-minute-placeholder.com'); $invoice = Invoice::findOrFail($op->invoice_id);
Cache::put('domains.pay-check.'.$op->id, 'taken', 60); $live->available = true;
same(null, $pay->stop($invoice), 'Old minute cache cannot manufacture a taken answer in the new namespace');
same('pending', $invoice->fresh()->status, 'Provider unknown quote keeps frozen unpaid debt');
$before = $live->reads; $pay->stop($invoice); same($before, $live->reads, 'Current unknown answer is coalesced for the minute');
// Credential rotation invalidates even a current namespaced cache at unchanged retail/revisions.
offerCommerceRotate($binding, 'rotated-synthetic-token');
same('unavailable', $pay->stop($invoice)['why'] ?? null, 'Same-identity rotation invalidates the cached pay answer');
same($before, $live->reads, 'Rotated local evidence stops before a provider read');
// A provider answer obtained before a local change is never classified as taken.
$op = $new('read-race-placeholder.com'); $invoice = Invoice::findOrFail($op->invoice_id); $live->available = false;
$original = DB::table('domains_tlds')->where('id', $tlds[0]->id)->value('catalogue_facts');
$live->duringRead = static fn () => DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => null]);
same('unavailable', $pay->stop($invoice)['why'] ?? null, 'Local change after provider observation gets a temporary refusal');
same(['pending', 'awaiting_payment'], [$invoice->fresh()->status, $op->fresh()->state], 'Stale taken answer cannot retire an invoice');
$live->duringRead = null; DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => $original]);
// The same fence is checked again after native retirement locks, before catalogue/domain locks.
$op = $new('retire-race-placeholder.com'); $invoice = Invoice::findOrFail($op->invoice_id); $onRetire = true;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionBeginning::class, static function () use (&$onRetire, $tlds): void {
    if ($onRetire) { $onRetire = false; DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => null]); }
});
same('unavailable', $pay->stop($invoice)['why'] ?? null, 'Local change before locked retirement is temporary, not taken');
same(false, $onRetire, 'Retirement transaction was reached after the provider observation');
same(['pending', 'awaiting_payment'], [$invoice->fresh()->status, $op->fresh()->state], 'Locked guard rolls back retirement');
// With definite current evidence the existing no-send retirement and late-credit proof own closure.
$op = $new('definite-retire-placeholder.com'); $invoice = Invoice::findOrFail($op->invoice_id);
same('taken', $pay->stop($invoice)['why'] ?? null, 'Definite current provider refusal closes the unpaid acquisition');
same(['cancelled', 'rejected', null], [$invoice->fresh()->status, $op->fresh()->state, $op->domain->fresh()->reservation_name], 'Retirement releases only the proved unpaid name');
InvoiceTransaction::withoutEvents(static fn () => InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => '1.00', 'fee' => '0.00', 'status' => 'succeeded']));
$returns = new Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);
$account = Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $binding->serverId)->sole();
$credited = $returns->creditRetired($account); $returns->creditRetired($account);
$credit = App\Models\Credit::where('user_id', $user->id)->sole()->amount;
same('1.00', Paymenter\Extensions\Servers\Domains\Services\RetailLine::decimal($credit), 'Late payment is credited exactly once');
same(1, count($op->fresh()->funding['late_credits']), 'Repeated inspection retains one credit ledger entry');
// Existing money, attempted and uncertain work bypass current offer changes without repricing.
$live->available = true;
foreach (['paid', 'processing', 'pending', 'uncertain'] as $case) {
    $op = $new('frozen-'.$case.'-placeholder.com'); $invoice = Invoice::findOrFail($op->invoice_id);
    if (in_array($case, ['paid', 'processing'], true)) {
        InvoiceTransaction::withoutEvents(static fn () => InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => '0.01', 'fee' => '0.00',
            'status' => $case === 'paid' ? 'succeeded' : 'processing']));
    } else { $op->update(['state' => $case]); }
    $policy = $op->fresh()->policy; $cost = $op->fresh()->cost; $before = $live->reads;
    DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => null]);
    same(null, $pay->stop($invoice), $case.': frozen work stays outside catalogue repricing');
    same([$policy, $cost, $before], [$op->fresh()->policy, $op->fresh()->cost, $live->reads], $case.': money/evidence unchanged and no provider read');
    DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => $original]);
}
$nativeSuccessful = true;
echo "Offer pay check: ok\n";
