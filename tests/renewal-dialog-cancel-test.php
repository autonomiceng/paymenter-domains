<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require dirname(__DIR__).'/Domains/routes/web.php';
require dirname(__DIR__).'/Domains/routes/owned.php';
storefrontViews();
$domain=new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(name:'example.com',status:'Active',tone:'ok',expiry:'2027-11-20',renewal:'On',renewalPrice:'$14.00',actions:['show'=>'/domains/1'],progress:[],setup:[],transfer:[],management:[],testEnvironment:false,renewNow:['url'=>'/domains/1/renew','terms'=>[1]]);
foreach([true,false] as $renewOpen){
    request()->query->set('edit',$renewOpen?'renew':'');
    $html=view('domains::partials.owner-renew',['domain'=>$domain,'renewOpen'=>$renewOpen,'summary'=>null,'support'=>null,'keys'=>['renew'=>str_repeat('a',64)],'renewalChoices'=>[]])->render();
    $dom=storefrontDom($html);
    if($renewOpen){
        same(1,$dom->query('//dialog[@open]//a[@href="/domains/1" and normalize-space(.)="Cancel" and not(ancestor::noscript)]')->length,'Server-opened renewal has a usable Cancel link when inline scripts are blocked');
    }else{
        same(1,$dom->query('//dialog//button[@data-dialog-close and normalize-space(.)="Cancel"]')->length,'Script-opened renewal retains its close button');
    }
}
$nativeSuccessful=true;echo "Renewal dialog cancellation passed.\n";
