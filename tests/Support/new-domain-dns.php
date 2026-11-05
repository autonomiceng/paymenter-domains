<?php
// Synthetic site DNS defaults through real checkout and owner capture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/site-dns-default.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionSetup,NewDomainDns,OwnerSetup};
// Native checkout and owner forms must prove persisted payloads and provider requests together.
$nativeSuccessful=false;
$account=Account::where('server_id',$binding->serverId)->sole();
same([],NewDomainDns::nameservers($binding->serverId,'register'),'An unconfigured account keeps its existing DNS behavior');
$template=DB::transaction(fn()=>WatchProfile::create(['name'=>'Registration nameservers','enabled'=>true,
    'nameservers'=>['ns1.provider.example','ns2.provider.example'],'website_endpoints'=>[],'email_endpoints'=>[],
    'edited_by'=>$user->id,'reason'=>'Synthetic registration default']));
siteDnsFixture($template->id);
same($template->id,\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::profile()->id,'Selected template is the site default');
if($binding->registrarKey==='namesilo'){
    $invalidName=placeholderComFixtureName('template-in-zone-placeholder.com');$outside=$template->nameservers;
    foreach([$invalidName,'ns1.'.$invalidName]as$inZone){
        DB::table('domains_watch_profiles')->where('id',$template->id)->update(['nameservers'=>json_encode([$inZone,'ns2.provider.example'])]);
        $counts=[\App\Models\Order::count(),\App\Models\Invoice::count(),\App\Models\Service::count(),\Paymenter\Extensions\Servers\Domains\Models\Operation::count()];
        $calls=$registration->calls;$cart=commerceCart($user);commerceAdd($cart,$nsProduct,$invalidName);$refused=commerceCheckout($user,$cart);
        same($counts,[\App\Models\Order::count(),\App\Models\Invoice::count(),\App\Models\Service::count(),\Paymenter\Extensions\Servers\Domains\Models\Operation::count()],'In-zone default is refused before an order or payable invoice exists: '.$inZone);
        same($calls,$registration->calls,'Invalid default never reaches the registration transport');
        $refused->assertDispatched('notify',static fn($event,$params)=>($params[0]['type']??null)==='error'
            &&str_contains($params[0]['message'],'nameservers outside')&&str_contains($params[0]['message'],'before payment'));
        $legacy=new \Paymenter\Extensions\Servers\Domains\Models\Operation(['server_id'=>$binding->serverId,'registrar_key'=>'namesilo','action'=>'register','payload'=>[]]);
        $legacy->setRelation('domain',new \Paymenter\Extensions\Servers\Domains\Models\Domain(['domain'=>$invalidName]));
        same([],NewDomainDns::forOperation($legacy),'Legacy fallback never reintroduces a rejected in-zone default');
    }
    DB::table('domains_watch_profiles')->where('id',$template->id)->update(['nameservers'=>json_encode($outside)]);
}
foreach(['disable','delete'] as $change){
    try{
        DB::transaction(function()use($template,$change){$row=$template->fresh();if($change==='delete'){$row->delete();}else{$row->update(['enabled'=>false,'revision'=>$row->revision+1]);}});
        throw new RuntimeException('Expected in-use template refusal');
    }catch(DomainException $e){expect(str_contains($e->getMessage(),'site DNS default')&&str_contains($e->getMessage(),'another'),'In-use refusal explains the site dependency');}
}
$registration->mode=$binding->registrarKey==='enom'?'clean':'300';$registration->calls=[];
$purchaseWire=[];$captureExchange=static function($origin,$command,$parameters,$timeout)use($exchange,&$purchaseWire){
    if(in_array($command,['Purchase','ModifyNS','registerDomain'],true)){$purchaseWire[$command]=$parameters;}
    $reply=$exchange($origin,$command,$parameters,$timeout);
    $submitted=$purchaseWire['ModifyNS']??$purchaseWire['registerDomain']??[];
    return str_replace(['ns1.provider.example','ns2.provider.example'],[$submitted['NS1']??$submitted['ns1']??'ns1.provider.example',$submitted['NS2']??$submitted['ns2']??'ns2.provider.example'],$reply);
};
$makeEngine($binding,$captureExchange);
$name=$binding->registrarKey==='enom'?'template-default.example.com':placeholderComFixtureName('template-default-placeholder.com');
$op=$binding->registrarKey==='enom'?$new($name):$nsNew($name);
same($template->nameservers,$op->payload['nameservers'],'Checkout freezes the site template without customer input');
$origin=['source'=>'site_default','profile_id'=>(int)$template->id,'profile_revision'=>(int)$template->fresh()->revision];
same($origin,$op->payload['nameservers_origin']??null,'Checkout retains inherited nameserver provenance for both registrars');
$registration->query=\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op,$op->domain);
$result=$engine->execute($op->id);same('pending',$result->state,'Default registration reaches financial observation: '.$result->safe_code);$assertRetained($result,[]);
if($binding->registrarKey==='enom'){
    same(['Purchase','ModifyNS','GetDNS'],$registration->calls,'Template reuses the existing post-purchase nameserver path');
    same(['default','No'],[$purchaseWire['Purchase']['UseDNS'],$purchaseWire['Purchase']['IgnoreNSFail']],'Purchase keeps registrar defaults and IgnoreNSFail unchanged');
    same($template->nameservers,[$purchaseWire['ModifyNS']['NS1'],$purchaseWire['ModifyNS']['NS2']],'Enom receives the template in ModifyNS');
}else{same($template->nameservers,[$purchaseWire['registerDomain']['ns1'],$purchaseWire['registerDomain']['ns2']],'NameSilo receives the template at purchase');}
$op=$binding->registrarKey==='enom'?$new('template-choice.example.com'):$nsNew(placeholderComFixtureName('template-choice-placeholder.com'));
$chosen=['ns1.customer.example','ns2.customer.example'];
(new OwnerSetup($clock,$ports))->save($user,$op->domain_id,['nameservers'=>$chosen,'privacy'=>false]);
same($chosen,$op->fresh()->payload['nameservers'],'Finish setup customer choice replaces the template');
same(['source'=>'customer'],$op->fresh()->payload['nameservers_origin']??null,'Explicit owner setup replaces inherited provenance');
$makeEngine($binding,$captureExchange);
$registration->query=\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op->fresh(),$op->domain);
$result=$engine->execute($op->id);same('pending',$result->state,'Customer choice reaches financial observation: '.$result->safe_code);$assertRetained($result,[]);
$wire=$purchaseWire[$binding->registrarKey==='enom'?'ModifyNS':'registerDomain'];
same($chosen,$binding->registrarKey==='enom'?[$wire['NS1'],$wire['NS2']]:[$wire['ns1'],$wire['ns2']],'Customer choice reaches the provider instead of the template');
same([] ,NewDomainDns::nameservers($binding->serverId,'transfer'),'Site defaults never supply transfer nameservers');
if($binding->registrarKey==='enom'){
    $transfer=$new('template-transfer.example.com',action:'transfer');
    expect(!array_key_exists('nameservers',$transfer->payload),'Transfer checkout has no default nameserver payload');
    same([],AcquisitionSetup::validate($transfer->domain,$transfer,[],false)['nameservers'],'Enom transfer keeps existing nameservers');
    commerceReject(fn()=>AcquisitionSetup::validate($transfer->domain,$transfer,$template->nameservers,false));
}
$equal=$binding->registrarKey==='enom'?$new('template-equal.example.com'):$nsNew(placeholderComFixtureName('template-equal-placeholder.com'));
(new OwnerSetup($clock,$ports))->save($user,$equal->domain_id,['nameservers'=>$template->nameservers,'privacy'=>false]);
same(['source'=>'customer'],$equal->fresh()->payload['nameservers_origin']??null,'An explicit choice equal to the old default is still a customer choice');
commerceReject(fn()=>$equal->fresh()->update(['payload'=>[...$equal->fresh()->payload,'nameservers_origin'=>$origin]]));
$op=$binding->registrarKey==='enom'?$new('template-blank.example.com'):$nsNew(placeholderComFixtureName('template-blank-placeholder.com'));
siteDnsFixture(null);
same(true,$owners->present($op->domain)->setup['defaults'],'Clearing the site template preserves the captured Finish setup default');
(new OwnerSetup($clock,$ports))->save($user,$op->domain_id,['nameservers'=>[],'privacy'=>false]);
same($template->nameservers,$op->fresh()->payload['nameservers'],'Blank Finish setup retains the frozen default');
same($origin,$op->fresh()->payload['nameservers_origin']??null,'Blank Finish setup retains inherited provenance');
siteDnsFixture(null);
$op=$binding->registrarKey==='enom'?$new('template-none.example.com'):$nsNew(placeholderComFixtureName('template-none-placeholder.com'));
expect(!array_key_exists('nameservers',$op->payload),'Clearing the template preserves the original unconfigured payload');
same($binding->registrarKey==='enom',AcquisitionSetup::ready($op),'No template preserves registrar-specific setup requirements');
// Leave only Enom configured for its refused-change scenario; NameSilo's existing tail tests missing setup.
if($binding->registrarKey==='enom'){siteDnsFixture($template->id);}
