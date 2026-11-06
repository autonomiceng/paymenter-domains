<?php
// Enom 444f31c management-actions/request-gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/management.php';
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementEvidence,WriteFeatures};
// Sealed uncertainty is read only; an expired unsealed claim can prepare again within our bounded retry budget.
[$d,$driver]=$holding(placeholderComFixtureName('manage-unsent-restored-placeholder.com'));$op=$submit($d,$driver,'lock',['locked'=>false]);$state->fault='GetDomainInfo';$management->run($op->id,$driver);
$state->fault='';$clock->instant=$clock->now()->modify('+16 minutes');Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Unsent preparation recovers automatically when reads return');
same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='SetRegLock')),'Recovered management request sends once');
$nativeSuccessful=true;echo "Unsent preparation single-send recovery passed.\n";
