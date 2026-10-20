<?php
// Native producer gates and customer order/copy pairs. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/runtime-records.php';
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,RuntimeWork,ObservationWork,ObservationRecords,OwnerRecords,ManagementPayload,ReadDeferred,DomainObservation};
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
expect(count($state->calls)>0,'Installed scheduler reaches registered producer');same('complete',ObservationRecords::current($d->fresh())['families']['dns']['state'],'Native producer publishes complete records');
$job=DB::table('domains_observation_work')->where('domain_id',$d->id)->sole();same(0,$job->tries,'Successful native refresh resets tries');same($clock->now()->getTimestamp()+86400,$job->due_at,'Ordinary refresh cadence is daily');
$io=count($state->calls);$runtimePorts->initialManagedRecordObservations();same($io,count($state->calls),'No duplicate early refresh');
DB::enableQueryLog();DB::flushQueryLog();
$dto=$owners->present($d->fresh());
$stoppedReads=array_filter(DB::getQueryLog(),static fn($q)=>str_contains($q['query'],'from "domains_observation_work"')&&str_contains($q['query'],'exists'));
same(1,count($stoppedReads),'One stopped-state read serves all record sections in this owner projection');DB::disableQueryLog();
same(array_map(fn($i)=>'record'.$i,range(1,12)),array_column($dto->management['dns']['records'],'name'),'Customer DNS numeric order');
same(array_map(fn($i)=>'sip'.$i,range(1,12)),array_column($dto->management['srv']['records'],'service'),'Customer SRV numeric order');
$html=$page();$dom=storefrontDom($html);
same('record2',$dom->query('//input[@id="dns-1-name"]')->item(0)->getAttribute('value'),'Real DNS form uses numeric order');
same('sip10',$dom->query('//input[@id="srv-9-service"]')->item(0)->getAttribute('value'),'Real SRV form uses numeric order');
$io=count($state->calls);$domainIo=count($observation->calls);$page();same($io,count($state->calls),'Customer GET has no management I/O');same($domainIo,count($observation->calls),'Customer GET has no observation I/O');
// Retain server-side source identity in exactly the displayed order, including a repeated service.
$f=ObservationRecords::current($d->fresh())['families'];foreach($f['srv']['records']as&$row){$row['host_id']=(int)substr($row['service'],3)+100;}unset($row);
$f['srv']['records'][]=['service'=>'sip2','protocol'=>'udp','priority'=>1,'weight'=>5,'port'=>8443,'target'=>'sip.example.com.','host_id'=>202];
ObservationRecords::save($d->fresh(),$f,$clock->now());$rows=OwnerRecords::forDomain($d->fresh())['srv'];
foreach($rows as$i=>$row){$p=OwnerRecords::restoreSrv($d->fresh(),['records'=>[[...$row,'port'=>9443]],'record_sources'=>[(string)$i],'preimage_digest'=>$owners->present($d->fresh())->management['srv']['digest']]);same($row['protocol']==='udp'?202:(int)substr($row['service'],3)+100,$p['records'][0]['host_id'],'SRV displayed source maps correct provider ID');}
expect(!str_contains($page(),'host_id')&&!str_contains($page(),'HostID'),'Source identity remains server-side');
same(ManagementPayload::digest($f['srv']['records'],'srv'),ManagementPayload::digest($rows,'srv'),'Presentation order preserves canonical digest');
// Current holds suppress automatic retries, and the note states the manual-resume prerequisite.
$state->fault='GetHosts';$dueRecords();$runtimePorts->initialManagedRecordObservations();expect(ObservationRecords::held($d->fresh()),'Failed native discovery creates current hold');
$html=$page();expect(str_contains($html,'Editing returns after our team resumes record checks and a check confirms the current list.'),'Held copy describes actual manual recovery');expect(!str_contains($html,'Checks normally run daily.'),'Held records never promise a daily record retry');
$io=count($state->calls);$clock->instant=$clock->now()->modify('+1 day');$runtimePorts->initialManagedRecordObservations();same($io,count($state->calls),'Held producer never automatically retries');
$state->fault='';app(ObservationWork::class)->resume($job->id,'staff_record_resume');$runtimePorts->initialManagedRecordObservations();expect(!ObservationRecords::held($d->fresh()),'Explicit resume recovers native producer');
$p=$d->fresh()->observation;unset($p['records']);$d->fresh()->update(['observation'=>$p]);$html=$page();expect(str_contains($html,'Editing returns after a successful record check. Checks normally run daily.'),'Unheld unknown copy states daily cadence and success prerequisite');expect(!str_contains($html,'after our team resumes'),'Unheld note has no false manual hold');
$visible=strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',$html));
foreach(['enom','namesilo']as$name){expect(!str_contains(strtolower($visible),$name),'Customer copy stays registrar-neutral');}
// A failed ordinary read while processing is off stops scheduled work without a record hold.
$bindings->setProcessing($d->server_id,false);$observation->fault='info_missing';$dueRecords();$runtimePorts->initialManagedRecordObservations();
same('read_unavailable',DB::table('domains_observation_work')->where('id',$job->id)->value('safe_code'),'Paused ordinary read failure stops its job');
expect(!ObservationRecords::held($d->fresh()),'Stopped ordinary read has no fabricated record hold');
$bindings->setProcessing($d->server_id,true,static fn()=>null);$html=$page();expect(str_contains($html,'after our team resumes record checks'),'Stopped scheduled reads require manual-recovery copy');expect(!str_contains($html,'Checks normally run daily.'),'Stopped scheduled reads never promise daily retries');
$io=count($observation->calls);$clock->instant=$clock->now()->modify('+1 day');$runtimePorts->initialManagedRecordObservations();same($io,count($observation->calls),'Stopped job never retries automatically after re-enabling');
$observation->fault='';app(ObservationWork::class)->resume($job->id,'staff_scheduled_resume');$runtimePorts->initialManagedRecordObservations();expect(!ObservationRecords::stopped($d->fresh()),'Explicit resume clears stopped scheduled work');
$p=$d->fresh()->observation;unset($p['records']);$d->fresh()->update(['observation'=>$p]);
// Acceptance can disappear without changing locked rows; it is checked again before publication.
$state->during=static function()use(&$compositionReady):void{$compositionReady=false;};$dueRecords();
try{app(DomainObservation::class)->run($d->id);expect(false,'Mid-flight acceptance loss must defer publication');}catch(ReadDeferred){}
same(null,ObservationRecords::current($d->fresh()),'No inventory committed after acceptance loss');$compositionReady=true;
// Pausing mid-flight discards record publication; independent snapshots continue on later paused ticks.
$state->during=fn()=>$bindings->setProcessing($d->server_id,false);$dueRecords();
try{app(DomainObservation::class)->run($d->id);expect(false,'Mid-flight pause must defer publication');}catch(ReadDeferred){}
same(null,ObservationRecords::current($d->fresh()),'No inventory committed after processing pause');$state->calls=[];$observation->years=2;$dueRecords();$runtimePorts->initialManagedRecordObservations();
same([],$state->calls,'Paused refresh never calls record transport');same('2028-10-18',$d->fresh()->observation['facts']['expiry'],'Paused records do not freeze independent expiry observations');
same($finance,[$original->fresh()->getAttributes(),$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->fresh()->confirmed_revision,$d->fresh()->renewal_policy],'Record producer never changes financial authority');
echo "Native record producer gates, daily/manual recovery, numeric DNS/SRV and source identity pairs passed.\n";$nativeSuccessful=true;
