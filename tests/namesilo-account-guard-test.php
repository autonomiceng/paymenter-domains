<?php
// Funded locally enrolled registration; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-registration-fixture.php';
require_once __DIR__.'/Support/namesilo-local-acquisition.php';
$guard=(object)['email'=>'wrong@example.com','id'=>'900','default'=>'1','mode'=>'email','reads'=>0];
$paidCount=0;
$original=$exchange;
$exchange=static function($origin,$command,$params,$timeout)use($guard,$original,&$paidCount,$clock):string {
    if($command==='contactAdd'&&($guard->preflightDelay??0)>0){$clock->instant=$clock->now()->modify('+'.$guard->preflightDelay.' seconds');$guard->preflightDelay=0;}
    if($command==='contactList'&&$params===[]){return nameSiloGuardWire($guard);}
    if(in_array($command,['registerDomain','renewDomain','transferDomain'],true)){$paidCount++;}
    return $original($origin,$command,$params,$timeout);
};
$make=static function(string $case)use($nsNew,$makeEngine,$binding,$exchange,$registration):array {
    $op=$nsNew(placeholderComFixtureName('local-guard-'.$case.'-placeholder.com'));
    $op->update(['payload'=>$op->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]);
    $registration->query=Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op,$op->domain);$makeEngine($binding,$exchange);
    return [$op,$GLOBALS['engine']];
};
assertLocalNameSiloAcquisition($make,$binding,$clock,$guard,static function()use(&$paidCount){return $paidCount;},$claims);
// Native config boots runtime once; use fresh composition ports for that independent inspection.
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class,new \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts);
$field=array_values(array_filter((new \Paymenter\Extensions\Servers\Domains\Domains)->getConfig(),fn($f)=>$f['name']==='domains_namesilo_expected_default_contact_email'));
same(0,count($field),'Native account setup no longer prompts for default-contact identity');
echo "Local NameSilo funded registration guard passed\n";$nativeSuccessful=true;
