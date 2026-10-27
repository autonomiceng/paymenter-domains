<?php
// Enom444f31c synthetic observation regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema,ObservationWork};
ObservationSchema::install(); $budget=new AccountReadBudget($clock); $observer=new DomainObservation($readBindings,$clock,$budget);
[$d,$driver,$original]=$holding(placeholderComFixtureName('observed-old-holder-placeholder.com'));
$original=$original->fresh(); $before=$original->getAttributes(); $anchor=$d->renewal_policy; $expiry=$d->provider_expires_at; $revision=$d->confirmed_revision;
same('pending',$d->observation['followup']['state'],'Acquisition atomically seeds durable follow-up');
$sellers->change('com',2,$tlds[1]->server_id,true,true,$clock->now()); $bindings->setProcessing($d->server_id,false);
same('refreshed',$observer->run($d->id),'Old holder reads its account after seller switch and spending pause');
same($before,$original->fresh()->getAttributes(),'Observation does not edit original attempt or finance');
same($anchor,$d->fresh()->renewal_policy,'Observation never changes billing anchor');same($expiry->format('Y-m-d'),$d->fresh()->provider_expires_at->format('Y-m-d'),'Confirmed expiry retained');same($revision,$d->fresh()->confirmed_revision,'Revision retained');
// Fresh foreign or drifted facts are diagnostic only, including unknown flags.
$observation->years=3; $observer->run($d->id);same('2029-10-18',$d->fresh()->observation['facts']['expiry'],'Drift stored with date precision');
same('2027-10-18',$d->fresh()->provider_expires_at->format('Y-m-d'),'Drift cannot reanchor');expect($d->fresh()->observation['drift'],'Drift marker retained');
$prior=$d->fresh()->observation; $observation->during=fn()=>$d->fresh()->update(['status'=>'held']);
commerceReject(fn()=>$observer->run($d->id));same($prior,$d->fresh()->observation,'Stale read discarded'); $d->fresh()->update(['status'=>'active']);
commerceReject(fn()=>$observer->run($d->id,999));
// Durable restart/duplicate delivery, due times, failure hold and explicit bounded resume.
$observation->years=1;
$work=new ObservationWork($observer,$clock,$budget);$work->enroll($d->server_id);$work->enroll($d->server_id);
same(1,DB::table('domains_observation_queues')->count(),'Account enrollment idempotent');
$work->tick(1); $job=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole(); $calls=count($observation->calls);
(new ObservationWork($observer,$clock,$budget))->tick(1);same($calls,count($observation->calls),'Due/lease survives new worker');
DB::table('domains_observation_work')->where('id',$job->id)->update(['due_at'=>$clock->now()->getTimestamp(),'lease_until'=>$clock->now()->getTimestamp()+300]);
$work->tick(1);same($calls,count($observation->calls),'Live worker not duplicated');
$clock->instant=$clock->now()->modify('+301 seconds');$observation->fault='info_missing';
same('read_no_evidence',$work->tick(1)[$d->server_id],'Failed domain read retains its cause');
same('read_no_evidence',DB::table('domains_observation_work')->where('id',$job->id)->value('safe_code'),'Scheduled failure retains safe cause with bound account');
expect(ObservationWork::stopped($d->fresh()),'Specific failure cause remains stopped');$calls=count($observation->calls);
$clock->instant=$clock->now()->modify('+1 day');$work->tick(1);same($calls,count($observation->calls),'Failed read has no automatic retries');
$observation->fault='';$work->resume($job->id,'staff_read_resume');
DB::table('domains_read_budgets')->where('server_id',$d->server_id)->update(['used'=>60,'window_at'=>$clock->now()->getTimestamp()*1000000]);
same('read_deferred',$work->tick(1)[$d->server_id],'Local quota refusal is retryable');same(0,DB::table('domains_observation_work')->where('id',$job->id)->value('tries'),'Local denial spends no failure try');
$clock->instant=$clock->now()->modify('+60 seconds');$work->tick(1);expect(count($observation->calls)>$calls,'Quota reset permits read without another resume');
expect(isset($d->fresh()->observation['resumptions'][0]),'Resume audit retained');same($before,$original->fresh()->getAttributes(),'Refresh/restart/resume preserves operation bytes');
// Enom TEST cap denial is also local: no failure try or stopped state, then automatic recovery.
DB::table('domains_observation_work')->where('id',$job->id)->update(['due_at'=>$clock->now()->getTimestamp()]);
$priorJob=DB::table('domains_observation_work')->where('id',$job->id)->sole();
$observation->during=static fn()=>throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit;
same('read_deferred',$work->tick(1)[$d->server_id],'TEST call cap defers scheduled primary read');
$capped=DB::table('domains_observation_work')->where('id',$job->id)->sole();
same([$priorJob->tries,$priorJob->safe_code,null,0,$clock->now()->getTimestamp()+60],[$capped->tries,$capped->safe_code,$capped->claim,$capped->lease_until,$capped->due_at],'Cap clears lease and restores tries and prior safe code');
$calls=count($observation->calls);$clock->instant=$clock->now()->modify('+60 seconds');
same('refreshed',$work->tick(1)[$d->server_id],'Capacity recovery needs no staff resume');expect(count($observation->calls)>$calls,'Next due tick performs read');
same($before,$original->fresh()->getAttributes(),'Cap recovery preserves original operation evidence');
// A disabled TEST limit is the same non-consuming refusal, even over successive due turns.
$capConfig=config('domains.enom_test_daily_call_cap');config(['domains.enom_test_daily_call_cap'=>0]);
foreach([1,2] as $turn){
 DB::table('domains_observation_work')->where('id',$job->id)->update(['due_at'=>$clock->now()->getTimestamp()]);
 $observation->during=static fn()=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::reserve(\Paymenter\Extensions\Servers\Domains\Data\Environment::Test);
 same('read_deferred',$work->tick(1)[$d->server_id],'Cap zero remains deferred on due turn '.$turn);
 $zero=DB::table('domains_observation_work')->where('id',$job->id)->sole();same([0,'refreshed',null,0,$clock->now()->getTimestamp()+60],[$zero->tries,$zero->safe_code,$zero->claim,$zero->lease_until,$zero->due_at],'Cap zero never spends a failure try or stops work');
 $clock->instant=$clock->now()->modify('+60 seconds');
}
config(['domains.enom_test_daily_call_cap'=>$capConfig]);same('refreshed',$work->tick(1)[$d->server_id],'Restored capacity recovers without staff resume');
// Separately authorized correction: immutable link and appended readback progress, never closure.
$bindings->setProcessing($d->server_id, true, syntheticActivation());$op=$management->request($user,$d->id,'auto_renew_off',[],$key(),$driver,correction:$original->operation_key);
same('auto_renew',$op->policy['management']['correction_link']['section'],'Frozen section link');same('succeeded',$management->run($op->id,$driver)->state,'Separate correction execution');
$clock->instant=$clock->now()->modify('+1 minute');$observer->run($d->id);
same('readback_confirmed_awaiting_review',$d->fresh()->observation['corrections'][0]['state'],'Readback projection never closes correction');
same($op->policy['management']['correction_link'],$op->fresh()->attempt['management_reads'][0]['correction_link'],'Separate append-only progress links original section');
same($before,$original->fresh()->getAttributes(),'Original corrections retained byte-for-byte');
$policy=$op->fresh()->policy;$policy['management']['correction_link']['section']='contacts';commerceReject(fn()=>$op->fresh()->update(['policy'=>$policy]));
// Same-clock requests created A/B but executed B/A project the final physical readback.
Illuminate\Support\Facades\Cache::flush();$a=$submit($d,$driver,'nameservers',['nameservers'=>['ns5.example.com','ns6.example.com']]);
$b=$submit($d,$driver,'nameservers',['nameservers'=>['ns7.example.com','ns8.example.com']]);
same('succeeded',$management->run($b->id,$driver)->state,'Later request executes first');Illuminate\Support\Facades\Cache::flush();same('succeeded',$management->run($a->id,$driver)->state,'Earlier request executes last');
$observer->run($d->id);same($a->operation_key,$d->fresh()->observation['sections']['nameservers']['operation_key'],'Projection follows durable readback sequence, not creation ID');
// A real management preparation interleaving cannot be held by a background projection save.
Illuminate\Support\Facades\Cache::flush();$race=$submit($d,$driver,'auto_renew_off');$priorTries=DB::table('domains_observation_work')->where('id',$job->id)->value('tries');
DB::table('domains_observation_work')->where('id',$job->id)->update(['due_at'=>$clock->now()->getTimestamp()]);
$state->during=function()use($work,$d,$job,$priorTries){same('read_deferred',$work->tick(1)[$d->server_id],'Observation yields during management preparation');same($priorTries,DB::table('domains_observation_work')->where('id',$job->id)->value('tries'),'Busy authority spends no observation try');};
same('succeeded',$management->run($race->id,$driver)->state,'Live management claim consumes and sends after deferred observation');
// Exhausted pre-acquisition history cannot suppress a new completed-acquisition phase.
$bindings->setProcessing($d->server_id, true, syntheticActivation());$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);$sellers->change('com',3,$d->server_id,true,true,$now);[$pending]=$prepare(placeholderComFixtureName('followup-after-exhaustion-placeholder.com'));$pendingDomain=$pending->domain()->firstOrFail();$work->tick(1);
$pendingJob=DB::table('domains_observation_work')->where('domain_id',$pendingDomain->id)->sole();DB::table('domains_observation_work')->where('id',$pendingJob->id)->update(['safe_code'=>'budget_exhausted','tries'=>5]);
same('succeeded',$reconcile->run($pending->id)->state,'Actual completion after pending work exhausted');
for($i=0;$i<3;$i++){$clock->instant=$clock->now()->modify('+301 seconds');(new ObservationWork($observer,$clock,$budget))->tick(1);}
same('observed',$pendingDomain->fresh()->observation['followup']['state'],'New acquisition phase drains through restarted queue');
same(2,DB::table('domains_observation_work')->where('domain_id',$pendingDomain->id)->count(),'Distinct acquisition work retains pending history');same(5,DB::table('domains_observation_work')->where('id',$pendingJob->id)->value('tries'),'Old exhausted history unchanged');
// Account-level failures retain a safe cause and never consume provider capacity.
$queue=DB::table('domains_observation_queues')->where('server_id',$d->server_id)->sole();$calls=count($observation->calls);
DB::table('domains_observation_queues')->where('id',$queue->id)->update(['account_fingerprint'=>str_repeat('e',64)]);
same('binding_mismatch',$work->tick(1)[$d->server_id],'Account catch retains binding failure cause');
same($calls,count($observation->calls),'Account mismatch never reads provider');
DB::table('domains_observation_queues')->where('id',$queue->id)->update(['account_fingerprint'=>$queue->account_fingerprint]);
// A broken catalogue audit cannot prevent an independent due domain read.
$savedAcceptance=App\Models\Server::findOrFail($d->server_id)->settings()->where('key',\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::KEY)->sole();$savedAcceptance->delete();
DB::table('domains_observation_queues')->where('id',$queue->id)->update(['catalogue_audit'=>'invalid-json','catalogue_hold'=>null,'catalogue_due'=>0]);
DB::table('domains_observation_work')->where('domain_id',$d->id)->update(['safe_code'=>null,'due_at'=>0,'lease_until'=>0]);
$calls=count($observation->calls);$isolated=new ObservationWork($observer,$clock,$budget,new \Paymenter\Extensions\Servers\Domains\Services\CatalogueSync($readBindings,$prices));$isolated->tick(1);
expect(count($observation->calls)>$calls,'Catalogue diagnostic persistence cannot skip independent domain reads');
$nativeSuccessful=true;echo "Shared observation, follow-up and correction projections passed\n";
