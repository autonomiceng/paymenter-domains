<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin};
// Each authority fault starts from the one prepared NameSilo fixture with its own completed holding:
// lifecycle drift, background route and bounded context, then owner, read and size faults before seal.
$cases['native-race']=static function()use($holding,$submit,$management,$ms,$writes):void {
    [$d,$driver]=$holding('native-race'); $op=$submit($d,$driver,'lock',['locked'=>false]);
    $ms->during=fn()=>$d->service->update(['status'=>'suspended']);
    same('held',$management->run($op->id,$driver)->state,'Fresh native lifecycle drift refuses seal'); same([],$writes(),'Native drift emits no write');
};
$cases['background']=static function()use($holding,$submit,$management,$ms,$clock,&$binding):void {
    [$d,$driver]=$holding('background'); $ms->route='apibatch'; $op=$submit($d,$driver,'auto_renew_off');
    same('succeeded',$management->run($op->id,$driver,RequestOrigin::Background)->state,'Background batch route');
    $r=new ManagementRequest($binding,'probe',$d->domain,'lock',['locked'=>true]);
    foreach ([new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('-1 second'),new RequestBudget(10),$clock),
        new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+1 minute'),new RequestBudget(0),$clock)] as $ctx) {
        $count=count($ms->calls); try { $driver->prepare($r,$ctx); throw new LogicException('Unbounded read'); } catch (RuntimeException) {}
        same($count,count($ms->calls),'Expired or exhausted context performs no I/O');
    }
};
foreach (['owner','getDomainInfo','large'] as $fault) {
    $cases[$fault]=static function()use($fault,$holding,$submit,$management,$ms,$writes):void {
        [$d,$driver]=$holding('refuse-'.$fault); $ms->fault=$fault; $op=$submit($d,$driver,'lock',['locked'=>false]);
        same('held',$management->run($op->id,$driver)->state,'Invalid read refuses before seal'); same([],$writes(),'No write after invalid ownership/read');
    };
}
// The RDAP reader caches the registry bootstrap; restoring it makes every case read it as a fresh process does.
nativeFixtureCases($cases,compact('clock','ms','observe','state','rdap','management','user','engine','claims'));
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
