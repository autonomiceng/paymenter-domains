<?php
// Native producer gates and customer order/copy pairs. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/runtime-records-ready.php';
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,RuntimeWork,ObservationWork,ObservationRecords,OwnerRecords,ManagementPayload,ReadDeferred,DomainObservation};
DB::enableQueryLog();DB::flushQueryLog();
$dto=$owners->present($d->fresh());
$stoppedReads=array_filter(DB::getQueryLog(),static fn($q)=>str_contains($q['query'],'from "domains_observation_work"')&&str_contains($q['query'],'exists'));
same(1,count($stoppedReads),'One stopped-state read serves all record sections in this owner projection');DB::disableQueryLog();
same(array_map(fn($i)=>'record'.$i,range(1,12)),array_column($dto->management['dns']['records'],'name'),'Customer DNS numeric order');
same(array_map(fn($i)=>'sip'.$i,range(1,12)),array_column($dto->management['srv']['records'],'service'),'Customer SRV numeric order');
$html=$page();$dom=storefrontDom($html);
same(['Renewal','Registrar records'],array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('//div[contains(@class,"domains-cols")]/div[contains(@class,"domains-sheet")]/section/div/h2'))),'Native customer sections follow the approved mock order');
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
same($finance,[$original->fresh()->getAttributes(),$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->fresh()->confirmed_revision,$d->fresh()->renewal_policy],'Record presentation never changes financial authority');
// Held/stopped customer rows and the shared page notice explain recovery together.
$p=$d->fresh()->observation;unset($p['records']);$d->fresh()->update(['observation'=>$p]);
ObservationRecords::hold($d->fresh(),'records_unavailable',$clock->now());
foreach(['held','stopped']as$readState){
 if($readState==='stopped'){\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::current($d->fresh())->update(['safe_code'=>'read_unavailable','tries'=>5]);}
 $heldHtml=$page();same($readState==='stopped',str_contains($heldHtml,e(\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD)),'Only a stopped read needs the staff-recovery page notice');
 foreach(['dns','srv']as$kind){$row=storefrontDom($heldHtml)->query('//li[@id="'.$kind.'"]')->item(0);expect($row!==null&&str_contains($row->textContent,'Missing'),$readState.' '.$kind.' row stays visible with its calm missing value');}
}
echo "Native record producer gates, numeric DNS/SRV and source identity pairs passed.\n";$nativeSuccessful=true;
