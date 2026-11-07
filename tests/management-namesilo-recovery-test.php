<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth,Cache};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin, PrivacyMode, Support};
[$d,$driver]=$holding('unknown'); $ms->fault='lost'; $op=$submit($d,$driver,'lock',['locked'=>false]);
same('uncertain',$management->run($op->id,$driver)->state,'Lost response stays uncertain'); same(1,count($writes()),'One uncertain write');
$nativeBeforeReplay = nativeFinancialSnapshot($op);
$management->run($op->id,$driver); same(1,count($writes()),'Unknown write never replayed');
same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
$ms->fault=''; $ms->route='apibatch'; same('succeeded',$management->reconcile($op->id,$driver)->state,'Read-only recovery'); same(1,count($writes()),'Recovery emits no write');
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
