<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. A local deferral keeps an overdue routine refresh's wait, so it is not starved.
declare(strict_types=1);
require __DIR__.'/Support/demo-c-c1-observation.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords;
use Paymenter\Extensions\Servers\Domains\Services\{DomainObservation,HoldingObservationWork,HoldingReadRetry,ObservationProjection};

$wired=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$exchange));
$worker=new HoldingObservationWork($wired,$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
$clock->instant=$clock->now()->modify('+1 day +2 hours');$start=$clock->now()->getTimestamp();
expect(ObservationProjection::recordStatus($d->fresh(),$clock->now())['stale'],'Fixture: the routine holding has stale records');
HoldingObservationWork::queueInitial($d->fresh(),$clock->now());
$overdue=$start-7200;
HoldingObservationWork::current($d->fresh())->update(['safe_code'=>'refreshed','tries'=>0,'due_at'=>$overdue]);
$routine=(int)HoldingObservationWork::current($d->fresh())->value('id');
// A second active holding on the same account has a transient retry that comes due while the routine read waits.
$other=Domain::withoutEvents(static fn()=>Domain::create([...$d->only(['server_id','registrar_key','account_fingerprint','authority_generation','user_id']),
    'domain'=>'deferred-retry-placeholder.com','sld'=>'deferred-retry-placeholder','tld'=>'com','service_id'=>$d->service_id+1000,'status'=>'active','observation'=>[]]));
HoldingObservationWork::queueInitial($other,$clock->now());
$retryDue=$start+30;
HoldingObservationWork::current($other)->update(['safe_code'=>'read_failed','tries'=>1,'due_at'=>$retryDue]);
$retry=(int)HoldingObservationWork::current($other)->value('id');
$row=static fn(int $id)=>DB::table('domains_observation_work')->where('id',$id)->sole();
// An interactive read holds the account's only Enom slot, so the routine read's first attempt is deferred locally.
$binding=$driver->capabilities()->binding;$slot=$budget->acquire($binding,RequestOrigin::Interactive);
$state->calls=[];
same('read_deferred',$worker->turn($queue,$guard),'The busy account slot defers the routine read');
same([],$state->calls,'The deferred attempt sends nothing to the registrar');
$held=$row($routine);
same([0,'refreshed',null,$overdue,$start+60],[(int)$held->tries,$held->safe_code,$held->claim,(int)$held->due_at,(int)$held->lease_until],
    'Deferral spends no try and keeps the overdue due time, retrying after a minute');
$clock->instant=$clock->now()->setTimestamp($start+20);
same('idle',$worker->turn($queue,$guard),'The routine read waits out its retry delay');
same($overdue,(int)$row($routine)->due_at,'Waiting keeps the overdue due time');
// The retry delay is not a running read: the status stays overdue and staff can still start Sync now.
$status=ObservationProjection::recordStatus($d->fresh(),$clock->now());
same([true,false],[HoldingReadRetry::status($d->fresh(),$clock->now())['read_overdue'],$status['read_checking']],'The retry delay still reads as overdue, not checking');
expect(!SyncDomainRecords::pending($d->fresh()),'Sync now is enabled during the retry delay');
$manual=HoldingObservationWork::claimManual($d->fresh(),$clock->now(),300);
same($routine,$manual[0]??null,'Sync now takes the read lease during the retry delay');
HoldingObservationWork::releaseManual(...$manual);
same($overdue,(int)$row($routine)->due_at,'An unfinished Sync now keeps the overdue due time');
// Capacity returns once the retry delay ends; the transient retry is due by then and competes for the turn.
$budget->release($binding,RequestOrigin::Interactive,$slot);
$clock->instant=$clock->now()->setTimestamp($start+61);
same('refreshed',$worker->turn($queue,$guard),'The routine read, still over an hour overdue, wins the next turn');
$after=$row($retry);
same([1,$retryDue,null],[(int)$after->tries,(int)$after->due_at,$after->claim],'The competing retry waits for the next turn');
expect((int)$row($routine)->due_at>$clock->now()->getTimestamp(),'The finished routine read is scheduled again later');
$nativeSuccessful=true;echo "Deferral routine wait passed.\n";
