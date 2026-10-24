<?php
// Physical transport checks use synthetic cURL only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/enom-exchange.php';
require __DIR__.'/Support/provider-send-namesilo-wire.php';
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{CurlTransport,ReadCommand,ManagementCommand};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCalls,EnomTestCallLimit,EnomTestWriteReservation as Reservation};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{CurlTransport as NsTransport,Environment as NsEnvironment,RequestContext as NsContext,RegistrarRequest};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
same(1,\Paymenter\Extensions\Servers\Domains\Services\ProviderSendGuard::CONTRACT,'Deployment capability contract');
$events=[];
$guard=static function(...$args)use(&$events,$clock):void {
    same(4,count($args),'Guard receives only registrar, environment, command and UTC instant');
    [$registrar,$environment,$command,$at]=$args;
    expect($at instanceof DateTimeImmutable,'Immutable wall-clock instant');
    same('UTC',$at->getTimezone()->getName(),'UTC regardless of site timezone');
    same($clock->now()->getTimestamp(),$at->getTimestamp(),'Guard uses the extension clock');
    $events[]=[$registrar,$environment,$command];
};
$clock->instant=$clock->instant->setTimezone(new DateTimeZone('Pacific/Auckland'));
config(['domains.provider_send_guard'=>$guard]);
foreach([\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,\Paymenter\Extensions\Servers\Domains\Data\Environment::Live] as $env){
    foreach(ReadCommand::cases() as $named){
        $parameters=match($named){
            ReadCommand::Balance,ReadCommand::Tlds,ReadCommand::Expired=>[],
            ReadCommand::Order=>['OrderID'=>'123'],ReadCommand::Transfer=>['TransferOrderID'=>'123'],
            ReadCommand::Orders=>['BeginDate'=>'10/01/2026','EndDate'=>'10/02/2026','Start'=>1],
            ReadCommand::HostStatus=>['CheckNSName'=>'ns1.example.com'],
            ReadCommand::ProductPrice=>['TLD'=>'com','ProductType'=>10,'Years'=>1],
            ReadCommand::TldInfo,ReadCommand::Attributes=>['TLD'=>'com'],
            ReadCommand::Check=>['SLD'=>'example','TLD'=>'com','Version'=>2,'IncludePrice'=>1,'IncludeProperties'=>1,'IncludeEAP'=>1],
            default=>['SLD'=>'example','TLD'=>'com'],
        };
        resetWire();$events=[];(new CurlTransport)->send($env,$named,$parameters,'synthetic-uid','SYNTHETIC-TOKEN',25000);
        same([['enom',$env->value,$named->value]],$events,'Every named Enom read invokes guard once');
        same(1,count($GLOBALS['enomWire']['forms']),'Allowed Enom read sends once');
    }
}
foreach(['registration'=>['Purchase','ModifyNS','GetDNS'],'transfer'=>['TP_CreateOrder'],'renewal'=>['Extend','UpdateExpiredDomains'],
    'management'=>array_merge(array_map(fn($c)=>$c->value,ManagementCommand::cases()),['GetDomainInfo','GetDNS','GetContacts','GetRegLock','GetWPPSInfo','GetRenew','GetHosts','GetDomainSRVHosts','GetDnsSec','CheckNSStatus','GetBalance','GetOrderDetail'])] as $role=>$commands){
    foreach($commands as $command){
        resetWire();$events=[];$exchange($wire,$role)($origin,$command,[],25000);
        same([['enom','test',$command]],$events,'Every runtime role and command invokes guard once');
        same(1,count($GLOBALS['enomWire']['forms']),'Allowed runtime exchange sends once');
    }
}
$requests=[RegistrarRequest::balance(),RegistrarRequest::prices(),RegistrarRequest::availability(['example.com'],false),
    RegistrarRequest::availability(['example.com'],true),RegistrarRequest::domainInfo('example.com'),
    RegistrarRequest::transferStatus('example.com'),RegistrarRequest::listOrders(),RegistrarRequest::orderDetails('123')];
foreach(NsEnvironment::cases() as $env){foreach(NsContext::cases() as $context){foreach($requests as $request){
    $events=[];$GLOBALS['nsGuardSends']=0;(new NsTransport)->send($env,$context,$request,'SYNTHETIC-KEY');
    same([['namesilo',$env===NsEnvironment::Live?'live':'test',$request->command]],$events,'NameSilo environment uses neutral test/live names');
    same(1,$GLOBALS['nsGuardSends'],'Allowed NameSilo send once');
}}}
foreach([static fn()=>throw new RuntimeException('PRIVATE-GUARD-SENTINEL'),static fn()=>throw new Error('PRIVATE-GUARD-SENTINEL'),'invalid-callable'] as $denyGuard){
    config(['domains.provider_send_guard'=>$denyGuard]);
    foreach([
        fn()=>(new CurlTransport)->send(\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,ReadCommand::Balance,[],'synthetic-uid','SYNTHETIC-TOKEN',25000),
        fn()=>$exchange($wire,'registration')($origin,'Purchase',[],25000),
        fn()=>(new NsTransport)->send(NsEnvironment::Sandbox,NsContext::Background,RegistrarRequest::balance(),'SYNTHETIC-KEY'),
    ] as $send){
        resetWire();$GLOBALS['nsGuardSends']=0;$caught=null;
        try{$send();}catch(Throwable $error){$caught=$error;}
        expect($caught!==null,'Guard failure must refuse the send');
        same(null,$caught->getPrevious(),'Guard cause discarded');
        expect(!str_contains((string)$caught,'PRIVATE-GUARD-SENTINEL'),'Guard error text discarded');
        same([0,0],[count($GLOBALS['enomWire']['forms']),$GLOBALS['nsGuardSends']],'Throwing or invalid guard sends nothing');
    }
}
foreach(['renewal'=>'Extend','management'=>'SetRegLock'] as $role=>$command){
    $used=EnomTestCalls::usage()['used'];$events=[];resetWire();
    config(['domains.provider_send_guard'=>static function($registrar,$environment,$named)use(&$events,$binding,$role):void {
        expect(Reservation::unconsumed($binding,$role,701),'Guard sees the prepared, unconsumed write reservation');
        $events[]=[$registrar,$environment,$named];throw new RuntimeException('PRIVATE-GUARD-SENTINEL');
    }]);
    $caught=null;
    try{Reservation::run($binding,$role,701,static function()use($binding,$role,$command,$exchange,$wire,$origin):void {
        Reservation::reserve($binding,$role);$exchange($wire,$role)($origin,$command,[],1000);
    });}catch(EnomTestCallLimit $error){$caught=$error;}
    expect($caught instanceof EnomTestCallLimit,$role.' reserved guard refusal is definitely unsent');
    same([['enom','test',$command]],$events,$role.' reserved write reaches the guard exactly once');
    same([],$GLOBALS['enomWire']['forms'],$role.' reserved guard refusal sends nothing');
    same($used+1,EnomTestCalls::usage()['used'],$role.' refused reservation remains charged');
    expect(!Reservation::active($binding,$role),$role.' failed operation closes its reservation scope');
    // A later operation attempt must reserve a new unit; it cannot inherit the refused attempt's unit.
    config(['domains.provider_send_guard'=>null]);$caught=null;
    try{Reservation::run($binding,$role,701,static fn()=>$exchange($wire,$role)($origin,$command,[],1000));}
    catch(EnomTestCallLimit $error){$caught=$error;}
    expect($caught instanceof EnomTestCallLimit,$role.' retry cannot reuse the refused attempt reservation');
    same([],$GLOBALS['enomWire']['forms'],$role.' unreserved retry sends nothing');
    same($used+1,EnomTestCalls::usage()['used'],$role.' unreserved retry does not alter the charged count');
    Reservation::run($binding,$role,701,static function()use($binding,$role,$command,$exchange,$wire,$origin):void {
        Reservation::reserve($binding,$role);$exchange($wire,$role)($origin,$command,[],1000);
        expect(!Reservation::unconsumed($binding,$role,701),'Successful send consumes its fresh reservation');
    });
    same(1,count($GLOBALS['enomWire']['forms']),$role.' freshly reserved retry sends exactly once');
    same($used+2,EnomTestCalls::usage()['used'],$role.' retry charges a second unit without refunding the first');
}
foreach(['read'=>fn()=>(new CurlTransport)->send(\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,ReadCommand::Balance,[],'synthetic-uid','SYNTHETIC-TOKEN',1000),
    'runtime'=>fn()=>$exchange($wire,'registration')($origin,'Purchase',[],1000)] as $path=>$send){
    resetWire();$events=[];
    config(['domains.provider_send_guard'=>static function()use(&$events):void {$events[]='allowed';$GLOBALS['enomWire']['nanoseconds']+=250000000;}]);
    $send();same(['allowed'],$events,$path.' partial-budget guard ran');
    same([750,750],[$GLOBALS['enomWire']['options'][CURLOPT_CONNECTTIMEOUT_MS],$GLOBALS['enomWire']['options'][CURLOPT_TIMEOUT_MS]],$path.' guard time shortens both Enom timeouts');
    same(1,count($GLOBALS['enomWire']['forms']),$path.' partial remaining budget sends once');
    foreach([1000000000,1001000000] as $elapsed){
        resetWire();$events=[];$caught=null;
        config(['domains.provider_send_guard'=>static function()use(&$events,$elapsed):void {$events[]='expired';$GLOBALS['enomWire']['nanoseconds']+=$elapsed;}]);
        try{$send();}catch(Throwable $error){$caught=$error;}
        same(['expired'],$events,$path.' slow guard ran before deadline refusal');
        expect($caught!==null,$path.' guard cannot spend an expired Enom request budget');
        same([],$GLOBALS['enomWire']['forms'],$path.' expired Enom budget sends no bytes');
        same(1,$GLOBALS['enomWire']['closed'],$path.' expired budget closes the handle');
    }
}
config(['domains.provider_send_guard'=>null]);resetWire();
(new CurlTransport)->send(\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,ReadCommand::Balance,[],'synthetic-uid','SYNTHETIC-TOKEN',25000);
$exchange($wire,'registration')($origin,'Purchase',[],25000);
$GLOBALS['nsGuardSends']=0;(new NsTransport)->send(NsEnvironment::Sandbox,NsContext::Background,RegistrarRequest::balance(),'SYNTHETIC-KEY');
same([2,1],[count($GLOBALS['enomWire']['forms']),$GLOBALS['nsGuardSends']],'Null guard preserves all three physical senders');
$GLOBALS['nsGuardNanoseconds']=0;$GLOBALS['nsGuardSends']=0;
config(['domains.provider_send_guard'=>static function():void { $GLOBALS['nsGuardNanoseconds']+=250000000; }]);
(new NsTransport)->sendWithin(NsEnvironment::Sandbox,NsContext::Interactive,RegistrarRequest::balance(),'SYNTHETIC-KEY',1000);
same([750,750],[$GLOBALS['nsGuardOptions'][CURLOPT_CONNECTTIMEOUT_MS],$GLOBALS['nsGuardOptions'][CURLOPT_TIMEOUT_MS]],'Guard time shortens both NameSilo timeouts');
same(1,$GLOBALS['nsGuardSends'],'Partial remaining budget still sends once');
$GLOBALS['nsGuardSends']=0;$expired=null;
config(['domains.provider_send_guard'=>static function():void { $GLOBALS['nsGuardNanoseconds']+=1000000000; }]);
try{(new NsTransport)->sendWithin(NsEnvironment::Sandbox,NsContext::Background,RegistrarRequest::balance(),'SYNTHETIC-KEY',1000);}catch(RuntimeException $error){$expired=$error;}
expect($expired!==null,'Guard cannot spend an expired NameSilo request budget');
same(0,$GLOBALS['nsGuardSends'],'Expired NameSilo budget sends no bytes');
config(['domains.provider_send_guard'=>null]);
echo "Deployment guard covers every physical registrar transport and command, refusal and null default.\n";
$nativeSuccessful=true;
