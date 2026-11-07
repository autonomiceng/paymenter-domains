<?php
// Synthetic acquisition observation lifecycle; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require_once __DIR__.'/Support/observation-work.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema,ObservationWork};
ObservationSchema::install();$budget=new AccountReadBudget($clock);$observer=new DomainObservation($readBindings,$clock,$budget);
[$d,$driver,$original]=$holding(placeholderComFixtureName('observation-acquisition-work-placeholder.com'));
$work=observationFixtureScheduler($observer,$clock,$ports);$work->enroll($d->server_id);$work->tick(2);
$job=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();
// The existing holding already had its scheduled read; only the new acquisition is due.
DB::table('domains_observation_work')->where('id',$job->id)->update(['due_at'=>$clock->now()->getTimestamp()+86400]);
// Exhausted pre-acquisition history cannot suppress a new completed-acquisition phase.
$bindings->setProcessing($d->server_id, true, syntheticActivation());$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);activeFixtureSeller($sellers,'com',(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->value('revision'),$d->server_id,true,true,$now);[$pending]=$prepare(placeholderComFixtureName('followup-after-exhaustion-placeholder.com'));$pendingDomain=$pending->domain()->firstOrFail();$work->tick(2);
$pendingJob=DB::table('domains_observation_work')->where('domain_id',$pendingDomain->id)->sole();DB::table('domains_observation_work')->where('id',$pendingJob->id)->update(['safe_code'=>'budget_exhausted','tries'=>5]);
same('succeeded',$reconcile->run($pending->id)->state,'Actual completion after pending work exhausted');
for($i=0;$i<3;$i++){$clock->instant=$clock->now()->modify('+301 seconds');(observationFixtureScheduler($observer,$clock,$ports))->tick(2);}
same('observed',$pendingDomain->fresh()->observation['followup']['state'],'New acquisition phase drains through restarted queue');
same(2,DB::table('domains_observation_work')->where('domain_id',$pendingDomain->id)->count(),'Distinct acquisition work retains pending history');same(5,DB::table('domains_observation_work')->where('id',$pendingJob->id)->value('tries'),'Old exhausted history unchanged');
// Account-level failures retain a safe cause and never consume provider capacity.
$queue=DB::table('domains_observation_queues')->where('server_id',$d->server_id)->sole();$calls=count($observation->calls);
DB::table('domains_observation_queues')->where('id',$queue->id)->update(['account_fingerprint'=>str_repeat('e',64)]);
same('binding_mismatch',$work->tick(2)[$d->server_id]['holding'],'Account catch retains binding failure cause');
same($calls,count($observation->calls),'Account mismatch never reads provider');
DB::table('domains_observation_queues')->where('id',$queue->id)->update(['account_fingerprint'=>$queue->account_fingerprint]);
// A broken catalogue audit cannot prevent an independent due domain read.
$savedOfferWork=DB::table('domains_observation_queues')->where('id',$queue->id)->value('offer_work');
DB::table('domains_observation_queues')->where('id',$queue->id)->update(['offer_work'=>'invalid-json']);
DB::table('domains_observation_work')->where('domain_id',$d->id)->update(['safe_code'=>null,'due_at'=>0,'lease_until'=>0]);
$calls=count($observation->calls);$isolated=observationFixtureScheduler($observer,$clock,$ports);$isolated->tick(2);
expect(count($observation->calls)>$calls,'Catalogue diagnostic persistence cannot skip independent domain reads');
// Unsent acquisitions have no holding to observe, including work retained by an older sweep.
DB::table('domains_observation_queues')->where('id',$queue->id)->update(['offer_work'=>$savedOfferWork]);$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);
DB::table('domains_observation_work')->update(['due_at'=>$now->getTimestamp()+30*86400]);
$unsent=[];
foreach([['held','usd_balance_required'],['held','staff_review_required'],['cancelled',null],['held','usd_balance_required'],['awaiting_payment',null],['awaiting_code',null]] as $i=>[$state,$reason]){
    $o=$new(placeholderComFixtureName('unsent-observation-'.$i.'-placeholder.com'),false,true,$i===5?'transfer':'register');
    $o->update(['state'=>$state,'safe_code'=>$reason]);$unsent[]=$o->domain()->firstOrFail();
}
$preseal=$new(placeholderComFixtureName('unsent-preseal-placeholder.com'));[$claimed]=$claims->claim($preseal->id);
$claims->finish($claimed,null,'pre_send_interrupted');$unsent[]=$preseal->domain()->firstOrFail();
$calls=count($observation->calls);$money=$nativeMoney();
$work->tick(2);same($calls,count($observation->calls),'Unsent acquisitions consume no provider reads');
same(0,DB::table('domains_observation_work')->whereIn('domain_id',array_map(fn($d)=>$d->id,$unsent))->count(),'Unsent acquisitions are not enrolled');
foreach($unsent as $u){
    DB::table('domains_observation_work')->insert(['server_id'=>$u->server_id,'account_fingerprint'=>$u->account_fingerprint,'domain_id'=>$u->id,'generation'=>$u->authority_generation,'due_at'=>0,'safe_code'=>'read_failed','tries'=>1]);
    expect(!ObservationWork::stopped($u),'Old false failure is not a current sync stop');
    try{$observer->run($u->id);expect(false,'Unsent direct read must defer');}catch(\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred){}
}
same([],ObservationWork::stoppedIds(collect($unsent)),'Batched sync status also ignores old unsent failures');
$work->tick(2);same($calls,count($observation->calls),'Retained unsent work and direct reads spend no provider reads');
same(0,DB::table('domains_observation_work')->whereIn('domain_id',array_map(fn($d)=>$d->id,$unsent))->count(),'Old unsent queue diagnostics are removed, not permanently stopped');
same($money,$nativeMoney(),'Exclusion changes no invoices, payments or jobs');
// Dispatch may beat discovery after apply: an old false failure must not revive at seal.
$send=$unsent[4]->operations()->sole();
DB::table('domains_observation_work')->insert(['server_id'=>$send->server_id,'account_fingerprint'=>$send->account_fingerprint,
    'domain_id'=>$send->domain_id,'generation'=>$send->authority_generation,'due_at'=>0,'safe_code'=>'read_failed','tries'=>1]);
// A real stopped holding on the same account must remain stopped.
DB::table('domains_observation_work')->where('domain_id',$d->id)->update(['safe_code'=>'binding_mismatch']);
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
$work->tick(2);expect(count($observation->calls)>$calls,'Possibly sent acquisition is observed without a receipt');
expect(DB::table('domains_observation_work')->where('domain_id',$sending->id)->exists(),'Submitted acquisition can enroll after exclusion');
// A failure from after submission must remain stopped, including on later discovery turns.
DB::table('domains_observation_work')->where('domain_id',$sending->id)->update(['safe_code'=>'read_failed']);
$calls=count($observation->calls);$work->tick(2);
same($calls,count($observation->calls),'Submitted acquisition failure is not retried before its due time');
expect(!ObservationWork::stopped($sending->fresh()),'Possibly-sent read failure is retryable, never permission to resend the purchase');
// A known registrar holding stays observable even when the pending order was not its source.
$known=$unsent[0]->fresh();$known->update(['observation'=>['facts'=>['name'=>$known->domain,'generation'=>$known->authority_generation,'account_fingerprint'=>$known->account_fingerprint,'owned'=>true]]]);
$observation->name=$known->domain;$calls=count($observation->calls);$work->tick(2);
expect(count($observation->calls)>$calls,'Current owned registrar evidence remains eligible');
$nativeSuccessful=true;echo "Acquisition observation eligibility, phases, exclusion and preserved stops passed\n";
