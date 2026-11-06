<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth,Cache};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin, PrivacyMode, Support};
[$d,$driver]=$holding('background'); $ms->route='apibatch'; $op=$submit($d,$driver,'auto_renew_off');
same('succeeded',$management->run($op->id,$driver,RequestOrigin::Background)->state,'Background batch route');
$r=new ManagementRequest($binding,'probe',$d->domain,'lock',['locked'=>true]);
foreach ([new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('-1 second'),new RequestBudget(10),$clock),
    new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+1 minute'),new RequestBudget(0),$clock)] as $ctx) {
    $count=count($ms->calls); try { $driver->prepare($r,$ctx); throw new LogicException('Unbounded read'); } catch (RuntimeException) {}
    same($count,count($ms->calls),'Expired or exhausted context performs no I/O');
}
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
