<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-enom.php';
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, NameserverEvidence, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, DomainSummaries, WriteAcceptance, WriteFeatures};
require __DIR__.'/Support/reconciliation-fixture.php';
$registration->mode='clean';$makeEngine($binding,$exchange);$op=$new('editable-dns.example.com');$runRegistration($op);
require __DIR__.'/Support/new-domain-dns.php';
$editableRegistration=$op;
// A real accepted Purchase followed by a registrar refusal must remain a registered, usable holding.
$registration->mode='clean';$registration->calls=[];
$refusalExchange=static function($origin,$command,$parameters,$timeout)use($exchange,$registration):string{
    if($command==='ModifyNS'){
        $registration->calls[]=$command;
        return '<interface-response><Command>ModifyNS</Command><ErrCount>1</ErrCount><Done>true</Done><errors><Err1>Invalid nameserver information</Err1></errors></interface-response>';
    }
    if($command==='GetDNS'){
        $registration->calls[]=$command;
        return '<interface-response><Command>GetDNS</Command><ErrCount>0</ErrCount><Done>true</Done><dns>dns1.name-services.com</dns><dns>dns2.name-services.com</dns></interface-response>';
    }
    return $exchange($origin,$command,$parameters,$timeout);
};
$makeEngine($binding,$refusalExchange);$op=$new('template-refused.example.com');$result=$runRegistration($op);$assertRetained($result,['nameservers']);
$observation->name=$op->domain->domain;$observation->years=1;$observation->expiryYears=null;$observation->reference='1234';$observation->fault='captured-charge';
$result=$reconcile->run($op->id);$d=$result->domain->fresh();
same(['succeeded','active',false],[$result->state,$d->status,$result->policy['reconciliation']['staff_alert']],'Nameserver refusal does not undo registration or ask staff');
same('6.00',$result->cost['actual']['amount'],'Original Purchase remains financially complete');
same(['dns1.name-services.com','dns2.name-services.com'],\Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::nameservers($d)->nameservers,'Fallback registrar nameservers retained');
$correctionCalls=[];
$correctionDriver=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($binding,static function($origin,$command,$parameters,$timeout)use(&$correctionCalls,$d,$refusalExchange):string{
    $correctionCalls[]=$command;
    if($command==='GetDomainInfo'){return '<interface-response><Command>GetDomainInfo</Command><ErrCount>0</ErrCount><Done>true</Done><GetDomainInfo><DomainName DomainNameID="456">'.$d->domain.'</DomainName><Status><Belongs-To>account-enom</Belongs-To></Status></GetDomainInfo></interface-response>';}
    return $refusalExchange($origin,$command,$parameters,$timeout);
});
$correctionEngine=new \Paymenter\Extensions\Servers\Domains\Services\DomainManagement($freshBindings,$clock,new WriteFeatures(management:true),true);
\Illuminate\Support\Facades\Cache::flush();
$correction=\Paymenter\Extensions\Servers\Domains\Services\CorrectionWork::produce($d,$correctionEngine,$correctionDriver);
expect($correction!==null,'Existing machinery creates the nameserver correction');
$correction=$correctionEngine->run($correction->id,$correctionDriver);
same('rejected',$correction->state,'Definite nameserver refusal is final: '.$correction->state.' / '.$correction->safe_code);
same(null,\Paymenter\Extensions\Servers\Domains\Services\CorrectionWork::produce($d,$correctionEngine,$correctionDriver),'Rejected correction creates no new retry');
same(1,count(array_filter($correctionCalls,fn($c)=>$c==='ModifyNS')),'Only one correction write');
$alerts=\Illuminate\Support\Facades\DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::retained(\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d),$clock->now()->getTimestamp()));
same([],$alerts,'Refused ancillary nameserver correction raises no staff alert');
same(['title'=>'Domain registered. Chosen nameservers not confirmed.','body'=>'The customer can set up DNS with their provider, then change nameservers.','tone'=>'info'],
    \Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($result,['reason'=>\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::reason($result)]),
    'The real staff request notice keeps the expected fallback quiet, without a staff task');
$owned=$owners->present($d);$notice=\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($owned,null);
same('info',$notice['tone'],'Fallback is a quiet customer information note');
expect(str_contains($notice['text'],'Your domain is registered')&&str_contains($notice['text'],'Set up DNS'),'Customer sees what happened and how to proceed');
expect(!collect($owned->progress)->contains('state','Under review'),'Refused DNS correction does not send customer to staff');
// A registration template is not evidence of a hosted zone.
$dns=\Illuminate\Support\Facades\DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::proven(\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d),null,null,collect(),[]));
same([null,null,null],$dns,'Registration template does not prove a hosting-source comparison');

// A spend hold leaves neither the customer editor nor automatic correction available: retain the existing staff alert.
$makeEngine($binding,$refusalExchange);$heldOp=$new('template-held.example.com');$assertRetained($runRegistration($heldOp),['nameservers']);$heldDomain=$heldOp->domain->fresh();
$heldDomain->update(['renewal_policy'=>[...$heldDomain->renewal_policy,'automatic_spend_paused'=>true]]);
$observation->name=$heldDomain->domain;$heldResult=$reconcile->run($heldOp->id);$heldDomain=$heldResult->domain->fresh();
same(['succeeded',false,true],[$heldResult->state,isset($owners->present($heldDomain)->management['nameservers']),$heldResult->policy['reconciliation']['staff_alert']],'Held nameserver correction stays on the existing staff alert while the owner editor is unavailable');

// Corrupt only the encrypted payload on existing editable and completed registrations: the real owner page must still render.
storefrontViews();$request=\Illuminate\Http\Request::create('/domains','GET');$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('request')->session());
$controller=new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners);
foreach([[$editableRegistration,false],[$result,false],[$result,true]] as [$registrationCase,$staffCase]){
    $ciphertext=\Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$registrationCase->id)->value('payload');
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$registrationCase->id)->update(['payload'=>'not-encrypted']);
    try{
        if($staffCase){
            $saved=$registrationCase->fresh();
            same(null,\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::reason($saved),'Unreadable completed DNS choice leaves the staff request status usable');
            same(null,\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($saved,['reason'=>null]),'Unreadable completed DNS choice has no pending correction notice');
            same(false,\Paymenter\Extensions\Servers\Domains\Services\RegistrationDns::pending($saved,$saved->domain),'Unreadable DNS choice is not a pending correction');
        }else{
            $page=$controller->show($request,$registrationCase->domain_id);$domain=$page->getData()['domain'];
            expect(str_contains($page->render(),$domain->name)&&$domain->setup['defaultNameservers']===[]&&!$domain->dnsCorrection,'Unreadable registration payload leaves the customer page usable without invented DNS facts');
        }
    }finally{\Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$registrationCase->id)->update(['payload'=>$ciphertext]);}
}

$nativeSuccessful=true;echo "Enom registration scenario passed.\n";
