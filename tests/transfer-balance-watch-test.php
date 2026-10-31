<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real native funding, automatic readmission, seal and reconciliation must compose across a long unsent hold.
require_once __DIR__.'/bootstrap.php';
$transferEnvironment=Paymenter\Extensions\Servers\Domains\Data\Environment::Test;
require __DIR__.'/Support/transfer-completion-enom-fixture.php';
use Illuminate\Support\Facades\{Cache,Queue};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,BalanceSchema,EnomTestSendLimitSchema,ExecutionWindow,HistoryAcceptances,ObligationAlerts,OperationClaims,ReminderSchema,RenewalAcceptance,RuntimeComposition,RuntimeDispatch,RuntimePorts,RuntimeSchema,RuntimeWork,TransferInputs,TransferPolling,TransferPrecheck,WriteFeatures};
RuntimeSchema::install();BalanceSchema::install();EnomTestSendLimitSchema::install();ReminderSchema::install();Queue::fake();
app()->instance(AccountBindings::class,$freshBindings);app()->instance(Clock::class,$clock);app()->instance(OperationClaims::class,$claims);app()->instance(TransferInputs::class,$inputs);
$advance=static function(int $seconds)use($clock):void {
    $clock->instant=$clock->now()->modify('+'.$seconds.' seconds');Cache::flush();
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
};
$op=$newTransfer(placeholderComFixtureName('long-balance-wait-placeholder.com'));$makeTransfer();
$offline=static fn()=>throw new LogicException('No unrelated send allowed');
$composition=RuntimeComposition::make($binding,$freshBindings,$clock,new WriteFeatures(transfer:true),$accepted,new RenewalAcceptance($binding),
    new TransferPrecheck($rdap),new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($binding,$offline),$protocol,$offline,
    new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($binding,$offline),profiles:$profiles);
// Existing fixture preflight reads are synthetic; reconciliation uses the real Enom driver/parser.
$composition['reconciliation']=$reconcile;
$ports=new RuntimePorts;$ports->register($binding->fingerprint,fn()=>$composition);
app()->instance(RuntimeDispatch::class,new RuntimeDispatch($clock));$runtime=new RuntimeWork($clock,$ports);
$state->funds='1.00';$runtime->operation($op->id);$held=$op->fresh();
same(['held','registrar_balance_insufficient','absent'],[$held->state,$held->safe_code,$held->attempt['seal']],'Shortfall retains a definitely-unsent transfer');
$claim=[$held->started_at->getTimestamp(),$held->attempt['claim_key'],$held->funding['claim']];
$advance(8*86400);$runtime->operation($op->id);
same(['held',[]],[$op->fresh()->state,$state->calls],'Eight days without registrar funds cannot send');
$state->funds='100.00';$advance(1800);$runtime->operation($op->id);$sent=$op->fresh();
same('sealed',$sent->attempt['seal'],'Restored funds seal the original claim automatically');
same(['TP_CreateOrder'],$state->calls,'Restored funds submit exactly one transfer');
$window=ExecutionWindow::start($sent)->getTimestamp();$deadline=TransferPolling::deadline($sent);
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,9*86400,'enom_transfer_v1'));
$observe->name=$sent->domain->domain;$observe->fault='pending';$observe->charge=$sent->attempt['authorized_cost']['amount'];
$observe->billDate=(new DateTimeImmutable($sent->attempt['dispatch_attempted_at']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('m/d/Y g:i:s A');
// A zero-wire first refusal must use the send window for both deadline and alert freshness.
$budget=new AccountReadBudget($clock);
for($i=0;$i<25;$i++){$token=$budget->acquire($binding,RequestOrigin::Background);$budget->release($binding,RequestOrigin::Background,$token);}
$runtime->operation($op->id);$deferred=$op->fresh();
same([],$observe->calls,'First status check yields before any provider read');
$d=$deferred->domain->fresh();$firstAlerts=ObligationAlerts::retained(['domain'=>$d,'operations'=>$d->operations()->get()],$clock->now()->getTimestamp());
$advance($deferred->policy['reconciliation']['next_at']-$clock->now()->getTimestamp());$runtime->operation($op->id);$pending=$op->fresh();
same('pending',$pending->policy['reconciliation']['transfer_status']??null,'The new transfer reaches Pending');
same('transfer_pending',$pending->policy['reconciliation']['reason'],'An old unsent hold does not exhaust the new pending watch');
same([],$firstAlerts,'A fresh first capacity wait is quiet despite the old claim');
same(false,$pending->policy['reconciliation']['staff_alert'],'Routine pending needs no staff recovery');
same($window+7*86400,$deadline,'The seven-day pending window starts with validated send authority');
$advance($pending->policy['reconciliation']['next_at']-$clock->now()->getTimestamp());$observe->fault='';$runtime->operation($op->id);$done=$op->fresh();
same('succeeded',$done->state,'The recovered transfer completes on its next automatic check');
same('active',$done->domain->service->status,'Automatic completion activates the native service');
same(['TP_CreateOrder'],$state->calls,'Capacity, polling and completion never resend');
same($claim,[$done->started_at->getTimestamp(),$done->attempt['claim_key'],$done->funding['claim']],'The original claim and funding identity stay fixed');
same($deadline,TransferPolling::deadline($done),'Capacity deferral and polling never move the send window');
expect(!isset($done->policy['staff_actions'])&&!isset($done->policy['reconciliation_resumptions']),'No staff action or manual recovery is needed');
echo "Long unsent balance hold, fresh pending window and automatic transfer completion passed.\n";$nativeSuccessful=true;
