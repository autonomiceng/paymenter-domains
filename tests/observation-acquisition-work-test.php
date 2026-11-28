<?php
// Synthetic acquisition observation lifecycle; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$useFundedRegistration = true;
require __DIR__.'/Support/management.php';
require_once __DIR__.'/Support/observation-work.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{OperationWriter, Transition};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HistoryAcceptances,ObservationSchema,ObservationWork,ReadDeferred};
ObservationSchema::install();$budget=new AccountReadBudget($clock);$observer=new DomainObservation($readBindings,$clock,$budget);
[$d,$driver,$original]=$holding(fundedRegistrationFixtureName());
$work=observationFixtureScheduler($observer,$clock,$ports);$work->enroll($d->server_id);$work->tick(2);
$job=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();
// The existing holding already had its scheduled read; only the new acquisition is due.
DB::table('domains_observation_work')->where('id',$job->id)->update(['due_at'=>$clock->now()->getTimestamp()+86400]);
// Exhausted pre-acquisition history cannot suppress a new completed-acquisition phase.
$bindings->setProcessing($d->server_id, true, syntheticActivation());$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);activeFixtureSeller($sellers,'com',(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->value('revision'),$d->server_id,true,true,$now);[$pending]=$prepare(placeholderComFixtureName('followup-after-exhaustion-placeholder.com'));$pendingDomain=$pending->domain()->firstOrFail();
$pendingJob=DB::table('domains_observation_work')->insertGetId(['server_id'=>$pendingDomain->server_id,
    'account_fingerprint'=>$pendingDomain->account_fingerprint,'domain_id'=>$pendingDomain->id,
    'generation'=>$pendingDomain->authority_generation,'due_at'=>$now->getTimestamp()+86400,'safe_code'=>'budget_exhausted','tries'=>5]);
same('succeeded',$reconcile->run($pending->id)->state,'Actual completion after pending work exhausted');
for($i=0;$i<3;$i++){$clock->instant=$clock->now()->modify('+301 seconds');(observationFixtureScheduler($observer,$clock,$ports))->tick(2);}
same('observed',$pendingDomain->fresh()->observation['followup']['state'],'New acquisition phase drains through restarted queue');
same(2,DB::table('domains_observation_work')->where('domain_id',$pendingDomain->id)->count(),'Distinct acquisition work retains pending history');same(5,DB::table('domains_observation_work')->where('id',$pendingJob)->value('tries'),'Old exhausted history unchanged');
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
// Only the operation route retires an expired, unspent seal.
$expired = $new(placeholderComFixtureName('unused-seal-observation-placeholder.com'));
[$claim, $unsubmitted, $account] = $claims->claim($expired->id);
$ctx = new RequestContext($account, RequestOrigin::Background, $clock->now()->modify('+300 seconds'), new RequestBudget(10), $clock);
$unused = $claims->seal($claim, $preflight->inspect($claim, $unsubmitted, $ctx), $clock->now());
expect($unused !== null, 'Registration sealed but never spent its grant');
$clock->instant = $clock->now()->modify('+361 seconds');
$observation->name = $unsubmitted->domain;
$savedHolding = $unsubmitted->fresh()->getRawOriginal();
$calls = count($observation->calls);
$work->tick(2);
same([$calls, $savedHolding], [count($observation->calls), $unsubmitted->fresh()->getRawOriginal()],
    'Observation work on an expired unused seal does no registrar I/O and saves no holding facts');
$claims->purchaseRoute($expired->fresh());
expect($expired->fresh()->acquisitionAttemptFacts()->released, 'Purchase routing retires the expired unused seal');
$clock->instant = $now;
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
$sealed=$claims->seal($claimed,$preflight->inspect($claimed,$sending,$ctx),$clock->now());expect($sealed!==null,'Previously unsent order sealed');
same(Transition::Applied, $claims->locked((int) $sealed->id, fn () => OperationWriter::at($clock->now())->consumeGrant($sealed->acquisitionWorker)),
    'A lost reply follows a spent purchase grant');
$claims->finish($sealed,null);
expect(!ObservationWork::stopped($sending->fresh()),'Dispatch before discovery must not revive an unsent sync failure');
same($holdingWork,DB::table('domains_observation_work')->where('domain_id',$d->id)->get()->toJson(),'Dispatch preserves a real holding failure');
expect(ObservationWork::stopped($d->fresh()),'Real holding still requires resume');
// A legacy positive snapshot is diagnostic while the original purchase is unresolved.
$sending->refresh()->update(['observation'=>['facts'=>['name'=>$sending->domain,'generation'=>$sending->authority_generation,
    'account_fingerprint'=>$sending->account_fingerprint,'owned'=>true]]]);
DB::table('domains_observation_work')->updateOrInsert(
    ['domain_id'=>$sending->id,'generation'=>$sending->authority_generation,'phase'=>'holding'],
    ['server_id'=>$sending->server_id,'account_fingerprint'=>$sending->account_fingerprint,'due_at'=>0,'safe_code'=>'read_failed','tries'=>1]);
$oldJob=DB::table('domains_observation_work')->where('domain_id',$sending->id);
$observation->name=$sending->domain;
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'enom_registration_v1'));
$clock->instant = $now->modify('+2 hours');
$holdingDuringRead = null;
$observation->during = static function () use ($work,$observer,$observation,$sending,$send,$oldJob,$clock,&$holdingDuringRead): void {
    $live = $send->fresh()->acquisitionAttemptFacts()->readUntil > $clock->now();
    $saved = $sending->fresh()->getRawOriginal();
    $calls = count($observation->calls);
    $work->tick(2);
    $deferred = false;
    try { $observer->run($sending->id); } catch (ReadDeferred) { $deferred = true; }
    $holdingDuringRead = [
        $live, count($observation->calls)-$calls, $deferred,
        $saved === $sending->fresh()->getRawOriginal(), $oldJob->value('tries'),
    ];
};
$result = $reconcile->run($send->id);
expect($holdingDuringRead !== null && $holdingDuringRead[0], 'Exercised holding entry points during a live purchase read');
same(0,$holdingDuringRead[1],'Queue and direct refresh do no holding I/O during a pending purchase read');
same([true,true],array_slice($holdingDuringRead,2,2),'Direct refresh defers and the legacy snapshot remains unchanged');
expect(in_array($holdingDuringRead[4],[null,1],true),'Obsolete work consumes no holding try');
same('succeeded',$result->state,'The original purchase check still publishes completion');
expect(!$oldJob->where('phase','holding')->exists(),'Discovery retires the obsolete idle job');

// The same registrar account can still refresh an independently activated holding.
$observation->name=$d->domain;$calls=count($observation->calls);
same('refreshed',$observer->run($d->id),'An activated generation remains independently observable');
expect(count($observation->calls)>$calls,'The activated holding retains registrar I/O');
$nativeSuccessful=true;echo "Purchase-linked observation exclusion and active holding phases passed\n";
