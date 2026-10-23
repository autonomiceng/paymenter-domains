<?php
// Enom d1392a8 complimentary/cancelled-unpaid cycles; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, PricingRule, RenewalFunding};
[$op, $d, $b, $invoice] = $makeRenewal('renew-replacement.com', false);
$clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z');
$invoice->update(['status' => 'cancelled']); commerceReject(fn () => $billing->invoice($d->id, $user));
$cycles->retireUnpaid($op->id); $retired = $op->fresh()->getRawOriginal(); $debt = $invoice->fresh()->getRawOriginal();
$replacementInvoice = $billing->invoice($d->id, $user); $replacement = Operation::where('invoice_id', $replacementInvoice->id)->sole();
same($op->operation_key.':replacement:1', $replacement->operation_key, 'Terminal unpaid debt receives distinct replacement key');
same($replacementInvoice->id, $billing->invoice($d->id, $user)->id, 'Exactly one current debt');
same($retired, $op->fresh()->getRawOriginal(), 'Original cycle evidence retained'); same($debt, $invoice->fresh()->getRawOriginal(), 'Original debt retained');
InvoiceTransaction::create(['invoice_id' => $replacementInvoice->id, 'amount' => $replacement->amount, 'fee' => '0.00', 'status' => 'succeeded']); $replacementInvoice->refresh()->update(['status'=>'paid']);
commerceReject(fn () => $cycles->retireUnpaid($replacement->id));
$clock->instant = new DateTimeImmutable('2027-10-18T11:59:00Z');
same('pending', $runRenewal($replacement, $b)->state, 'Replacement paid obligation executes once');
$staff = commerceUser(); $role = Role::create(['name'=>'Renewal funding','permissions'=>['admin.domains.manage','admin.domains.fund']]); $staff->update(['role_id'=>$role->id]);
foreach (['complete', 'revoked', 'unknown', 'over', 'exact', 'term', 'unauthorized'] as $case) {
    [$unpaid, $d, $b] = $makeRenewal('complimentary-'.$case.'.com', false);
    $cycles->retireUnpaid($unpaid->id); $years = $case === 'term' ? 3 : 1;
    commerceReject(fn () => $cycles->complimentary($d->id, $user, $years, new Money('8.00','USD'), 'staff_reviewed'));
    $nativePrice = $d->service->fresh()->price; $nativeMode = $d->fresh()->price_mode;
    $comp = $cycles->complimentary($d->id, $staff, $years, new Money($years === 3 ? '24.00':'8.00','USD'), 'staff_reviewed');
    same('complimentary', $comp->funding_basis, 'Independent audited authority'); same($years, (int)$comp->term, 'Explicit fixed complimentary term');
    same(null, $comp->invoice_id, 'No complimentary invoice'); same($nativePrice, $d->service->fresh()->price, 'One-cycle gift preserves paid native retail');
    same($nativeMode,$d->fresh()->price_mode,'One-cycle gift preserves future paid mode');
    same($comp->id, $cycles->complimentary($d->id, $staff, $years, new Money($years === 3 ? '24.00':'8.00','USD'), 'staff_reviewed')->id, 'Cycle idempotent');
    commerceReject(fn () => $cycles->complimentary($d->id, $staff, 2, new Money('16.00','USD'), 'staff_reviewed'));
    commerceReject(fn () => $comp->update(['authorized_by'=>$user->id])); commerceReject(fn () => $comp->fresh()->update(['funding_reason'=>'other']));
    [$engine,$writer,$completion] = $compose($b); $writes = $state->writes;
    if ($case === 'revoked') { $d->refresh()->update(['renewal_policy'=>array_diff_key($d->renewal_policy,['complimentary'=>true])]); }
    if ($case === 'unknown') { $state->price='?'; }
    if ($case === 'over') { $state->price='8.01'; }
    if ($case === 'unauthorized') { $role->update(['permissions'=>[]]); }
    // Role authorization is audited at issuance; revocation of the exact cycle is separate.
    $state->years = $years;
    $sent = $engine->run($comp->id,$writer);
    $refused = in_array($case,['revoked','unknown','over'],true);
    same($refused ? 'held':'pending', $sent->state, 'Complimentary '.$case.' disposition');
    same($writes+($refused?0:1),$state->writes,'Complimentary bounded send');
    if (in_array($case, ['complete', 'exact', 'term'], true)) {
        $d->refresh()->update(['renewal_policy'=>array_diff_key($d->renewal_policy,['complimentary'=>true])]);
        $state->expiry=(2027+$years).'-10-18'; $state->after=$state->expiry; $state->charge=$years===3?'24.00':'8.00';
        same('succeeded',$completion->run($comp->id)->state,'Revocation after send retains incurred completion');
        $clock->instant=new DateTimeImmutable((2027+$years).'-09-27T12:00:00Z');
        same('pending',$billing->invoice($d->id,$user)->status,'Next cycle bills normally after one-cycle gift');
    }
    if ($case === 'unauthorized') { $role->update(['permissions'=>['admin.domains.manage','admin.domains.fund']]); }
}
// Retained server rows are ignored: true cannot override global off, false cannot block global on/unset.
$product->server->settings()->updateOrCreate(['key'=>'credits_auto_use'],['value'=>true,'type'=>'boolean']);
config(['settings.credits_auto_use'=>false]);
[$op, $d, $b, $offBill] = $makeRenewal('renew-credit-global-off.com',false, 1, true, static fn () => config(['settings.credits_auto_use'=>false]));
same('pending',$offBill->status,'Native global credit opt-out respected'); same(0,$offBill->transactions()->count(),'No global opt-out settlement');
same(10000,Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$user->credits()->where('currency_code','USD')->sole()->amount),'Global opt-out credit untouched');
$product->server->settings()->where('key','credits_auto_use')->sole()->update(['value'=>false]);
$creditBalance = static fn (): int => PricingRule::minor((string) $user->credits()->where('currency_code', 'USD')->sole()->amount);
$creditBefore = $creditBalance();
config(['settings.credits_auto_use'=>false]);
[$op, $d, $b, $creditBill] = $makeRenewal('renew-credit.com', false, 1, false, static fn () => config(['settings.credits_auto_use'=>true]));
same('paid', $creditBill->status, 'Global opt-in pays renewal despite stale server opt-out');
same(1, (int) $creditBill->transactions()->sole()->is_credit_transaction, 'Native credit settlement retained');
same($creditBefore - PricingRule::minor((string) $creditBill->total), $creditBalance(), 'Full credit deducts exactly the invoice amount in minor units');
same('pending', $runRenewal($op, $b)->state, 'Native credit is current paid funding');
config(['settings.credits_auto_use'=>false]);
$creditBefore = $creditBalance();
[$op, $d, $b, $defaultBill] = $makeRenewal('renew-credit-default.com', false, 1, false, static function (): void {
    config(['settings'=>collect(config('settings'))->except('credits_auto_use')->all()]);
    expect(!config()->has('settings.credits_auto_use'), 'Global credit setting is absent');
});
same('paid', $defaultBill->status, 'Unset global setting uses native default-on');
same(1, $defaultBill->transactions()->count(), 'Default-on creates one native credit settlement');
same(1, (int) $defaultBill->transactions()->sole()->is_credit_transaction, 'Default-on settlement is credit');
same($creditBefore - PricingRule::minor($defaultBill->total), $creditBalance(), 'Default-on deducts exactly the invoice amount');
same(false, $product->server->settings()->where('key','credits_auto_use')->sole()->value, 'Stale server row is retained unchanged');
config(['settings.credits_auto_use'=>false]); $creditBefore = $creditBalance();
[$op, $d, $b, $scheduledBill] = $makeRenewal('renew-credit-scheduled.com', false, 1, false, static function ($d) use ($billing, $product): void {
    $product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'], ['value'=>true,'type'=>'boolean']);
    config(['settings.credits_auto_use'=>true]);
    same('paid', $billing->invoice($d->id)->status, 'Scheduled renewal follows global credit opt-in');
});
same(1, (int) $scheduledBill->transactions()->sole()->is_credit_transaction, 'Scheduled renewal uses one native credit payment');
same($creditBefore - PricingRule::minor($scheduledBill->total), $creditBalance(), 'Scheduled billing deducts credit once');
foreach (['insufficient' => -1, 'exact' => 0] as $case => $delta) {
    config(['settings.credits_auto_use' => false]); // Acquisition stays outside the renewal credit scenario.
    $balanceBefore = null;
    [$op, $d, $b, $bill] = $makeRenewal('renew-credit-'.$case.'.com', false, 1, false,
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
    [$op, $d, $b, $bill] = $makeRenewal('renew-credit-'.$case.'.com', false, 1, false, static function () use ($user, $remaining, &$prepaidRemaining): void {
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
echo "Unpaid replacement, staff complimentary authority, fixed terms and caps passed\n"; $nativeSuccessful=true;
