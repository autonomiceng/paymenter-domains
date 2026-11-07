<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\ContactProfile;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ManagementProfiles;
// Frozen transfer details drive both durable profile staging and association without staff input.
[$d,$driver,$original]=$holding('frozen-correction');
$original->update(['policy'=>[...$original->policy,'reconciliation'=>[...($original->policy['reconciliation']??[]),'observed_corrections'=>['contacts']]]]);
for($round=0;$round<10;$round++){
    Cache::flush();$correction=\Paymenter\Extensions\Servers\Domains\Services\CorrectionWork::produce($d,$management,$driver);
    if($correction){($correction->attempt['seal']??null)==='sealed'?$management->reconcile($correction->id,$driver):$management->run($correction->id,$driver);}
}
same(4,count(array_filter($writes(),fn($v)=>$v[0]==='contactAdd')),'Exactly one isolated profile per frozen role');
same(4,count(array_filter($writes(),fn($v)=>$v[0]==='contactDomainAssociate')),'Every frozen role associated once');
same(null,\Paymenter\Extensions\Servers\Domains\Services\CorrectionWork::produce($d,$management,$driver),'Completed corrections converge without another write');
$nativeSuccessful=true; echo "management-namesilo-contact-corrections-test.php passed\n";
