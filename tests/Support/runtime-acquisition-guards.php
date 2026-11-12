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
foreach (['register'=>$closed,'transfer'=>$closedReverse] as $action=>$disabled) {
    $clock->at=$clock->at->modify('+61 seconds'); Cache::flush();
    $blocked=$new(placeholderComFixtureName('disabled-'.$action.'-placeholder.com'),$action);
    $blocked->update(['state'=>'queued']); same('queued',$blocked->fresh()->state,'Disabled execution starts with queued paid work');
    $before=[$state->reads,$state->rdap,$state->writes];
    $disabled->operation($blocked); $result=$blocked->fresh();
    same('held',$result->state,'Disabled '.$action.' action refuses execution');
    same('write_disabled',$result->safe_code,'Disabled action names its exact gate');
    same([],$result->attempt??[],'Disabled action stays unclaimed and unsealed');
    same($before,[$state->reads,$state->rdap,$state->writes],'Disabled action performs zero provider reads or writes');
}
// Corruption must be refused by the selector before an engine can claim or read.
$op=$new(placeholderComFixtureName('route-guards-placeholder.com'),'register');$attempt=$op->attempt;$readCount=$state->reads;
$refused(fn()=>$transferPort->execute($op->id),'Acquisition operation binding changed.');
foreach(['account_fingerprint'=>str_repeat('f',64),'server_id'=>$other->serverId,'registrar_key'=>'namesilo'] as $field=>$value){
    $original=$op->getRawOriginal($field);DB::table('domains_operations')->where('id',$op->id)->update([$field=>$value]);
    $refused(fn()=>$registrationPort->execute($op->id),'Acquisition operation binding changed.');
    DB::table('domains_operations')->where('id',$op->id)->update([$field=>$original]);
}
same([$attempt,$readCount,[]],[$op->fresh()->attempt,$state->reads,$state->writes],'Route refusal leaves no claim, reads or sends');
DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'renew','cost'=>Illuminate\Support\Facades\Crypt::encryptString('[]')]);
$refused(fn()=>$composition['acquisition']->execute($op->id),'Acquisition action unavailable.');
$ports->operation($op->fresh());same('renewal_acceptance_required',$op->fresh()->safe_code,'Renewal reaches its independent disabled gate');
same([], $state->writes,'Renewal never reaches acquisition spies');
