<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/runtime-acquisition-fixture.php';
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,Money,QuoteEvidence,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AcquisitionPort, AcquisitionPorts, CartAdmission, CheckoutEligibility, InvoiceBinding, NativeCatalogue, OperationClaims, PriceCatalogue, Rdap, Refunds, RegistrarRegistry, RegistrationProfiles, RenewalAcceptance, RuntimeComposition, RuntimePorts, RuntimeWork, SellerPolicies, TransferInputs, TransferPrecheck, WriteAcceptance, WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Registration,Transfer,Management};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
// Genuine paid native work alternates on one account with the same registered factory.
$worker=new RuntimeWork($clock,$ports);$expected=[];
foreach(['register','transfer','register','transfer'] as $i=>$action){
    $clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('alternating-'.$i.'-placeholder.com'),$action);
    $beforeReads=$state->reads;$beforeRdap=$state->rdap;
    $worker->invoice($op->invoice_id);Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,fn($job)=>$job->operationId===$op->id);
    $worker->operation($op->id);$expected[]=[$action,$op->domain->domain];
    same($expected,$state->writes,'Correct action protocol exchanges exactly once in order: '.json_encode([$op->fresh()->state,$op->fresh()->safe_code,$state->writes]));
    same($action==='register'?'pending':'uncertain',$op->fresh()->state,'Real acquisition engine records its action outcome');
    same('sealed',$op->fresh()->attempt['seal'],'Actual funding and dispatch checks completed');
    same($action==='register'?1:2,$state->reads-$beforeReads,'Real action-specific fresh preflight count');
    if($action==='transfer'){expect($state->rdap-$beforeRdap>=2,'Transfer repeats fresh RDAP admission');}
    $composition['acquisition']->execute($op->id);same($expected,$state->writes,'Duplicate delivery never replays a monetary command');
}
foreach(['register','transfer'] as $action){
    $clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('failed-preflight-'.$action.'-placeholder.com'),$action);$state->premium=true;
    $ports->operation($op);$state->premium=false;
    same('held',$op->fresh()->state,'Failed fresh preflight holds '.$action);same('absent',$op->fresh()->attempt['seal'],'Preflight refusal precedes sealing');
    same($expected,$state->writes,'Failed preflight sends neither action');
}
same($binding->fingerprint,$account->fresh()->account_fingerprint,'Alternation preserves account identity');
echo "Installed typed acquisition: pure seven-role composition, action/binding refusals, independent renewal and paid alternating protocols passed.\n";
