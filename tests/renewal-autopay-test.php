<?php
// Synthetic saved-method collection; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/reconciliation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Credit, Gateway, Invoice, Notification, NotificationTemplate, ServiceCancellation};
use Illuminate\Support\Facades\{DB, Log, Mail, Queue};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels, RequestFacts, StaffGrants};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HoldingLocks, HolderPricing, NativeDomainGuard, RenewalAutoPay, RenewalCycles, RenewalFunding, FinancialAdjustments};

class DomainRenewalGateway
{
    public static array $calls = [];
    public static $during = null;
    public static string $mode = 'paid';
    public function __construct(array $settings) {}
    public function charge(Invoice $invoice, $amount, BillingAgreement $agreement): bool
    {
        same(0, DB::transactionLevel(), 'Gateway is outside all financial locks');
        $op = Operation::where('invoice_id', $invoice->id)->sole();
        same('uncertain', $op->funding['auto_pay']['status'], 'Attempt committed before gateway call');
        same(null, $op->funding['auto_pay_result'] ?? null, 'No outcome invented before charge');
        self::$calls[] = [$invoice->id, \Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$amount)), $agreement->id];
        if (self::$during) { $hook = self::$during; self::$during = null; $hook($op); }
        if (self::$mode === 'throws') { throw new RuntimeException('Synthetic lost response; private gateway details'); }
        if (self::$mode === 'false') { return false; }
        if (self::$mode === 'paid') { ExtensionHelper::addPayment($invoice, null, $amount); }
        return true;
    }
}
class_alias(DomainRenewalGateway::class, 'Paymenter\\Extensions\\Gateways\\RenewalDouble\\RenewalDouble');
Queue::fake(); Mail::fake();
$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot();
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);$billing = new DomainBilling($bindings, $guard, $clock);
$gateway = Gateway::create(['name'=>'Synthetic saved method', 'extension'=>'RenewalDouble', 'type'=>'gateway', 'enabled'=>true]);
$agreement = BillingAgreement::create(['user_id'=>$user->id, 'gateway_id'=>$gateway->id, 'name'=>'Synthetic card', 'external_reference'=>'synthetic-only']);
$server = $product->server;
foreach (['domains_billing_enabled', 'domains_manual_renewals_enabled'] as $key) {
    $server->settings()->updateOrCreate(['key'=>$key], ['value'=>true, 'type'=>'boolean']);
}
// A real completed registration supplies each holder anchor; all registrar reads stay synthetic.
$holder = static function (string $suffix) use ($prepare, $reconcile, $clock, $agreement) {
    [$original] = $prepare(placeholderComFixtureName('auto-'.$suffix.'-placeholder.com'));
    same('succeeded', $reconcile->run($original->id)->state, 'Registration anchor confirmed');
    $d = $original->domain()->firstOrFail();
    $d->service->update(['billing_agreement_id'=>$agreement->id]);
    $clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z');
    return $d;
};
$operation = static fn (Invoice $i) => Operation::where('invoice_id', $i->id)->sole();
$paidProof = static function (Operation $op): array {
    return DB::transaction(fn () => RenewalFunding::requireCurrent($op->fresh(), HoldingLocks::take($op->domain()->firstOrFail())));
};
$credit = Credit::create(['user_id'=>$user->id, 'currency_code'=>'USD', 'amount'=>'0.00']);
$d = $holder('once');
DomainRenewalGateway::$during = static function ($op) use ($billing): void {
    same($op->invoice_id, $billing->invoice($op->domain_id)->id, 'Concurrent-style re-fetch while outcome uncertain reuses invoice');
};
$i = $billing->invoice($d->id); $op = $operation($i);
same(1, count(DomainRenewalGateway::$calls), 'Issued scheduled invoice charges once');
same('paid', $i->fresh()->status, 'Successful native payment marks renewal invoice paid');
same('submitted', $op->funding['auto_pay_result']['status'], 'Gateway acceptance is only submission');
same('awaiting_payment', $op->state, 'Payment alone does not dispatch registrar renewal');
same('paid_invoice', $paidProof($op)['basis'], 'Auto Pay uses existing paid-invoice funding path');
same($i->id, $billing->invoice($d->id)->id, 'Scheduler rerun returns existing invoice');
same($i->id, $billing->invoice($d->id, $user)->id, 'Owner existing path returns same invoice');
same(1, count(DomainRenewalGateway::$calls), 'Neither existing path charges again');
\Illuminate\Support\Facades\Auth::setUser(staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]));
$grants = StaffGrants::current();
same(['Auto Pay: submitted', 'Invoice #'.$op->invoice_id, 'Auto Pay: submitted', 'paid'], [RequestFacts::for($op, $grants)['auto_pay'], RequestFacts::for($op, $grants)['invoice'], RequestFacts::for($op, $grants)['invoice_sub'], $i->fresh()->status], 'Staff facts expose charge as the Invoice sub-line');
commerceReject(fn () => $op->update(['funding'=>[]]));
$op->refresh();
commerceReject(fn () => $op->fresh()->update(['funding'=>['auto_pay'=>$op->funding['auto_pay']]]));
$manual = $holder('manual'); $mi = $billing->invoice($manual->id, $user); $mo = $operation($mi);
same('manual_renewal', $mo->funding['auto_pay']['reason'], 'Manual renewal never auto charges');
same($mi->id, $billing->invoice($manual->id)->id, 'Scheduled re-fetch cannot charge a manual invoice');
ExtensionHelper::addPayment($mi, null, $mo->amount);
$manualProof = $paidProof($mo); $autoProof = $paidProof($op);
foreach (['basis','invoice_total_minor','succeeded_net_minor','currency'] as $key) { same($manualProof[$key], $autoProof[$key], 'Manual and automatic payment have identical funding semantics: '.$key); }
same(1, count(DomainRenewalGateway::$calls), 'Manual invoice paid interactively');
\Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireUnsent($mo->fresh(), $manual);
\Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireUnsent($op->fresh(), $d);

$full = $holder('credit'); $credit->update(['amount'=>'100.00']); $fi = $billing->invoice($full->id);
same('paid', $fi->fresh()->status, 'Full account credit settles invoice');
same('invoice_settled', $operation($fi)->funding['auto_pay']['reason'], 'Credit settlement skips Auto Pay');
same(1, count(DomainRenewalGateway::$calls), 'Full credit makes no gateway call');
$credit->update(['amount'=>'0.00']); $partial = $holder('small-credit'); $credit->update(['amount'=>'2.00']); $pi = $billing->invoice($partial->id);
same(200, \Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$credit->fresh()->amount), 'Insufficient account credit is left untouched as in native cron');
same($operation($pi)->amount, DomainRenewalGateway::$calls[1][1], 'Unapplied partial credit charges full remaining balance');
$credit->update(['amount'=>'0.00']);

// Interleave native financial/preference changes between invoice commit and charge authority refresh.
$beforeCharge = null;
Operation::updated(static function (Operation $row) use (&$beforeCharge): void {
    if ($beforeCharge && $row->wasChanged('funding') && isset($row->funding['auto_pay']) && !isset($row->funding['auto_pay_result'])) {
        $hook = $beforeCharge; $beforeCharge = null;
        DB::afterCommit(static fn () => $hook($row));
    }
});
$partial = $holder('remainder');
$beforeCharge = static fn ($op) => ExtensionHelper::addPayment($op->invoice_id, null, '2.00', isCreditTransaction:true);
$ri = $billing->invoice($partial->id);
same('8.40', DomainRenewalGateway::$calls[2][1], 'Native charge uses current invoice remainder after partial credit payment');
same('paid', $ri->fresh()->status, 'Remainder payment fully funds invoice');
same('paid_invoice', $paidProof($operation($ri))['basis'], 'Partial credit plus charge uses unchanged funding path');

// Native failure notification is exercised with its real helper and an enabled synthetic app template.
NotificationTemplate::updateOrCreate(['key'=>'invoice_payment_failed'], ['enabled'=>true,
    'subject'=>'Synthetic failure', 'body'=>'Synthetic failure', 'in_app_title'=>'Synthetic payment failed',
    'in_app_body'=>'Pay invoice manually', 'mail_enabled'=>'never', 'in_app_enabled'=>'force']);
$warnings = [];
Log::listen(static function ($event) use (&$warnings): void { if ($event->level === 'warning') { $warnings[] = [$event->message, $event->context]; } });
$cycles = new RenewalCycles($bindings, $clock);
$failed = $holder('failure'); DomainRenewalGateway::$mode = 'throws'; $count = count(DomainRenewalGateway::$calls);
$notifications = Notification::count(); $failInvoice = $billing->invoice($failed->id); $failOp = $operation($failInvoice);
same($count+1, count(DomainRenewalGateway::$calls), 'Throwing charge attempted once');
same('pending', $failInvoice->fresh()->status, 'Failed charge leaves invoice pending');
same(['status'=>'unknown'], array_diff_key($failOp->funding['auto_pay_result'], ['at'=>true]), 'Lost response remains unknown');
same($notifications, Notification::count(), 'Unknown collection sends no payment-failed notification');
same([['Domains renewal Auto Pay outcome unknown; staff review required.', []]], $warnings, 'Warning contains no customer data or exception details');
same(['Auto Pay: result missing', 'pending'], [RequestFacts::for($failOp, $grants)['auto_pay'], $failInvoice->fresh()->status], 'Unknown collection visible to staff');
commerceReject(fn () => $cycles->retireUnpaid($failOp->id));
commerceReject(fn () => $cycles->retireUnpaid($failOp->id));
same('pending', $failInvoice->fresh()->status, 'Unknown charge cannot retire unpaid debt');
ExtensionHelper::addPayment($failInvoice, null, $failOp->amount, transactionId:'late-unknown');
same('paid_invoice', $paidProof($failOp)['basis'], 'Late lost-response settlement funds the original invoice');
same('unknown', $failOp->fresh()->funding['auto_pay_result']['status'], 'Late settlement preserves synchronous result');
commerceReject(fn () => $cycles->retireUnpaid($failOp->id));
$billing->invoice($failed->id); same($count+1, count(DomainRenewalGateway::$calls), 'Failed charge never automatically retries');
expect(!str_contains(serialize($failOp->funding), 'private gateway'), 'No exception text retained');

// Crash after accepted charge but before result persistence leaves uncertainty, never a retry.
$uncertain = $holder('uncertain'); DomainRenewalGateway::$mode = 'pending'; $rejectResult = true;
Operation::updating(static function (Operation $row) use (&$rejectResult): void {
    if ($rejectResult && isset($row->funding['auto_pay_result']) && ($row->funding['auto_pay_result']['status']??null)==='submitted') {
        $rejectResult = false; throw new RuntimeException('Synthetic persistence interruption');
    }
});
try { $billing->invoice($uncertain->id); throw new LogicException('Expected persistence failure'); }
catch (RuntimeException $e) { same('Synthetic persistence interruption', $e->getMessage(), 'Failure occurred after gateway return'); }
$uncertainOp = $uncertain->operations()->where('action','renew')->sole();
same('uncertain', $uncertainOp->funding['auto_pay']['status'], 'Precharge marker survives interrupted result write');
same(null, $uncertainOp->funding['auto_pay_result']??null, 'Uncertain outcome is not manufactured');
$count = count(DomainRenewalGateway::$calls); $billing->invoice($uncertain->id);
same($count, count(DomainRenewalGateway::$calls), 'Uncertain marker blocks scheduler retry');
same('Auto Pay: result missing', Labels::autoPay($uncertainOp->funding['auto_pay']), 'Staff sees the uncertain charge as Missing');
expect(!RenewalCycles::virgin($uncertainOp), 'Unresolved collection is not unattempted');
expect(!FinancialAdjustments::unattemptedFunding($uncertainOp), 'Unresolved funding blocks unpaid retirement');
commerceReject(fn () => $cycles->retireUnpaid($uncertainOp->id));
commerceReject(fn () => $cycles->retireUnpaid($uncertainOp->id));
same('pending', Invoice::findOrFail($uncertainOp->invoice_id)->status, 'Interrupted result keeps original debt active');
// Retained cancelled debt from the prior release still cannot create another collection.
$old = clone $uncertainOp;
$old->state = 'rejected'; $old->safe_code = 'renewal_cancelled_unpaid';
$old->attempt = ['seal'=>'absent', 'send_state'=>'definitely_unsent', 'disposition'=>'rejected', 'rejection_basis'=>'definitely_unsent',
    'terminal'=>['operation_key'=>$old->operation_key, 'generation'=>$old->authority_generation, 'invoice_id'=>$old->invoice_id, 'transactions'=>[]]];
$old->completed_at = $clock->now();
$l = DB::transaction(fn () => HoldingLocks::take($uncertain));
$l['operations'] = $l['operations']->map(fn ($row) => $row->id === $old->id ? $old : $row);
$l['invoices']->firstWhere('id', $old->invoice_id)->status = 'cancelled';
commerceReject(fn () => RenewalCycles::next($l));

$declined = $holder('declined'); DomainRenewalGateway::$mode = 'false';
$count = count(DomainRenewalGateway::$calls); $notifications = Notification::count();
$di = $billing->invoice($declined->id); $declinedOp = $operation($di);
same(['status'=>'declined','notification'=>'requested'], array_diff_key($declinedOp->funding['auto_pay_result'], ['at'=>true]), 'False return is a definite decline');
same($notifications+1, Notification::count(), 'Decline requests the native payment-failed notification');
same('pending', $di->fresh()->status, 'Declined invoice stays pending');
$billing->invoice($declined->id); $billing->invoice($declined->id);
same($count+1, count(DomainRenewalGateway::$calls), 'Declined method never retries automatically');
expect(RenewalCycles::virgin($declinedOp), 'Definite decline permits unpaid retirement');
$cycles->retireUnpaid($declinedOp->id);
$replacement = $billing->invoice($declined->id, $user);
expect($replacement->id !== $di->id, 'Definite decline allows a distinct replacement debt');
same($replacement->id, $billing->invoice($declined->id)->id, 'Replacement reused without collection');
same($count+1, count(DomainRenewalGateway::$calls), 'Interactive replacement makes no second charge');

foreach (['failed', 'charged', 'unknown', 'submitted', 'unexpected'] as $status) {
    $legacy = clone $uncertainOp; $legacy->funding = [...$legacy->funding, 'auto_pay_result'=>['status'=>$status]];
    expect(!RenewalCycles::virgin($legacy), 'Unresolved or legacy result refuses retirement: '.$status);
}

$submitted = $holder('submitted'); DomainRenewalGateway::$mode = 'pending';
$si = $billing->invoice($submitted->id); $submittedOp = $operation($si); $result = $submittedOp->funding['auto_pay_result'];
$count = count(DomainRenewalGateway::$calls);
same(['Auto Pay: submitted','Auto Pay: submitted'],[RequestFacts::for($submittedOp,$grants)['auto_pay'],RequestFacts::for($submittedOp,$grants)['invoice_sub']],'Submitted Auto Pay labels agree');
same('Auto Pay: charged',Labels::autoPay(['status'=>'charged']),'Legacy charged label remains supported');
staffPanel();$autoPayStaff=staffUser(['admin.domains.view']);
Livewire\Livewire::actingAs($autoPayStaff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$submittedOp->id])->assertSee('Auto Pay: submitted')->assertSee('Invoice #'.$si->id);

same('submitted', $result['status'], 'Accepted payment can still be pending');
same(['Auto Pay: submitted', 'pending'], [RequestFacts::for($submittedOp, $grants)['auto_pay'], $si->fresh()->status], 'Submission is not shown as paid');
commerceReject(fn () => $cycles->retireUnpaid($submittedOp->id));
ExtensionHelper::addProcessingPayment($si, null, $submittedOp->amount, transactionId:'asynchronous-charge');
same(['Auto Pay: submitted', 'pending'], [RequestFacts::for($submittedOp, $grants)['auto_pay'], $si->fresh()->status], 'Processing remains pending');
ExtensionHelper::addFailedPayment($si, null, $submittedOp->amount, transactionId:'asynchronous-charge');
same(['Auto Pay: submitted', 'pending'], [RequestFacts::for($submittedOp, $grants)['auto_pay'], $si->fresh()->status], 'Later failure is never labelled charged');
commerceReject(fn () => $cycles->retireUnpaid($submittedOp->id));
$si->refresh()->update(['status'=>'cancelled']);
same(['Auto Pay: submitted', 'cancelled'], [RequestFacts::for($submittedOp, $grants)['auto_pay'], $si->fresh()->status], 'Cancelled invoice state is current');
commerceReject(fn () => $billing->invoice($submitted->id, $user));
$si->refresh()->update(['status'=>'pending']);
ExtensionHelper::addPayment($si, null, $submittedOp->amount, transactionId:'asynchronous-charge');
ExtensionHelper::addPayment($si, null, $submittedOp->amount, transactionId:'asynchronous-charge');
same(['Auto Pay: submitted', 'paid'], [RequestFacts::for($submittedOp, $grants)['auto_pay'], $si->fresh()->status], 'Late success updates staff invoice state');
same($result, $submittedOp->fresh()->funding['auto_pay_result'], 'Submission result remains write-once through settlement');
same('paid_invoice', $paidProof($submittedOp)['basis'], 'Asynchronous payment reaches native funding');
$billing->invoice($submitted->id); $billing->invoice($submitted->id);
same($count, count(DomainRenewalGateway::$calls), 'Repeated settlement and scheduler delivery never recollect');

DomainRenewalGateway::$mode = 'paid';
$second = BillingAgreement::create(['user_id'=>$user->id,'gateway_id'=>$gateway->id,'name'=>'Second card','external_reference'=>'second']);
foreach (['no_agreement','foreign_agreement','gateway_disabled','gateway_missing','agreement_changed','service_inactive','renewal_off','owner_auto_renew_off','currency','invoice_settled'] as $reason) {
    $held = $holder(str_replace('_','-',$reason)); $count = count(DomainRenewalGateway::$calls);
    $foreign = null;
    if ($reason==='no_agreement') { $held->service->update(['billing_agreement_id'=>null]); }
    elseif ($reason==='foreign_agreement') {
        $foreign = BillingAgreement::create(['user_id'=>commerceUser()->id,'gateway_id'=>$gateway->id,'name'=>'Other owner','external_reference'=>'other']);
        $held->service->update(['billing_agreement_id'=>$foreign->id]);
    } elseif ($reason==='gateway_disabled') { $gateway->update(['enabled'=>false]); }
    elseif ($reason==='gateway_missing') { $gateway->delete(); }
    else {
        $beforeCharge = static function ($op) use ($reason, $held, $second): void {
            match ($reason) {
                'agreement_changed' => $held->service->update(['billing_agreement_id'=>$second->id]),
                'service_inactive' => DB::table('services')->where('id',$held->service_id)->update(['status'=>'cancelled']),
                'renewal_off' => $held->update(['renewal_policy'=>[...$held->renewal_policy,'renewals_stopped'=>true]]),
                'owner_auto_renew_off' => $held->update(['renewal_policy'=>[...$held->renewal_policy,'owner_auto_renew'=>false]]),
                'currency' => DB::table('invoices')->where('id',$op->invoice_id)->update(['currency_code'=>'EUR']),
                'invoice_settled' => ExtensionHelper::addPayment($op->invoice_id,null,$op->amount),
            };
        };
    }
    $invoice = $billing->invoice($held->id); $row = $operation($invoice);
    $decision = $row->funding['auto_pay_result']??$row->funding['auto_pay'];
    same($reason, $decision['reason']??null, 'Saved-method refusal: '.$reason);
    same('skipped', $decision['status'], 'Refusal retained');
    same($count, count(DomainRenewalGateway::$calls), 'Refusal makes no charge: '.$reason);
    $gateway->restore(); $gateway->update(['enabled'=>true]); $agreement->restore();
}
$cancelled = $holder('cancellation'); $count = count(DomainRenewalGateway::$calls);
$beforeCharge = static fn ($op) => ServiceCancellation::create(['service_id'=>$op->service_id,'type'=>'end_of_period','reason'=>'Synthetic cancellation']);
$ci = $billing->invoice($cancelled->id);
same('renewal_off', $operation($ci)->funding['auto_pay_result']['reason'], 'Pending native cancellation blocks postcommit collection');
same($count, count(DomainRenewalGateway::$calls), 'Cancellation never charges');
$off = $holder('off'); $off->update(['renewal_policy'=>[...$off->renewal_policy,'renewals_stopped'=>true]]);
same(null, $billing->invoice($off->id), 'Renewal Off creates no invoice or charge');
same($count, count(DomainRenewalGateway::$calls), 'Renewal Off makes no gateway call');
echo "Scheduled renewal Auto Pay: committed once-only attempts, credits, failures, skips, staff facts and native funding passed.\n";
$nativeSuccessful = true;
