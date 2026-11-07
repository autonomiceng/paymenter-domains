<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth,Cache};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin, PrivacyMode, Support};
[$d,$driver]=$holding('slot-race'); $op=$submit($d,$driver,'auto_renew_off');
$ms->during=fn()=>Cache::put('domains-write-slot:'.$op->account_fingerprint,true,25);
same('queued',$management->run($op->id,$driver)->state,'Consume slot race defers before first send');
same('management_deferred',$op->fresh()->safe_code,'Bounded retryable deferral'); same('absent',$op->fresh()->attempt['seal'],'Deferred operation never sealed'); same([],$writes(),'Consume race emits no write');
Cache::flush(); same('succeeded',$management->run($op->id,$driver)->state,'Unsent deferral retries safely');
same(['removeAutoRenewal'],array_column($writes(),0),'Retry spends only one actual write grant');
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
