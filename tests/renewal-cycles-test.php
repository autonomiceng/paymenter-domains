<?php
// Enom d1392a8 complimentary/cancelled-unpaid cycles; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, RenewalFunding};
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
config(['settings.credits_auto_use'=>true]);
[$op, $d, $b, $offBill] = $makeRenewal('renew-credit-off.com', false, 1, true);
same('pending',$offBill->status,'Server credit auto-use defaults off'); same(0,$offBill->transactions()->count(),'No settlement without opt-in');
same(10000,Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$user->credits()->where('currency_code','USD')->sole()->amount),'Opt-out credit untouched');
$product->server->settings()->updateOrCreate(['key'=>'credits_auto_use'],['value'=>true,'type'=>'boolean']);
config(['settings.credits_auto_use'=>false]);
[$op, $d, $b, $offBill] = $makeRenewal('renew-credit-global-off.com',false);
same('pending',$offBill->status,'Native global credit opt-out respected'); same(0,$offBill->transactions()->count(),'No global opt-out settlement');
same(10000,Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$user->credits()->where('currency_code','USD')->sole()->amount),'Global opt-out credit untouched');
config(['settings.credits_auto_use'=>false]);
[$op, $d, $b, $creditBill] = $makeRenewal('renew-credit.com', false, 1, false, static fn () => config(['settings.credits_auto_use'=>true]));
same('paid', $creditBill->status, 'Opted-in full native credit pays renewal invoice');
same(1, (int) $creditBill->transactions()->sole()->is_credit_transaction, 'Native credit settlement retained');
same('pending', $runRenewal($op, $b)->state, 'Native credit is current paid funding');
echo "Unpaid replacement, staff complimentary authority, fixed terms and caps passed\n"; $nativeSuccessful=true;
