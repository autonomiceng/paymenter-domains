<?php
// Frozen .us preparation only, never public eligibility; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionSetup, ContactRecovery, ContactSetup, RegistryInputs, SetupValidation, WriteHeld};
$makeEngine($binding,$exchange);
$accepted=fixtureOfferFacts($binding);
expect(!RegistryInputs::supported('namesilo','us',[]),'Nexus preparation never publishes .us');
expect(!RegistryInputs::supported('enom','us',[]),'Enom .us remains unsupported');
commerceReject(fn()=>RegistryInputs::validate('enom','us',[],['usnc'=>'C11','usap'=>'P1']));
// Seed a synthetic frozen paid setup without opening catalogue admission for .us.
// This models a future accepted collection seam, not a customer sale or provider proof.
$nexusOp=$nsNew(placeholderComFixtureName('nexus-preparation-placeholder.com'));$name='nexus-preparation-placeholder.us';
fixtureObserveOfferFacts($binding,$accepted->endings+['us'=>$accepted->endings['com']]);
$policy=$nexusOp->policy;$policy['checkout']['name']=$name;
$snapshot=$nexusOp->domain->snapshot;$snapshot['checkout']=$policy['checkout'];
DB::table('domains_domains')->where('id',$nexusOp->domain_id)->update(['domain'=>$name,'reservation_name'=>$name,'tld'=>'us','snapshot'=>Crypt::encryptString(json_encode($snapshot))]);
DB::table('domains_operations')->where('id',$nexusOp->id)->update(['policy'=>Crypt::encryptString(json_encode($policy))]);
$nexusOp->refresh();$domain=$nexusOp->domain()->firstOrFail();
$payload=$nexusOp->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false];
$payload['registry']=['usnc'=>'C12','usap'=>'P3'];
ContactSetup::recoveryWrite(fn()=>$nexusOp->update(['payload'=>$payload]));
$nexusOp->refresh()->setRelation('domain',$domain);
ContactSetup::requireReady($nexusOp);
expect(AcquisitionSetup::ready($nexusOp),'Frozen explicit nexus passes the pre-claim grammar');
$before=$nexusOp->getRawOriginal();
commerceReject(fn()=>$nexusOp->update(['payload'=>array_replace($payload,['registry'=>['usnc'=>'C11','usap'=>'P1']])]));
$nexusOp->refresh();same($before,$nexusOp->getRawOriginal(),'Ordinary saves cannot replace frozen nexus');
foreach([[],['usnc'=>'C11'],['usnc'=>'C11','usap'=>'bad'],['usnc'=>'bad','usap'=>'P1'],
    ['usnc'=>11,'usap'=>'P1'],['usnc'=>'C11','usap'=>'P1','extra'=>'value'],null] as $values){
    commerceReject(fn()=>RegistryInputs::validate('namesilo','us',[],$values));
    $invalid=clone $nexusOp;$invalid->payload=array_replace($payload,['registry'=>$values]);$invalid->setRelation('domain',$domain);
    expect(!AcquisitionSetup::ready($invalid),'Missing or invalid frozen nexus refuses before any profile or purchase');
    ContactSetup::recoveryWrite(fn()=>$nexusOp->fresh()->update(['payload'=>$invalid->payload]));
    ContactRecovery::hold($nexusOp->id);
    same('registry_details_unsupported',$nexusOp->fresh()->safe_code,'Invalid nexus uses the existing registry hold');
    same(null,$nexusOp->fresh()->attempt,'Nexus refusal creates no dispatch attempt');
    DB::table('domains_operations')->where('id',$nexusOp->id)->update($before);
}
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::register($name,1,true,'1',$payload['nameservers'],RegistryInputs::nameSiloNexus('us',$payload['registry'])));
$private=clone $nexusOp;$private->payload=array_replace($payload,['privacy'=>true]);$private->setRelation('domain',$domain);
expect(!AcquisitionSetup::ready($private),'Privacy remains forbidden for .us');
try { AcquisitionSetup::validate($domain,$private,$payload['nameservers'],true); expect(false,'Private nexus must fail owner setup'); }
catch (SetupValidation $error) { same(['privacy'],$error->fields,'Privacy refusal identifies the owner setting'); }
$nexusContext=new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+120 seconds'),new RequestBudget(10),$clock);
$beforeCalls=$registration->calls;
try { $fresh->inspect($private,$domain,$nexusContext); expect(false,'Private nexus must fail preflight'); }
catch (WriteHeld $error) { same('registry_details_unsupported',$error->reason,'Preflight privacy refusal keeps existing registry vocabulary'); }
same($beforeCalls,$registration->calls,'Invalid nexus/privacy makes no profile or purchase call');
commerceReject(fn()=>RegistryInputs::validate('namesilo','us',[['name'=>'unaccepted']],$payload['registry']));
commerceReject(fn()=>RegistryInputs::validate('namesilo','com',[],$payload['registry']));
// Preparation preserves exact category/purpose, but cannot grant publication or a paid dispatch.
$mapping=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::register($name,1,false,'1',$payload['nameservers'],RegistryInputs::nameSiloNexus('us',$payload['registry']));
same('registerDomain',$mapping->command,'Explicit nexus retains its exact registration mapping');
same(['usnc'=>'C12','usap'=>'P3'],array_intersect_key($mapping->parameters(),array_flip(['usnc','usap'])),'Mapping preserves the exact frozen nexus');
same(0,$mapping->parameters()['private'],'.us mapping forbids privacy');
$registration->mode='300';$registration->calls=[];$makeEngine($binding,$exchange);
$finance=nativeFinancialSnapshot($nexusOp);$result=$runRegistration($nexusOp->fresh());
same('held',$result->state,'Prepared nexus cannot dispatch while .us publication remains unsupported');
same([],$registration->calls,'Unsupported nexus sends no profile, purchase or follow-up request');
expect(!isset($result->attempt['dispatch_attempted_at']),'Unsupported nexus creates no possible-send seal');
same($finance,nativeFinancialSnapshot($result),'Unsupported nexus preserves native finance and holding state');
same($payload['registry'],$result->payload['registry'],'Refusal preserves the exact frozen nexus');
$beforeCalls=$registration->calls;$engine->execute($result->id);same($beforeCalls,$registration->calls,'Held unsupported nexus never retries a purchase');
expect(!RegistryInputs::supported('namesilo','us',[]),'Synthetic mapping grants no .us publication');
echo "Frozen NameSilo nexus validation, exact mapping and closed dispatch passed\n";
