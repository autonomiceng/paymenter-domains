<?php
// Shared full native producer-gate premise; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/runtime-records.php';
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Services\{RuntimeWork,ObservationRecords,DomainObservation};
Queue::fake();$finance=[$original->fresh()->getAttributes(),$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->confirmed_revision,$d->renewal_policy];
$state->dns=array_map(fn($i)=>['name'=>'record'.$i,'type'=>'TXT','address'=>' exact TXT '.$i.' ','mx_pref'=>10],range(12,1));
$state->srv=array_map(fn($i)=>['service'=>'sip'.$i,'protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>443,'target'=>'sip.example.com.'],range(12,1));
$bindings->setProcessing($d->server_id,false);
app(RuntimeWork::class)->tick();same(1,DB::table('domains_observation_queues')->where('turn','>',0)->count(),'Exactly one bounded observation account turn per native tick');same([],$state->calls,'Default paused account makes no record reads');same(null,ObservationRecords::current($d->fresh()),'Paused account publishes no inventory');
$registerRecords();expect($runtimePorts->accepted($a),'Synthetic accepted composition');
$dueRecords();$runtimePorts->initialManagedRecordObservations();same([],$state->calls,'Acceptance alone cannot bypass processing pause');
$bindings->setProcessing($d->server_id,true,static fn()=>null);$compositionReady=false;$dueRecords();$runtimePorts->initialManagedRecordObservations();
same([],$state->calls,'Enabled account without accepted composition makes no record reads');expect(!ObservationRecords::held($d->fresh()),'Missing acceptance creates no fabricated record hold');
$compositionReady=true;$dueRecords();$clock->instant=$clock->now()->modify('+1 hour');app(RuntimeWork::class)->tick();
foreach(['GetHosts','GetDomainSRVHosts','GetDnsSec'] as $command){same(1,count(array_filter($state->calls,fn($call)=>$call[0]===$command)),'Installed scheduler reads each supported record family once: '.$command);}same('complete',ObservationRecords::current($d->fresh())['families']['dns']['state'],'Daily read publishes confirmed DNS inventory');
app(DomainObservation::class)->run($d->id,readRecords:true);same('complete',ObservationRecords::current($d->fresh())['families']['dns']['state'],'Native producer publishes complete records');
$job=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();same(0,$job->tries,'Successful native refresh resets tries');same($clock->now()->getTimestamp()+86400,$job->due_at,'Ordinary refresh cadence is daily');
$io=count($state->calls);$runtimePorts->initialManagedRecordObservations();same($io,count($state->calls),'No duplicate early refresh');
