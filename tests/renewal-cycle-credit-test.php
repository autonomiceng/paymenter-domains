<?php
// Enom d1392a8 complimentary/cancelled-unpaid cycles; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
$cycleFixtureGroup='credit';
require __DIR__.'/Support/renewal-cycle-fixture.php';
use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, PricingRule, RenewalFunding};
// Retained server rows are ignored: true cannot override global off, false cannot block global on/unset.
$product->server->settings()->updateOrCreate(['key'=>'credits_auto_use'],['value'=>true,'type'=>'boolean']);
config(['settings.credits_auto_use'=>false]);
[$op, $d, $b, $offBill] = $makeRenewal(placeholderComFixtureName('renew-credit-global-off-placeholder.com'),false, 1, true, static fn () => config(['settings.credits_auto_use'=>false]));
same('pending',$offBill->status,'Native global credit opt-out respected'); same(0,$offBill->transactions()->count(),'No global opt-out settlement');
same(10000,Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$user->credits()->where('currency_code','USD')->sole()->amount),'Global opt-out credit untouched');
$product->server->settings()->where('key','credits_auto_use')->sole()->update(['value'=>false]);
$creditBalance = static fn (): int => PricingRule::minor((string) $user->credits()->where('currency_code', 'USD')->sole()->amount);
$creditBefore = $creditBalance();
config(['settings.credits_auto_use'=>false]);
[$op, $d, $b, $creditBill] = $makeRenewal(placeholderComFixtureName('renew-credit-placeholder.com'), false, 1, false, static fn () => config(['settings.credits_auto_use'=>true]));
same('paid', $creditBill->status, 'Global opt-in pays renewal despite stale server opt-out');
same(1, (int) $creditBill->transactions()->sole()->is_credit_transaction, 'Native credit settlement retained');
same($creditBefore - PricingRule::minor((string) $creditBill->total), $creditBalance(), 'Full credit deducts exactly the invoice amount in minor units');
same('pending', $runRenewal($op, $b)->state, 'Native credit is current paid funding');
config(['settings.credits_auto_use'=>false]);
$creditBefore = $creditBalance();
[$op, $d, $b, $defaultBill] = $makeRenewal(placeholderComFixtureName('renew-credit-default-placeholder.com'), false, 1, false, static function (): void {
    config(['settings'=>collect(config('settings'))->except('credits_auto_use')->all()]);
    expect(!config()->has('settings.credits_auto_use'), 'Global credit setting is absent');
});
same('paid', $defaultBill->status, 'Unset global setting uses native default-on');
same(1, $defaultBill->transactions()->count(), 'Default-on creates one native credit settlement');
same(1, (int) $defaultBill->transactions()->sole()->is_credit_transaction, 'Default-on settlement is credit');
same($creditBefore - PricingRule::minor($defaultBill->total), $creditBalance(), 'Default-on deducts exactly the invoice amount');
same(false, $product->server->settings()->where('key','credits_auto_use')->sole()->value, 'Stale server row is retained unchanged');
config(['settings.credits_auto_use'=>false]); $creditBefore = $creditBalance();
[$op, $d, $b, $scheduledBill] = $makeRenewal(placeholderComFixtureName('renew-credit-scheduled-placeholder.com'), false, 1, false, static function ($d) use ($billing, $product): void {
    $product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'], ['value'=>true,'type'=>'boolean']);
    config(['settings.credits_auto_use'=>true]);
    same('paid', $billing->invoice($d->id)->status, 'Scheduled renewal follows global credit opt-in');
});
same(1, (int) $scheduledBill->transactions()->sole()->is_credit_transaction, 'Scheduled renewal uses one native credit payment');
same($creditBefore - PricingRule::minor($scheduledBill->total), $creditBalance(), 'Scheduled billing deducts credit once');
foreach (['insufficient' => -1, 'exact' => 0] as $case => $delta) {
    config(['settings.credits_auto_use' => false]); // Acquisition stays outside the renewal credit scenario.
    $balanceBefore = null;
    [$op, $d, $b, $bill] = $makeRenewal(placeholderComFixtureName('renew-credit-'.$case.'-placeholder.com'), false, 1, false,
        static function ($domain) use ($user, $delta, &$balanceBefore): void {
            $balanceBefore = PricingRule::minor((string) $domain->service->fresh()->price) + $delta;
            $user->credits()->where('currency_code', 'USD')->sole()->update(['amount' => PricingRule::decimal($balanceBefore)]);
            config(['settings.credits_auto_use' => true]);
        });
    same(PricingRule::minor((string) $bill->total) + $delta, $balanceBefore, 'Credit '.$case.' boundary is relative to actual invoice total');
    same($delta === 0 ? 'paid' : 'pending', $bill->status, 'Auto-use '.$case.' credit invoice status');
    same($delta === 0 ? 1 : 0, $bill->transactions()->count(), 'Auto-use '.$case.' credit transaction count');
    same($delta === 0 ? 0 : $balanceBefore, $creditBalance(), 'Auto-use '.$case.' credit balance');
    if ($delta === 0) { same(1, (int) $bill->transactions()->sole()->is_credit_transaction, 'Exact credit uses native settlement'); }
}
// An earlier synchronous payment during invoice creation changes the native remaining balance.
$prepaidRemaining = null;
Operation::created(static function ($operation) use (&$prepaidRemaining): void {
    if ($operation->action !== 'renew' || $prepaidRemaining === null) { return; }
    $amount = PricingRule::minor($operation->amount) - $prepaidRemaining;
    \App\Helpers\ExtensionHelper::addPayment($operation->invoice_id, null, PricingRule::decimal($amount));
});
foreach (['partial' => 100, 'settled' => 0, 'overpaid' => -1] as $case => $remaining) {
    config(['settings.credits_auto_use'=>false]); $prepaidRemaining = null;
    [$op, $d, $b, $bill] = $makeRenewal(placeholderComFixtureName('renew-credit-'.$case.'-placeholder.com'), false, 1, false, static function () use ($user, $remaining, &$prepaidRemaining): void {
        $user->credits()->where('currency_code','USD')->sole()->update(['amount'=>'100.00']);
        $prepaidRemaining = $remaining; config(['settings.credits_auto_use'=>true]);
    });
    same('paid', $bill->status, 'Already funded '.$case.' invoice stays paid');
    $creditPayments = $bill->transactions()->where('is_credit_transaction',true)->get();
    same($case === 'partial' ? 1 : 0, $creditPayments->count(), 'Only positive remaining balance uses credit');
    same(10000 - max(0, $remaining), $creditBalance(), 'Credit deducts only the remaining balance');
    if ($case === 'partial') { same($remaining, PricingRule::minor($creditPayments->sole()->amount), 'Credit transaction records remaining, not total'); }
}
$prepaidRemaining = null;
same([],array_keys($registrations),'All independent acquisition scenarios ran');
echo "Native renewal cycle matrix passed\n"; $nativeSuccessful=true;
