<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native SQLite + real parsers prove scheduler-to-family reads without provider writes.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\StaffDomainInputs;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,ObligationAlerts,PublicRegistryReader,RenewalOwnership};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema,Runtime,RuntimePorts,RuntimeComposition,WriteFeatures,WriteAcceptance,RenewalAcceptance,TransferPrecheck,Rdap,AccountBindings,OwnerPorts};
ObservationSchema::install();
[$d,$driver,$original]=$holding(placeholderComFixtureName('automatic-records-placeholder.com'));
$bindings->setProcessing($d->server_id,false);
// Native Runtime registration is necessary: an ungated observer cannot reproduce the production refusal.
$b=$driver->capabilities()->binding;$offline=static fn()=>throw new RuntimeException('No unrelated provider calls');
$runtimePorts=new RuntimePorts;
$accept=static function(RuntimePorts $runtimePorts)use($b,$readBindings,$clock,$offline,$driver):void {
 $runtimePorts->register($b->fingerprint,static fn()=>RuntimeComposition::make($b,$readBindings,$clock,new WriteFeatures(management:true),
  new WriteAcceptance($b),new RenewalAcceptance($b),new TransferPrecheck(new Rdap($offline)),
  new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($b,$offline),
  new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Transfer($b,$offline),$offline,$driver,true));
};
$accept($runtimePorts);app()->offsetUnset(Runtime::class);app()->instance(RuntimePorts::class,$runtimePorts);
app()->instance(AccountBindings::class,$readBindings);app()->instance(OwnerPorts::class,$ports);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new AccountReadBudget($clock));
Runtime::register();$observer=app(DomainObservation::class);
$worker=new HoldingObservationWork($observer,$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
$before=$original->fresh()->getAttributes();$money=$nativeMoney();$state->calls=[];
$findings=[];$check=static function(bool $ok,string $message)use(&$findings):void {if(!$ok){$findings[]=$message;}};
same('records_disabled',$worker->turn($queue,$guard),'Production gate skips families while Processing is off');
expect(!HoldingObservationWork::stopped($d->fresh()),'A closed record gate never stops daily core reads');
$q=HoldingObservationWork::current($d->fresh());
$assertDaily=static function()use($q,$clock):void {$job=(clone $q)->sole();same(0,(int)$job->tries,'Gated core success uses no retry budget');same(86400,$job->due_at-$clock->now()->getTimestamp(),'Core read keeps daily cadence');};
$assertDaily();same([], $state->calls,'Closed processing gate makes no family calls');
// Collect both independent gate failures so the red run witnesses drift as well as the visible missing records.
storefrontViews();
$gatedFacts=\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($d->fresh(),$clock->now());
foreach(['staff'=>view('domains::partials.record-families',['recordFamilies'=>$gatedFacts,'staff'=>true])->render(),'customer'=>$page()] as $surface=>$html){
 $dom=new DOMDocument;@$dom->loadHTML($html);$xp=new DOMXPath($dom);$nodes=$xp->query('//span[@data-record-family="dns"]');
 // Staff see the gated DNS family; the customer note is wording and layout, proven by screenshot, not pinned here.
 if($surface==='staff'){expect($nodes->length>0,'staff renders gated family');}
 // Staff-only setup advice never reaches the customer page.
 if($surface==='customer'){$check(!str_contains($dom->textContent,'Processing'),'customer page shows no staff setup advice');}
}
expect(count($observation->calls)>0,'Core ownership and expiry reads still run');
expect(ObservationRecords::current($d->fresh(),$clock->now())===null,'A core read does not invent family evidence');
$at=$d->fresh()->last_synced_at->format(DATE_ATOM);$coreCalls=count($observation->calls);
same('idle',$worker->turn($queue,$guard),'Stale family discovery cannot repeatedly wake gated work');same($coreCalls,count($observation->calls),'No core read burst');
$clock->instant=$clock->now()->modify('+1 day');same('records_disabled',$worker->turn($queue,$guard),'Core read continues while Processing stays off');$assertDaily();
expect($at!==$d->fresh()->last_synced_at->format(DATE_ATOM),'Daily core facts receive a fresh time');
$readBindings->setProcessing($d->server_id,true,static fn()=>null);
$runtimePorts=new RuntimePorts;app()->instance(RuntimePorts::class,$runtimePorts);
$clock->instant=$clock->now()->modify('+1 day');same('records_disabled',$worker->turn($queue,$guard),'Production gate also skips unaccepted management');$assertDaily();
expect(!HoldingObservationWork::stopped($d->fresh()),'Missing acceptance never stops core reads');same([],$state->calls,'No unaccepted family calls');
$accept($runtimePorts);$clock->instant=$clock->now()->modify('+1 day');
same('refreshed',$worker->turn($queue,$guard),'Next automatic read includes families after both production gates open');
expect(ObservationRecords::current($d->fresh(),$clock->now())!==null,'Automatic worker saves a full-family read without a staff sync');
foreach(['GetHosts','GetDomainSRVHosts','GetDnsSec'] as $command){expect(in_array($command,array_column($state->calls,0),true),'Automatic read includes '.$command);}
$at=$d->fresh()->observation['records']['at'];$calls=count($state->calls);
same('idle',$worker->turn($queue,$guard),'Repeated page/scheduler hints are deduplicated');same($calls,count($state->calls),'Fresh read spends no duplicate calls');
$clock->instant=$clock->now()->modify('+1 day');$worker->turn($queue,$guard);
expect($at!==$d->fresh()->observation['records']['at'],'Daily due turn replaces stale family evidence');
same($before,$original->fresh()->getAttributes(),'Automatic family reads preserve all original attempt evidence');same($money,$nativeMoney(),'Automatic reads create no financial writes');
foreach($state->calls as [$command]){expect(str_starts_with($command,'Get')||$command==='CheckNSStatus','Only read commands are sent');}
storefrontViews();
$recordPage=view('domains::partials.record-families',['recordFamilies'=>\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($d->fresh(),$clock->now()),'staff'=>true])->render();
expect(str_contains($recordPage,'Last registrar read:')&&str_contains($recordPage,'Next registrar read:'),'Existing record rows show last and next reads');
// The real closed gate must not replace an ownership-drift result with a healthy daily receipt.
$readBindings->setProcessing($d->server_id,false);$observation->fault='owner';$q->update(['due_at'=>0]);$state->calls=[];
// Phase A schedules one registry read and one registrar recheck even while record checks are off.
// Both transports stay synthetic; a registry with no matching service returns an unknown answer.
$registryReads=0;
app()->instance(DepartureChecks::class,new DepartureChecks(new \Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader(static fn()=>[]),$clock,
 static function($dns,$snapshot)use(&$registryReads){$registryReads++;return new PublicRegistryReader($dns,['services'=>[]],static fn()=>throw new RuntimeException('No registry HTTP calls'));}));
$result=$worker->turn($queue,$guard);$job=(clone $q)->sole();
$check($result==='departure_registry','Closed-gate absence queues the public registry read');
$check((int)$job->tries===0&&$job->safe_code==='departure_registry','Departure work has its own bounded schedule');
$coreCalls=count($observation->calls);$first=$clock->now()->getTimestamp();
$check($worker->turn($queue,$guard)==='departure_recheck','The one registry read finishes before the registrar recheck');
$check($registryReads===1&&count($observation->calls)===$coreCalls,'The registry read sends no registrar calls');
$check((int)(clone $q)->sole()->due_at===$first+86400,'The automatic registrar recheck waits 24 hours');
$check($worker->turn($queue,$guard)==='idle','No second registrar read before its due time');
$clock->instant=$clock->now()->setTimestamp($first+86400);
$check($worker->turn($queue,$guard)==='domain_not_in_account','The one automatic recheck retains definite absence');
$check(count($observation->calls)>$coreCalls,'The recheck actually reads the registrar');
$coreCalls=count($observation->calls);
$check(($d->fresh()->observation['departure_check']['rechecked']??false)===true,'The automatic recheck is marked complete');
for($try=2;$try<=5;$try++){
 $clock->instant=$clock->now()->modify('+1 day');
 $check($worker->turn($queue,$guard)==='idle'&&count($observation->calls)===$coreCalls&&$registryReads===1,'No repeated departure reads at turn '.$try);
}
$check(($d->fresh()->observation['drift']??false)===true,'Drift facts remain visible');
$check(HoldingObservationWork::stopped($d->fresh()),'Closed-gate absence stops after its one automatic recheck');
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
// Definite account absence has a departure review instead of the generic stopped-sync review.
$keys=ObligationAlerts::retained(['domain'=>$d->fresh(),'operations'=>$d->operations()->get()],$clock->now()->getTimestamp());
ObligationAlerts::finish($d->fresh(),$keys,$clock->now()->getTimestamp());
$check(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->count()===1,'Closed-gate drift retains one staff departure review after inspection');
$alerts=array_column(StaffDomainInputs::for([$d->fresh()])[$d->id]['alerts'],'code');
$check(in_array(RenewalOwnership::REVIEW,$alerts,true),'Closed-gate drift keeps the departure review visible to staff');
$check(!in_array('observation_review',$alerts,true),'Account absence does not also raise a generic stopped-sync review');
same([],$state->calls,'Drifted closed gate still sends no family calls');
same($before,$original->fresh()->getAttributes(),'Drift retries preserve the original registration evidence');same($money,$nativeMoney(),'Drift retries create no financial writes');
DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']);
$dto=(new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter($owners,$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices)))->service($user,(int)$d->service_id);
same('Cancelled',$dto->serviceStatus,'Presenter carries the native cancellation separately');
expect($dto->status!=='Cancelled','Retained domain keeps its own state');
expect($findings===[],implode('; ',$findings));
$nativeSuccessful=true;echo "Automatic record-family reads passed\n";
