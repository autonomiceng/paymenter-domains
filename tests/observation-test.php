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
$capConfig=config('domains.enom_test_sends_per_day');config(['domains.enom_test_sends_per_day'=>0]);
foreach([1,2] as $turn){
 DB::table('domains_observation_work')->where('id',$job->id)->update(['due_at'=>$clock->now()->getTimestamp()]);
 $observation->during=static fn()=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimit::admit(\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,'synthetic-uid','GetDomainInfo');
 same('read_deferred',$work->tick(1)[$d->server_id],'Cap zero remains deferred on due turn '.$turn);
 $zero=DB::table('domains_observation_work')->where('id',$job->id)->sole();same([0,'refreshed',null,0,$clock->now()->getTimestamp()+60],[$zero->tries,$zero->safe_code,$zero->claim,$zero->lease_until,$zero->due_at],'Cap zero never spends a failure try or stops work');
 $clock->instant=$clock->now()->modify('+60 seconds');
}
config(['domains.enom_test_sends_per_day'=>$capConfig]);same('refreshed',$work->tick(1)[$d->server_id],'Restored capacity recovers without staff resume');
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
// Unsent acquisitions have no holding to observe, including work retained by an older sweep.
$savedAcceptance->replicate()->save();$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);
DB::table('domains_observation_work')->update(['due_at'=>$now->getTimestamp()+30*86400]);
$unsent=[];
foreach([['held','usd_balance_required'],['held','staff_review_required'],['cancelled',null],['held','usd_balance_required'],['awaiting_payment',null],['awaiting_code',null]] as $i=>[$state,$reason]){
    $o=$new(placeholderComFixtureName('unsent-observation-'.$i.'-placeholder.com'),false,true,$i===5?'transfer':'register');
    $o->update(['state'=>$state,'safe_code'=>$reason]);$unsent[]=$o->domain()->firstOrFail();
}
$preseal=$new(placeholderComFixtureName('unsent-preseal-placeholder.com'));[$claimed]=$claims->claim($preseal->id);
$claims->finish($claimed,null,'pre_send_interrupted');$unsent[]=$preseal->domain()->firstOrFail();
$calls=count($observation->calls);$money=$nativeMoney();
$work->tick(1);same($calls,count($observation->calls),'Unsent acquisitions consume no provider reads');
same(0,DB::table('domains_observation_work')->whereIn('domain_id',array_map(fn($d)=>$d->id,$unsent))->count(),'Unsent acquisitions are not enrolled');
foreach($unsent as $u){
    DB::table('domains_observation_work')->insert(['server_id'=>$u->server_id,'account_fingerprint'=>$u->account_fingerprint,'domain_id'=>$u->id,'generation'=>$u->authority_generation,'due_at'=>0,'safe_code'=>'read_failed','tries'=>1]);
    expect(!ObservationWork::stopped($u),'Old false failure is not a current sync stop');
    try{$observer->run($u->id);expect(false,'Unsent direct read must defer');}catch(\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred){}
}
same([],ObservationWork::stoppedIds(collect($unsent)),'Batched sync status also ignores old unsent failures');
$work->tick(1);same($calls,count($observation->calls),'Retained unsent work and direct reads spend no provider reads');
same(0,DB::table('domains_observation_work')->whereIn('domain_id',array_map(fn($d)=>$d->id,$unsent))->count(),'Old unsent queue diagnostics are removed, not permanently stopped');
same($money,$nativeMoney(),'Exclusion changes no invoices, payments or jobs');
// Dispatch may beat discovery after apply: an old false failure must not revive at seal.
$send=$unsent[4]->operations()->sole();
DB::table('domains_observation_work')->insert(['server_id'=>$send->server_id,'account_fingerprint'=>$send->account_fingerprint,
    'domain_id'=>$send->domain_id,'generation'=>$send->authority_generation,'due_at'=>0,'safe_code'=>'read_failed','tries'=>1]);
// A real stopped holding on the same account must remain stopped.
DB::table('domains_observation_work')->where('domain_id',$d->id)->update(['safe_code'=>'read_failed']);
$holdingWork=DB::table('domains_observation_work')->where('domain_id',$d->id)->get()->toJson();
// Supply funding through the normal fixture before claiming this previously unpaid order.
DB::table('invoices')->where('id',$send->invoice_id)->update(['status'=>'paid']);
App\Models\InvoiceTransaction::create(['invoice_id'=>$send->invoice_id,'amount'=>$send->amount,'fee'=>'0.00','status'=>'succeeded']);
[$claimed,$sending,$binding]=$claims->claim($send->id);
$ctx=new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+300 seconds'),new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(10),$clock);
$sealed=$claims->seal($claimed,$preflight->inspect($claimed,$sending,$ctx),$clock->now());expect($sealed!==null,'Previously unsent order sealed');$claims->finish($sealed,null);
expect(!ObservationWork::stopped($sending->fresh()),'Dispatch before discovery must not revive an unsent sync failure');
same($holdingWork,DB::table('domains_observation_work')->where('domain_id',$d->id)->get()->toJson(),'Dispatch preserves a real holding failure');
expect(ObservationWork::stopped($d->fresh()),'Real holding still requires resume');
$observation->name=$sending->domain;$calls=count($observation->calls);
$work->tick(1);expect(count($observation->calls)>$calls,'Possibly sent acquisition is observed without a receipt');
expect(DB::table('domains_observation_work')->where('domain_id',$sending->id)->exists(),'Submitted acquisition can enroll after exclusion');
// A failure from after submission must remain stopped, including on later discovery turns.
DB::table('domains_observation_work')->where('domain_id',$sending->id)->update(['safe_code'=>'read_failed']);
$calls=count($observation->calls);$work->tick(1);
same($calls,count($observation->calls),'Submitted acquisition failure is not automatically retried');
expect(ObservationWork::stopped($sending->fresh()),'Possibly-sent attempt retains its genuine sync stop');
// A known registrar holding stays observable even when the pending order was not its source.
$known=$unsent[0]->fresh();$known->update(['observation'=>['facts'=>['name'=>$known->domain,'generation'=>$known->authority_generation,'account_fingerprint'=>$known->account_fingerprint,'owned'=>true]]]);
$observation->name=$known->domain;$calls=count($observation->calls);$work->tick(1);
expect(count($observation->calls)>$calls,'Current owned registrar evidence remains eligible');
$nativeSuccessful=true;echo "Shared observation, follow-up and correction projections passed\n";
