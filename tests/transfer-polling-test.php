<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native funding/authority locks and the real read adapter are needed to prove automatic completion without another purchase.
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances,OperationReconciliation};
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
$op=$prepare('automatic-wait');
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,8*86400,'namesilo_transfer_v1'));
$observe->status='Pending at Registry'; $sent=$state->calls;
for($i=0;$i<6;$i++){
    $before=count($observe->calls); $r=$reconcile->run($op->id);
    expect(count($observe->calls)>$before,'Expected pending transfer keeps checking beyond five polls');
    same(false,$r->policy['reconciliation']['staff_alert'],'Normal pending needs no staff action');
    $clock->instant=$clock->now()->modify('+12 hours');
}
$observe->status='Transfer Completed';
same('succeeded',$reconcile->run($op->id)->state,'Transfer completes automatically after the old poll stop');
same($sent,$state->calls,'Waiting and completion never resend the transfer');
// Restart/manual calls cannot defeat pacing; failed reservations retain their place in the schedule.
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Services\{TransferInputs,RuntimeSchema,RuntimeDispatch,RuntimePorts,RuntimeWork,RuntimeComposition,WriteFeatures,RenewalAcceptance,TransferPrecheck,Rdap,TransferPolling,AccountReadBudget,ReadDeferred,TransferReadBudget};
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{RequestFacts,OperationActions};
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
RuntimeSchema::install(); Queue::fake();
DB::table('domains_read_budgets')->delete();
$op=$prepare('held-automatic');HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,8*86400,'namesilo_transfer_v1'));
$observe->status='Domain is Locked';$observe->fault='reject';
$r=$reconcile->run($op->id);same('held',$r->state,'Needs-action remains visible without resending');
same(true,$r->policy['reconciliation']['staff_alert'],'Old registrar action remains visible');
$calls=count($observe->calls);$reconcile->run($op->id);same($calls,count($observe->calls),'Repeated manual check obeys durable backoff');
$clock->instant=new DateTimeImmutable('@'.$r->policy['reconciliation']['next_at']);
$offline=static fn()=>throw new LogicException('No additional send allowed');
$composition=RuntimeComposition::make($binding,$readBindings,$clock,new WriteFeatures(transfer:true),$accepted,new RenewalAcceptance($binding),
    new TransferPrecheck(new Rdap($offline)),new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration($binding,$profiles,$offline),$protocol,$offline,
    new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management($binding,$offline),profiles:$profiles);
$ports=new RuntimePorts;$ports->register($binding->fingerprint,fn()=>$composition);
app()->instance(RuntimeDispatch::class,new RuntimeDispatch($clock));app()->instance(TransferInputs::class,$inputs);
$runtime=new RuntimeWork($clock,$ports);
$bindings->setProcessing($binding->serverId,false);$runtime->tickOperations();
expect(Queue::pushed(RunOperation::class)->contains(fn($job)=>$job->operationId===$op->id),'Held needs-action selected automatically while writes paused');
$observe->status='Transfer Completed';$observe->fault='';$runtime->operation($op->id);
same('succeeded',$op->fresh()->state,'Runtime completes held transfer while writes remain paused');
$bindings->setProcessing($binding->serverId,true,syntheticActivation());
// No customer or staff event extends the original window, and a deadline is never negative proof.
DB::table('domains_read_budgets')->delete();
$op=$prepare('deadline');HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,8*86400,'namesilo_transfer_v1'));
$observe->status='Pending at Registry';$reads=[];
while($clock->now()->getTimestamp()<TransferPolling::deadline($op)){
    $reads[]=$clock->now()->getTimestamp();$r=$reconcile->run($op->id);
    $r=$op->fresh();$calls=count($observe->calls);$reconcile->run($op->id);same($calls,count($observe->calls),'Early duplicate does no I/O');
    $clock->instant=new DateTimeImmutable('@'.$r->policy['reconciliation']['next_at']);
}
$calls=count($observe->calls);$r=$reconcile->run($op->id);expect(count($observe->calls)>$calls,'Expiry performs a final bounded check');same('transfer_deadline_passed',$r->policy['reconciliation']['reason'],'Original seven-day deadline surfaces one exception');
same(true,$r->policy['reconciliation']['staff_alert'],'Deadline is visible');same(null,$r->completed_at,'Timeout never invents finality');
same(null,$refunds->creditRejected($r->id),'Deadline never grants a refund');same(null,$r->attempt['transfer_terminal']??null,'Deadline is not negative proof');
$calls=count($observe->calls);$clock->instant=$clock->now()->modify('+1 day');$reconcile->run($r->id);same($calls,count($observe->calls),'After deadline no automatic registrar reads');
foreach($reads as $start){expect(count(array_filter($reads,fn($at)=>$at>=$start&&$at<$start+86400))<=4,'At most four automatic passes per rolling day');}
same(16,count($reads),'Seven-day watch has sixteen automatic passes');
$notice=RequestFacts::notice($r,['unreadable'=>false]);expect(!empty($notice['body'])&&($notice['action']??null)==='resume_reads','Deadline gives staff a resolution route');
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,9*86400,'namesilo_transfer_v1'));
$reconcile->resume($r->id,'provider_facts_updated');$observe->status='Transfer Completed';
same('succeeded',$reconcile->run($r->id)->state,'Explicit reviewed check can confirm a late success without extending the window');
// Our shared account guard defers ordinary waits, then retries without staff readmission.
DB::table('domains_read_budgets')->delete();$op=$prepare('guard-recovery');$observe->status='Pending at Registry';
$budget=new AccountReadBudget($clock);
for($i=0;$i<25;$i++){$token=$budget->acquire($binding,RequestOrigin::Background);$budget->release($binding,RequestOrigin::Background,$token);}
$r=$reconcile->run($op->id);same([],$observe->calls,'Poll yields at our 25-read watch allowance');
same(false,$r->policy['reconciliation']['staff_alert'],'Our guard retries before asking staff');
same(null,RequestFacts::notice($r,['unreadable'=>false])['action']??null,'Our guard asks for no staff input');
$token=$budget->acquire($binding,RequestOrigin::Background);$budget->release($binding,RequestOrigin::Background,$token);
$token=$budget->acquire($binding,RequestOrigin::Interactive);$budget->release($binding,RequestOrigin::Interactive,$token);
$clock->instant=new DateTimeImmutable('@'.$r->policy['reconciliation']['next_at']);
$r=$reconcile->run($op->id);expect(count($observe->calls)>0,'Guard refusal recovers automatically at next paced check');
same(false,$r->policy['reconciliation']['staff_alert'],'Guard recovery returns to routine waiting');
$clock->instant=new DateTimeImmutable('@'.$r->policy['reconciliation']['next_at']);$observe->status='unknown';
$r=$reconcile->run($op->id);same(true,$r->policy['reconciliation']['staff_alert'],'Unexpected result escalates');
same('transfer_status_unavailable',$r->policy['reconciliation']['reason'],'Unexpected result has one clear cause');
// Preserve the unexpected-result stop at dispatch too, without losing the final check.
$p=$r->policy;$p['reconciliation']['polls']=OperationReconciliation::POLL_LIMIT;$p['reconciliation']['next_at']=$clock->now()->getTimestamp();$r->update(['policy'=>$p]);
Queue::fake();app(RuntimeDispatch::class)->enqueue($r->id);
expect(!Queue::pushed(RunOperation::class)->contains(fn($job)=>$job->operationId===$r->id),'Stopped unexpected results do not queue every minute');
expect(TransferPolling::due($r,TransferPolling::deadline($r)),'Unexpected poll stop still permits the single final check');
// A tighter configured background guard remains tighter than the transfer allowance.
DB::table('domains_read_budgets')->delete();$narrow=new TransferReadBudget($clock,new AccountReadBudget($clock,1));
$token=$narrow->acquire($binding,RequestOrigin::Background);$narrow->release($binding,RequestOrigin::Background,$token);
try{$narrow->acquire($binding,RequestOrigin::Background);expect(false,'Configured lower guard must win');}catch(ReadDeferred){}
// A transfer that missed all checks still gets the same deadline and recovery route.
DB::table('domains_read_budgets')->delete();$op=$prepare('missed-window');$clock->instant=$now->modify('+7 days');
DB::table('domains_runtime_operations')->updateOrInsert(['operation_id'=>$op->id],['attempts'=>20,'due_at'=>$clock->now()->getTimestamp()+21600]);
Queue::fake();app(RuntimeDispatch::class)->enqueue($op->id);
expect(Queue::pushed(RunOperation::class)->contains(fn($job)=>$job->operationId===$op->id),'Deadline notice does not wait behind an old runtime backoff');
$r=$reconcile->run($op->id);same(true,OperationActions::readLimit($r),'Deadline has a recovery action even before five polls');
same(false,TransferPolling::due($r,$clock->now()->getTimestamp()),'Surfaced deadline stops automatic no-op jobs');
echo "Automatic transfer pacing, held runtime dispatch, deadline, guard recovery and late read-only completion passed.\n"; $nativeSuccessful=true;
