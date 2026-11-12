<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/enom-exchange.php';
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$method=new ReflectionMethod(\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::class,'registrationAcceptance');
$source=implode('',array_slice(file($method->getFileName()),$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
expect(!str_contains($source,"RegistrationCommand::Purchase->value==='Purchase'"),'Environment permission must not claim constant command comparison as protocol evidence');
same(['route'=>'https://resellertest.enom.com/interface.asp','batch_route'=>true,'interval_seconds'=>25],
    \Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding),'TEST acceptance reports environment permission');
$live=new \Paymenter\Extensions\Servers\Domains\Data\AccountBinding('enom',1,\Paymenter\Extensions\Servers\Domains\Data\Environment::Live,'synthetic-uid');
same(false,\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($live)['batch_route'],'Unaccepted live route stays off');
// Enom renews before it answers, so a quiet Extend or late renewal gets the same full limit as Purchase.
$renewalWire=(new ReflectionFunction($make($binding,$credentials)))->getStaticVariables()['wire'];
foreach(['Extend'=>['SLD'=>'example','TLD'=>'com','NumYears'=>1,'UseCreditCard'=>'no'],'UpdateExpiredDomains'=>['DomainName'=>'example.com','NumYears'=>1,'UseCreditCard'=>'no']] as $command=>$params){
    $reply='<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>1234</OrderID></interface-response>';
    resetWire(['chunks'=>[$reply]]);same($reply,$exchange($renewalWire,'renewal')($origin,$command,$params,25000),'Renewal reply returned');
    same(25,$GLOBALS['enomWire']['options'][CURLOPT_LOW_SPEED_TIME],'A quiet '.$command.' is allowed its full call limit');
}
$nativeSuccessful=true;
