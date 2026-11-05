<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real native work leases, observer and fake wire; no live provider.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema};
ObservationSchema::install();[$d,$driver,$original]=$holding(placeholderComFixtureName('retry-records-placeholder.com'));
$worker=new HoldingObservationWork(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports),$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
$observation->during=static fn()=>throw new DomainException('Registrar read failed.');
same('read_failed',$worker->turn($queue,$guard),'Transient primary failure is retained');
expect(!HoldingObservationWork::stopped($d->fresh()),'First transient failure schedules an automatic retry');
$q=HoldingObservationWork::current($d->fresh());$job=$q->sole();same(60,$job->due_at-$clock->now()->getTimestamp(),'First retry uses bounded backoff');
same('idle',$worker->turn($queue,$guard),'No retry before due time');
$clock->instant=$clock->now()->modify('+60 seconds');same('refreshed',$worker->turn($queue,$guard),'Transient recovery needs no staff action');
expect(!ObservationRecords::held($d->fresh()),'Successful full-family retry clears stale failure hold');
// A family failure is also retried, even though the primary domain facts succeeded.
$q->update(['due_at'=>0]);$clock->instant=$clock->now()->modify('+61 seconds');$state->fault='GetDomainSRVHosts';
for($try=1;$try<=5;$try++){
 same('read_failed',$worker->turn($queue,$guard),'Family failure keeps its safe code');$job=$q->sole();same($try,(int)$job->tries,'Failure count is durable');
 if($try<5){expect(!HoldingObservationWork::stopped($d->fresh()),'Retry remains active before exhaustion');$clock->instant=$clock->now()->setTimestamp((int)$job->due_at);}
}
expect(HoldingObservationWork::stopped($d->fresh()),'Five failures stop with their exact cause');$calls=count($state->calls);$clock->instant=$clock->now()->modify('+2 days');
same('idle',$worker->turn($queue,$guard),'Exhausted current work stays stopped');same($calls,count($state->calls),'Exhaustion does not loop');
// Legacy stopped work has no retry receipt. A repaired, verified account gets one recovery only.
$p=$d->fresh()->observation;unset($p['read_retry']);$d->fresh()->update(['observation'=>$p]);$q->update(['safe_code'=>'configuration_unverified','tries'=>5]);$state->fault='';
same('refreshed',$worker->turn($queue,$guard),'Legacy stop automatically resumes after configuration matches');
$q->update(['safe_code'=>'binding_mismatch','tries'=>1,'due_at'=>0]);$calls=count($state->calls);
same('idle',$worker->turn($queue,$guard),'Permanent binding refusal stays stopped');same($calls,count($state->calls),'Permanent refusal sends no wire');
$nativeSuccessful=true;echo "Bounded automatic read recovery passed\n";
