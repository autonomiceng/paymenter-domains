<?php
// Enom 444f31c management-actions/request-gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/management.php';
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementEvidence,WriteFeatures};
// Frozen corrections have a deterministic producer and exact readback, including provider auto renew off.
[$d,$driver,$original]=$holding(placeholderComFixtureName('manage-auto-correct-placeholder.com'));
$state->renew=true;$original->update(['policy'=>[...$original->policy,'reconciliation'=>[...($original->policy['reconciliation']??[]),'observed_corrections'=>['auto_renew']]]]);
for($round=0;$round<3;$round++){Cache::flush();$correction=\Paymenter\Extensions\Servers\Domains\Services\CorrectionWork::produce($d,$management,$driver);if($correction){($correction->attempt['seal']??null)==='sealed'?$management->reconcile($correction->id,$driver):$management->run($correction->id,$driver);}}
$corrections=$d->operations()->whereNotNull('authorized_by')->where('funding_basis','non_billable_management')->get();
expect($corrections->contains('action','auto_renew_off'),'Producer creates the missing automatic renewal correction');
same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='SetRenew')),'Repeated producer cannot repeat the provider write');
$offCorrection=$corrections->firstWhere('action','auto_renew_off');same('succeeded',$offCorrection->state,'Exact false readback completes correction');
same($original->operation_key,$offCorrection->policy['management']['correction'],'Correction remains bound to original authorization');
$nativeSuccessful=true;echo "Automatic renewal correction producer and exact readback passed.\n";
