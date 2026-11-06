<?php
// Native owner POSTs need real checkout, encrypted payloads, native sessions and Blade.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/site-dns-default.php';
require __DIR__.'/Support/owner-actions.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{OwnerSetup, DomainManagement};
foreach([0,1]as$i){
    $newTransfer('owner-register-route-'.$i.'-placeholder.com',$i,false,false); // choose the account through native seller policy
    $registerName=placeholderComFixtureName('owner-register-'.$i.'-placeholder.com');
    $cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[$i],'register'),$registerName);commerceCheckout($user,$cart);
    $registration=Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain',$registerName)->sole()->operations()->sole();
    if($i){
        foreach([[],['ns1.owner-register-1-placeholder.com','ns2.example.com'],['xn--bad.example.com','ns2.example.com']]as$invalid){
            $r=$req($registration->domain,'setup',['nameservers'=>implode("\n",$invalid),'privacy'=>'0','confirmed'=>'1','password'=>'PRIVATE-PASSWORD']);$action->setup($r,$registration->domain_id);
            expect($session->get('errors')->has('nameservers'),'Purchase grammar maps to a fixed field error');
            same(['nameservers'=>implode("\n",$invalid),'privacy'=>'0'],$session->get('_old_input'),'Setup retains only submitted nonsecret fields');
            expect(!isset($registration->fresh()->policy['owner_setup']),'Invalid settings never freeze');
        }
        $us=clone $registration->domain;$us->domain=reservedFixtureName('synthetic.example.com');$us->tld='us';
        try{Paymenter\Extensions\Servers\Domains\Services\AcquisitionSetup::validate($us,$registration,['ns1.example.com','ns2.example.com'],true);expect(false,'Restricted privacy refused');}
        catch(Paymenter\Extensions\Servers\Domains\Services\SetupValidation $e){same(['privacy'],$e->fields,'Restricted privacy has a safe field error');}
        storefrontViews();app('view')->share('errors',$session->get('errors'));
        $html=(new OwnedDomainController($owners))->setup($r,$registration->domain_id)->render();
        same(1,storefrontDom($html)->query('//textarea[@name="nameservers"][@aria-invalid="true"]')->length,'Real Blade identifies setup error');
        same(implode("\n",$invalid),storefrontDom($html)->query('//textarea[@name="nameservers"]')->item(0)->textContent,'Setup redisplays submitted nameservers');
        same(0,storefrontDom($html)->query('//input[@name="privacy"]')->length,'Setup errors do not expose the hidden privacy choice');
        app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
    }
    commerceReject(fn()=>$setup->save($user,$registration->domain_id,['nameservers'=>$i?['ns1.example.com','ns2.example.com']:[],'privacy'=>true]));
    expect(!isset($registration->fresh()->policy['owner_setup']),'Local writer also refuses hidden privacy without freezing setup');
    // Exercise the actual POST grammar: blank nameservers use the site template on either registrar.
    $template=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create(['name'=>'Owner POST registration DNS '.$i,'enabled'=>true,
        'nameservers'=>['ns1.provider.example','ns2.provider.example'],'website_endpoints'=>[],'email_endpoints'=>[],
        'edited_by'=>$user->id,'reason'=>'Synthetic Finish setup default']));
    $account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$registration->server_id)->sole();
    siteDnsFixture($template->id);
    $session->forget('errors');$r=$req($registration->domain,'setup',['nameservers'=>'']);nativeNormalizeInput($r);$action->setup($r,$registration->domain_id);
    expect(!$session->has('errors'),'Blank Finish setup with a template passes the HTTP boundary');
    same($template->nameservers,$registration->fresh()->payload['nameservers'],'HTTP capture fills the selected template before dispatch');
    siteDnsFixture(null);
    expect(isset($registration->fresh()->policy['owner_setup']),'Registration desired settings captured before dispatch');
    same(false,$registration->fresh()->payload['privacy'],'Setup without privacy keeps the stored choice');
    same('awaiting_payment',$registration->fresh()->state,'Setup creates no financial or processing authority');
}
$nativeSuccessful=true;echo "Native owner action matrix passed.\n";
