<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native record-reader witness.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
// NS15 native encrypted round-trip, owner projection and sequence planning with opaque CAA/SRV siblings.
[$d,$driver,$original]=$holding('ns15');$ms->route='apibatch';
$dnsRows=[['record_id'=>'A1','type'=>'A','host'=>$d->domain,'value'=>'8.8.8.8','ttl'=>'300','distance'=>'0'],
 ['record_id'=>'CAA1','type'=>'CAA','host'=>$d->domain,'value'=>'0:issue:ca.example','ttl'=>'7207','distance'=>'0'],
 ['record_id'=>'SRV1','type'=>'SRV','host'=>'_sip._tcp.'.$d->domain,'value'=>'5:443:sip.example.','ttl'=>'600','distance'=>'7']];
$dnsFault=false;
$nsExchange=static function($origin,$cmd,$p,$timeout)use($managementExchange,&$dnsRows,&$dnsFault):string {
 if($cmd==='dnsListRecords'){return $dnsFault?'{}':json_encode(['request'=>['operation'=>$cmd],'reply'=>['code'=>300,'detail'=>'ok','resource_record'=>$dnsRows]]);}
 if($cmd==='dnsSecListRecords'){return json_encode(['request'=>['operation'=>$cmd],'reply'=>['code'=>300,'detail'=>'ok','ds_record'=>[]]],JSON_THROW_ON_ERROR);}
 return $managementExchange($origin,$cmd,$p,$timeout);
};
$ns15=new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ObservationReader($binding,$nsExchange);
$ctx=fn()=>new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25),$clock,stopOnReadFailure:true);
$request=new \Paymenter\Extensions\Servers\Domains\Data\ManagementRequest($binding,'ns15-ns',$d->domain,'discovery',[],domainId:$d->id);
$ms->ids=array_fill_keys(['registrant','administrative','technical','billing'],'900001');
$ms->profiles['900001']=['first_name'=>'Synthetic','last_name'=>'Person','company'=>'','address'=>'1 Example Street','address2'=>'','city'=>'Portland','state'=>'OR','zip'=>'97201','country'=>'US','email'=>'synthetic@example.com','phone'=>'+1.5035550123'];
$discovered=$ns15->discover($request,$ctx());
same('complete',$discovered['dns']['state'],'NameSilo canonical DNS inventory complete');
same('complete',$discovered['settings']['contacts']['state'],'Bounded contacts read resolves all exact role IDs');
same(1,count(array_filter($ms->calls,fn($r)=>$r[0]==='contactList')),'Shared contact IDs consume one read');
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::save($d,$discovered,$clock->now(),$ns15->observationCapabilities());
$good=$d->fresh()->observation['records'];
same(false,\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::incomplete($d->fresh()),'DS mutation Off / supported read is not a perpetual failure');
$inventory=\Paymenter\Extensions\Servers\Domains\Services\OwnerRecords::nameSilo($d->fresh());
same($discovered['dns']['records'],$inventory['records'],'Encrypted CAA, TTL, provider IDs and opaque SRV survive projection');
$view=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsForm::view($inventory);
same(['CAA','SRV'],array_column($view['fixed'],'type'),'Unsupported edit types stay read-only');
$desired=$view['records'];$desired[0]['address']='1.1.1.1';
$payload=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsForm::payload($inventory,$desired,['0'],$view['digest']);
$steps=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsSequence::plan($d->domain,$inventory,$payload['records']);
same(1,count($steps),'Editing A leaves CAA/SRV siblings untouched');same(300,$steps[0]['after']['ttl'],'Existing TTL retained');
$clock->instant=$clock->now()->modify('+1 minute');$dnsRows[]=['record_id'=>'bad','type'=>'A','host'=>$d->domain,'value'=>'invalid','ttl'=>'300','distance'=>'0'];
$partial=$ns15->discover($request,$ctx());
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::save($d->fresh(),$partial,$clock->now(),$ns15->observationCapabilities());
same('partial',$d->fresh()->observation['records']['families']['dns']['state'],'Malformed sibling withdraws whole-list completeness');
same(3,count($d->fresh()->observation['records']['families']['dns']['records']),'Valid partial siblings retained');
same(null,\Paymenter\Extensions\Servers\Domains\Services\OwnerRecords::nameSilo($d->fresh()),'Partial DNS cannot authorize whole-list editing');
$status=\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::recordStatus($d->fresh(),$clock->now()->modify('+1 day'));
same('supported',$status['families']['ds']['expectation'],'DS read and mutation support remain separate');
same('unknown',$status['families']['ds']['state'],'Unattempted DS after partial DNS remains unknown');
same(true,$status['stale'],'Daily stale state is explicit');same($good['at'],$status['families']['dns']['last_good_at'],'Last-good DNS time retained');
$before=$d->fresh()->observation;$dnsFault=true;
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::failPrimary($d->fresh(),$clock->now());
same($before['records'],$d->fresh()->observation['records'],'Failed primary preserves exact prior encrypted inventory/time');

$nativeSuccessful=true;echo "NameSilo fair pending window and durable follow-up passed\n";
