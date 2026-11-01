<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native final-check settlement, no registrar calls.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
use Illuminate\Support\Facades\Queue;
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances,TransferPolling,AccountReadBudget,RuntimeSchema,RuntimeDispatch};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
RuntimeSchema::install();
$op=$prepare('finished-before-deadline');$start=$op->started_at;$sent=$state->calls;
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,8*86400,'namesilo_transfer_v1'));
$observe->status='Pending at Registry';$reconcile->run($op->id);
$clock->instant=new DateTimeImmutable('@'.(TransferPolling::deadline($op)-3600));$observe->status='Transfer Completed';
$clock->instant=$clock->now()->modify('+1 hour');
same('succeeded',$reconcile->run($op->id)->state,'A transfer finished before expiry completes on its automatic final check');
same($sent,$state->calls,'Final check never sends another transfer');same($start->getTimestamp(),$op->fresh()->started_at->getTimestamp(),'Original deadline unchanged');
$op=$prepare('final-capacity');$start=$op->started_at;
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,8*86400,'namesilo_transfer_v1'));
$clock->instant=new DateTimeImmutable('@'.TransferPolling::deadline($op));$budget=new AccountReadBudget($clock);
for($i=0;$i<25;$i++){$token=$budget->acquire($binding,RequestOrigin::Background);$budget->release($binding,RequestOrigin::Background,$token);}
$r=$reconcile->run($op->id);same([],$observe->calls,'Final check yields before its first wire');
same(true,$r->policy['reconciliation']['staff_alert'],'Unresolved expiry alerts staff while capacity recovery stays automatic');
$clock->instant=$clock->now()->modify('+61 seconds');Queue::fake();(new RuntimeDispatch($clock))->enqueue($op->id);
expect(Queue::pushed(RunOperation::class)->contains(fn($job)=>$job->operationId===$op->id),'Final check retries automatically as capacity returns');
same('succeeded',$reconcile->run($op->id)->state,'Deferred final check completes without staff input');
same($start->getTimestamp(),$op->fresh()->started_at->getTimestamp(),'Deferred final check does not extend the watch');
$calls=count($observe->calls);$reconcile->run($op->id);same($calls,count($observe->calls),'Final success cannot be checked twice');
// A partly read final check must retry, but cannot turn into a minute read loop.
$op=$prepare('partial-final-capacity');HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,8*86400,'namesilo_transfer_v1'));
$clock->instant=new DateTimeImmutable('@'.TransferPolling::deadline($op));
for($i=0;$i<22;$i++){$token=$budget->acquire($binding,RequestOrigin::Background);$budget->release($binding,RequestOrigin::Background,$token);}
$r=$reconcile->run($op->id);same(3,count($observe->calls),'Partial final check stops at our 25-read watch allowance');
$next=$r->policy['reconciliation']['next_at'];expect($next>=$clock->now()->getTimestamp()+7200,'Partial final check keeps wire-attempt backoff');
$clock->instant=$clock->now()->modify('+61 seconds');same(false,TransferPolling::due($r,$clock->now()->getTimestamp()),'Returned minute capacity does not spin partial final reads');
$clock->instant=new DateTimeImmutable('@'.$next);same('succeeded',$reconcile->run($r->id)->state,'Partial final check retries itself to completion');
$op=$prepare('unresolved-final');HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,8*86400,'namesilo_transfer_v1'));
$observe->status='Pending at Registry';$clock->instant=new DateTimeImmutable('@'.TransferPolling::deadline($op));
$r=$reconcile->run($op->id);expect(count($observe->calls)>0,'Unresolved expiry is established by one final read');
same('transfer_deadline_passed',$r->policy['reconciliation']['reason'],'Unresolved final facts give the deadline exception');
same(null,$refunds->creditRejected($r->id),'Time and a pending final read never grant a refund');
$calls=count($observe->calls);$clock->instant=$clock->now()->modify('+1 day');$reconcile->run($r->id);
same($calls,count($observe->calls),'Completed final pass does not start another watch');
// A final capacity wait must not hide a previously observed conflicting charge.
$p=$r->policy;unset($p['reconciliation']['final_check']);$p['reconciliation']['reason']='transfer_charge_unexpected';
$p['reconciliation']['staff_alert']=true;$r->update(['policy'=>$p]);
for($i=0;$i<25;$i++){$token=$budget->acquire($binding,RequestOrigin::Background);$budget->release($binding,RequestOrigin::Background,$token);}
$r=$reconcile->run($r->id);same('transfer_charge_unexpected',$r->policy['reconciliation']['reason'],'Final capacity refusal preserves an existing anomaly');
same(true,$r->policy['reconciliation']['staff_alert'],'Capacity refusal cannot clear an anomaly alert');
echo "Automatic final completion, deferred final admission and unresolved expiry passed.\n";$nativeSuccessful=true;
