<?php
// Native operation sealing and projection require the installed fixture; all provider transport is fake.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestBudget,RequestContext,RequestOrigin,Support};
use Paymenter\Extensions\Servers\Domains\Services\{ObservationRecords,OwnerRecords,OwnerRecordInput};
[$d,$driver]=$holding('glue'); $host='ns1.'.$d->domain;
same(Support::Supported,$driver->capabilities()->get('glue')->support,'NameSilo glue supported');
$context=fn()=>new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock);
$ms->glue=['ns1'=>['8.8.8.8','1.1.1.1']];
$list=$driver->discover(new ManagementRequest($binding,'list-glue',$d->domain,'discovery',[]),$context());
same('1.1.1.1, 8.8.8.8',$list['glue']['records'][$host],'List preserves multiple addresses');
// Published JSON has duplicate ip keys; the adapter preserves the whole set.
$documented='{"hosts":[{"host":"ns1","ip":"8.8.8.8","ip":"1.1.1.1"}]}';
$glueClass=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Glue::class;
same($list['glue']['records'],$glueClass::records(json_decode($glueClass::preserveAddresses($documented),true),$d->domain),'Documented repeated keys keep every address');
ObservationRecords::save($d,$list,$clock->now());
same('1.1.1.1, 8.8.8.8',OwnerRecords::forDomain($d->fresh())['glue'][0]['ip'],'Customer receives complete address set');
foreach ([['glue_update','9.9.9.9, 1.1.1.1','modifyRegisteredNameServer'],['glue_delete',null,'deleteRegisteredNameServer'],['glue_add','8.8.8.8, 1.1.1.1','addRegisteredNameServer']] as [$action,$ip,$command]) {
    Cache::flush();$ms->calls=[];$payload=['host'=>$host]+($ip===null?[]:['ip'=>$ip]);
    $input=OwnerRecordInput::payload($action,$payload,$d->domain,'namesilo');$op=$submit($d,$driver,$action,$input);
    same('succeeded',$management->run($op->id,$driver)->state,'Readback confirms '.$action);
    same([$command],array_column($writes(),0),'One registered nameserver mutation');
    $commands=array_column($ms->calls,0);expect(array_search('listRegisteredNameServers',$commands,true)<array_search($command,$commands,true),'Read before mutation');
    expect(isset($d->fresh()->observation['record_reads'][$host]['at']),'Pre-change read stored with timestamp');
    $rows=OwnerRecords::forDomain($d->fresh())['glue'];
    same($ip===null?[]:[$driver->validate($action,$payload,$d->domain)],$rows,'Confirmed glue projects to owner');
}
$calls=$ms->calls;
try { $submit($d,$driver,'glue_update',['host'=>$host,'ip'=>'2606:4700:4700::1111']);expect(false,'IPv6 must refuse'); }
catch (DomainException $error) { expect(str_contains($error->getMessage(),'IPv4'),'IPv6 refusal explains IPv4'); }
same($calls,$ms->calls,'IPv6 refused before transport');
$ms->fault='listRegisteredNameServers';Cache::flush();$op=$submit($d,$driver,'glue_update',['host'=>$host,'ip'=>'9.9.9.9']);$ms->calls=[];
same('held',$management->run($op->id,$driver)->state,'Unreadable glue refuses change');same([],$writes(),'Failed preread sends no mutation');
$nativeSuccessful=true;echo "NameSilo glue list, IPv4 sets, add/change/delete and preflight passed.\n";
