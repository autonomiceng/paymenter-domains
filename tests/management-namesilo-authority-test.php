<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth,Cache};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin, PrivacyMode, Support};
[$d,$driver]=$holding('native-race'); $op=$submit($d,$driver,'lock',['locked'=>false]);
$ms->during=fn()=>$d->service->update(['status'=>'suspended']);
same('held',$management->run($op->id,$driver)->state,'Fresh native lifecycle drift refuses seal'); same([],$writes(),'Native drift emits no write');
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
