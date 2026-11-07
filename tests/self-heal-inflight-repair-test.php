<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Native encrypted settings, work claims and read completion are required to reproduce this race.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationSchema};
ObservationSchema::install();[$d,$driver,$original]=$holding(placeholderComFixtureName('inflight-repair-placeholder.com'));
$worker=new HoldingObservationWork(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock)),$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
$verified=$d->server->settings()->where('key','domains_verified_credentials')->sole();$valid=$verified->value;
$financial=$original->fresh()->getAttributes();$broken=false;
// The first synthetic wire call happens after the attempt has verified and captured its credentials.
$observation->during=static function()use($verified,&$broken):void{$broken=true;$verified->value='';$verified->save();};
same('configuration_unverified',$worker->turn($queue,$guard),'Configuration breaking during a read retains its safe failure');
expect($broken,'The configuration break happened inside the read');
expect(count($observation->calls)>0,'The read started before verification was removed');
expect(HoldingObservationWork::stopped($d->fresh()),'Broken configuration stops automatic reads');
$q=HoldingObservationWork::current($d->fresh());$resumes=(int)(clone $q)->value('resumes');$calls=count($observation->calls);
same('idle',$worker->turn($queue,$guard),'Unrepaired configuration remains stopped');
same($calls,count($observation->calls),'Broken configuration cannot send another read');
$verified->value=$valid;$verified->save();
same('refreshed',$worker->turn($queue,$guard),'Restoring the same configuration automatically resumes the interrupted read');
same($resumes+1,(int)(clone $q)->value('resumes'),'Restoring verification consumes exactly one automatic resume');
expect(!HoldingObservationWork::stopped($d->fresh()),'Successful recovery clears the stopped state');
$calls=count($observation->calls);same('idle',$worker->turn($queue,$guard),'Successful recovery retains the ordinary schedule');
same($calls,count($observation->calls),'Successful recovery does not repeat the read');
same($resumes+1,(int)(clone $q)->value('resumes'),'No duplicate automatic resume');
same($financial,$original->fresh()->getAttributes(),'Read recovery preserves financial evidence');
$nativeSuccessful=true;echo "In-flight configuration repair resumes automatic reads once.\n";
