<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic offline NameSilo wire.
declare(strict_types=1);
// The deployed NameSilo sync reads only what NameSilo offers here. DNS records and DNSSEC are not offered, so a sync never
// reads them, and a DNS reply NameSilo refuses can no longer stop the glue and contact reads or raise a sync alert.
require __DIR__.'/Support/management-namesilo.php';
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\ObservationRecords;
[$d,$driver,$original]=$holding('offered');$ms->route='apibatch';
$exchange=static function($origin,$cmd,$p,$timeout)use($managementExchange):string {
    // NameSilo refuses a DNS list for a domain that does not use its nameservers.
    if(in_array($cmd,['dnsListRecords','dnsSecListRecords'],true)){return json_encode(['request'=>['operation'=>$cmd],'reply'=>['code'=>280,'detail'=>'DNS not available']],JSON_THROW_ON_ERROR);}
    return $managementExchange($origin,$cmd,$p,$timeout);
};
$ms->ids=array_fill_keys(['registrant','administrative','technical','billing'],'900001');
$ms->profiles['900001']=['first_name'=>'Synthetic','last_name'=>'Person','company'=>'','address'=>'1 Example Street','address2'=>'','city'=>'Portland','state'=>'OR','zip'=>'97201','country'=>'US','email'=>'synthetic@example.com','phone'=>'+1.5035550123'];
$reader=new ObservationReader($binding,$exchange);
$ctx=new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25),$clock,stopOnReadFailure:true);
$request=new \Paymenter\Extensions\Servers\Domains\Data\ManagementRequest($binding,'offered-read',$d->domain,'discovery',[],domainId:$d->id);
$ms->calls=[];
$out=$reader->discover($request,$ctx);
same([],array_values(array_intersect(array_column($ms->calls,0),['dnsListRecords','dnsSecListRecords'])),'The sync never reads DNS records or DNSSEC');
same(['unsupported','unsupported'],[$reader->observationCapabilities()['families']['dns'],$reader->observationCapabilities()['families']['ds']],'DNS records and DNSSEC are reported as not offered');
same([null,'complete','complete'],[$out['failed_family']??null,$out['glue']['state'],$out['settings']['contacts']['state']],'Glue and contacts are read in full');
ObservationRecords::save($d,$out,$clock->now(),$reader->observationCapabilities());
same(false,ObservationRecords::incomplete($d->fresh()),'Families NameSilo does not offer are not a failed sync');
$nativeSuccessful=true; echo "NameSilo sync reads only what is offered passed.\n";
