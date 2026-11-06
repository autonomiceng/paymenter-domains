<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth,Cache};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin, PrivacyMode, Support};
foreach (['large'] as $fault) {
    [$d,$driver]=$holding('refuse-'.$fault); $ms->fault=$fault; $op=$submit($d,$driver,'lock',['locked'=>false]);
    same('held',$management->run($op->id,$driver)->state,'Invalid read refuses before seal'); same([],$writes(),'No write after invalid ownership/read');
}
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
