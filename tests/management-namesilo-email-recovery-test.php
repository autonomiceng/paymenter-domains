<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth,Cache};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin, PrivacyMode, Support};
[$d,$driver]=$holding('email-unknown'); $ms->fault='lost'; $op=$submit($d,$driver,'auth_code_email');
same('uncertain',$management->run($op->id,$driver)->state,'Lost email acknowledgement stays unknown');
$ms->fault=''; $ms->route='apibatch'; same('uncertain',$management->reconcile($op->id,$driver)->state,'Readback cannot invent original email acknowledgement');
same(['retrieveAuthCode'],array_column($writes(),0),'Email never resent by observation');
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
