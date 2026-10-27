<?php
// Synthetic paid transfer guard; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-fixture.php';
$guard=(object)['email'=>'expected@example.com','id'=>'900','default'=>'1','mode'=>'singleton','reads'=>0];
$raw=$exchange;$paid=0;
$exchange=static function($origin,$command,$params,$timeout)use($raw,$guard,&$paid,$clock):string {
    if($command==='contactAdd'&&($guard->preflightDelay??0)>0){$clock->instant=$clock->now()->modify('+'.$guard->preflightDelay.' seconds');$guard->preflightDelay=0;}
    if($command==='contactList'&&$params===[]){return nameSiloGuardWire($guard);}
    if($command==='transferDomain'){$paid++;}
    return $raw($origin,$command,$params,$timeout);
};
$run=static function($case)use($newTransfer,$makeTransfer,&$engine){
    $op=$newTransfer(placeholderComFixtureName('guard-transfer-'.$case.'-placeholder.com'),1);$makeTransfer(1);
    return $engine->execute($op->id);
};
$limited=$newTransfer(placeholderComFixtureName('guard-transfer-limiter-placeholder.com'),1);$makeTransfer(1);
assertNameSiloLimitedAcquisition($limited,$engine,$clock,$guard,static function()use(&$paid){return $paid;});
assertNameSiloAccountRefusals($binding,$guard,$run,static function()use(&$paid){return $paid;});
same(1,$paid,'All transfer refusals preserve exactly the one matching write');
same('uncertain',$run('match-again')->state,'Unchanged account permits another transfer');
same(2,$paid,'One transfer per matching request');
$limited=$newTransfer(placeholderComFixtureName('guard-transfer-limiter-deferred-placeholder.com'),1);$makeTransfer(1);
assertNameSiloLimitedAcquisition($limited,$engine,$clock,$guard,static function()use(&$paid){return $paid;},$claims);
$deferred=$newTransfer(placeholderComFixtureName('guard-transfer-deferred-placeholder.com'),1);$makeTransfer(1);
assertNameSiloDeferredGuard($deferred,$claims,$engine,$clock,$guard,static function()use(&$paid){return $paid;});
echo "NameSilo account transfer refusal matrix passed.\n";$nativeSuccessful=true;
