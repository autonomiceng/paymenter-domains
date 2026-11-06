<?php
// Funded locally enrolled transfer; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-fixture.php';
require_once __DIR__.'/Support/namesilo-local-acquisition.php';
$guard=(object)['email'=>'expected@example.com','id'=>'900','default'=>'1','mode'=>'singleton','reads'=>0];
$raw=$exchange;$paid=0;
$exchange=static function($origin,$command,$params,$timeout)use($raw,$guard,&$paid,$clock):string {
    if($command==='contactAdd'&&($guard->preflightDelay??0)>0){$clock->instant=$clock->now()->modify('+'.$guard->preflightDelay.' seconds');$guard->preflightDelay=0;}
    if($command==='contactList'&&$params===[]){return nameSiloGuardWire($guard);}
    if($command==='transferDomain'){$paid++;}
    return $raw($origin,$command,$params,$timeout);
};
$make=static function(string $case)use($newTransfer,$makeTransfer,&$engine):array {
    $op=$newTransfer(placeholderComFixtureName('local-guard-transfer-'.$case.'-placeholder.com'),1);$makeTransfer(1);return [$op,$engine];
};
// The native fixture starts with Enom; each factory selects the enrolled NameSilo account.
$localBinding=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[1]->server_id)->sole());
assertLocalNameSiloAcquisition($make,$localBinding,$clock,$guard,static function()use(&$paid){return $paid;},$claims);
echo "Local NameSilo funded transfer guard passed\n";$nativeSuccessful=true;
