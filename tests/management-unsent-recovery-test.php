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
[$d,$driver]=$holding(placeholderComFixtureName('manage-unsent-auto-placeholder.com'));$op=$submit($d,$driver,'lock',['locked'=>false]);$state->fault='GetDomainInfo';
same('held',$management->run($op->id,$driver)->state,'Preflight loss proves no management send');
for($round=0;$round<5;$round++){$clock->instant=$clock->now()->modify('+16 minutes');Cache::flush();$management->run($op->id,$driver);}
$clock->instant=$clock->now()->modify('+16 minutes');$calls=count($state->calls);$management->run($op->id,$driver);
same(['rejected','management_preparation_failed'],[$op->fresh()->state,$op->fresh()->safe_code],'Five definitely-unsent preparation failures close the nonbillable change');
same(5,count($op->fresh()->attempt['management_deferrals']),'Our unsent management recovery budget is bounded');same($calls,count($state->calls),'Exhaustion leaves visible request without more reads or writes');
$nativeSuccessful=true;echo "Bounded unsent preparation exhaustion passed.\n";
