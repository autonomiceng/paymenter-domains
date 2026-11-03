<?php
// Seal latency must not eat the Purchase's own bounded call limit. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';
$binding=$bindingFor(0);$calls=[];
$exchange=static function($origin,$command,$parameters,$timeout)use(&$calls):string {
    $calls[]=[$command,$timeout];
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate></interface-response>';
};
$makeEngine($binding,$exchange);$op=$new(placeholderComFixtureName('seal-time-placeholder.com'));
$advanced=false;
Paymenter\Extensions\Servers\Domains\Models\Operation::updated(static function($saved)use($op,$clock,&$advanced):void {
    if(!$advanced&&$saved->id===$op->id&&($saved->attempt['seal']??null)==='sealed'){$advanced=true;$clock->instant=$clock->now()->modify('+2 seconds');}
});
$result=$runRegistration($op);
expect($advanced,'Test includes two seconds spent sealing after preflight');
same(['Purchase',25000],$calls[0],'Purchase receives its own full 25 second limit after seal latency');
same('pending',$result->state,'Ordinary accepted Purchase remains pending charge proof');
$nativeSuccessful=true;echo "Purchase has its own full call limit after sealing.\n";
