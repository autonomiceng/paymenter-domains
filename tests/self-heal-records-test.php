<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native SQLite + real parsers prove scheduler-to-family reads without provider writes.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\DB;
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
same('records_disabled',$worker->turn($queue,$guard),'Production gate skips families while Processing is off');
expect(!HoldingObservationWork::stopped($d->fresh()),'A closed record gate never stops daily core reads');
$q=HoldingObservationWork::current($d->fresh());
$assertDaily=static function()use($q,$clock):void {$job=(clone $q)->sole();same(0,(int)$job->tries,'Gated core success uses no retry budget');same(86400,$job->due_at-$clock->now()->getTimestamp(),'Core read keeps daily cadence');};
$assertDaily();same([], $state->calls,'Closed processing gate makes no family calls');
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
DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']);
$dto=(new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter($owners,$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices)))->service($user,(int)$d->service_id);
same('Cancelled',$dto->serviceStatus,'Presenter carries the native cancellation separately');
expect($dto->status!=='Cancelled','Retained domain keeps its own state');
$nativeSuccessful=true;echo "Automatic record-family reads passed\n";
