<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native SQLite + real parsers prove scheduler-to-family reads without provider writes.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationRecords,ObservationSchema};
ObservationSchema::install();
[$d,$driver,$original]=$holding(placeholderComFixtureName('automatic-records-placeholder.com'));
$bindings->setProcessing($d->server_id,false);
$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports);
$worker=new HoldingObservationWork($observer,$clock);
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();$guard=static fn()=>null;
$before=$original->fresh()->getAttributes();$money=$nativeMoney();$state->calls=[];
same('refreshed',$worker->turn($queue,$guard),'Automatic holding turn succeeds with spending paused');
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
