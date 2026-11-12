<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Discovery keeps an overdue routine refresh's wait, so it is not starved.
declare(strict_types=1);
require __DIR__.'/Support/demo-c-c1-observation.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{DomainObservation,HoldingObservationWork,ObservationProjection};

$wired=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$exchange));
$worker=new HoldingObservationWork($wired,$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();
// The fixture's last record read is now more than a day old, so discovery sees stale records on this active holding.
$clock->instant=$clock->now()->modify('+1 day +2 hours');
expect(ObservationProjection::recordStatus($d->fresh(),$clock->now())['stale'],'Fixture: the routine holding has stale records');
HoldingObservationWork::queueInitial($d->fresh(),$clock->now());
$overdue=$clock->now()->getTimestamp()-7200;
HoldingObservationWork::current($d->fresh())->update(['safe_code'=>'refreshed','tries'=>0,'due_at'=>$overdue]);
$routine=(int)HoldingObservationWork::current($d->fresh())->value('id');
// A second active holding on the same account has a due transient retry competing for the same turn.
$other=Domain::withoutEvents(static fn()=>Domain::create([...$d->only(['server_id','registrar_key','account_fingerprint','authority_generation','user_id']),
    'domain'=>'retry-read-placeholder.com','sld'=>'retry-read-placeholder','tld'=>'com','service_id'=>$d->service_id+1000,'status'=>'active','observation'=>[]]));
HoldingObservationWork::queueInitial($other,$clock->now());
$retryDue=$clock->now()->getTimestamp()-60;
HoldingObservationWork::current($other)->update(['safe_code'=>'read_failed','tries'=>1,'due_at'=>$retryDue]);
$retry=(int)HoldingObservationWork::current($other)->value('id');
// The guard runs at the start of turn, discovery and claim. Its third call sees the due time discovery left.
$seen=[];$guard=static function()use(&$seen,$routine):void{$seen[]=(int)DB::table('domains_observation_work')->where('id',$routine)->value('due_at');};
same('refreshed',$worker->turn($queue,$guard),'The scheduled turn reads the overdue routine holding');
same($overdue,$seen[2]??null,'Discovery keeps the overdue routine due time');
$after=DB::table('domains_observation_work')->where('id',$retry)->sole();
same([1,$retryDue,null],[(int)$after->tries,(int)$after->due_at,$after->claim],'The competing retry waits for the next turn');
expect((int)DB::table('domains_observation_work')->where('id',$routine)->value('due_at')>$clock->now()->getTimestamp(),'The finished routine read is scheduled again later');
$nativeSuccessful=true;echo "Discovery routine wait passed.\n";
