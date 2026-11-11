<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real Blade distinguishes a paid registration from an active domain with no changes.
require __DIR__.'/native-app.php'; require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
storefrontViews();
$domain=new OwnedDomain('example.com','Pending registration','info',null,'',null,['show'=>'/domains/1'],[],[],[],[],false,order:['state'=>'paid','kind'=>'registration']);
$html=view('domains::partials.activity',['domain'=>$domain])->render();
expect(str_contains($html,'Registration is in progress.')&&!str_contains($html,'No changes yet.'),'Pending registration History explains the work in progress');
$transfer=new OwnedDomain(...[...get_object_vars($domain),'status'=>'Pending transfer','order'=>['state'=>'paid','kind'=>'transfer']]);
expect(str_contains($html=view('domains::partials.activity',['domain'=>$transfer])->render(),'Transfer is in progress.')&&!str_contains($html,'No changes yet.'),'Paid transfer History explains the work in progress');
$active=new OwnedDomain(...[...get_object_vars($domain),'status'=>'Active','order'=>[]]);
expect(str_contains(view('domains::partials.activity',['domain'=>$active])->render(),'No changes yet.'),'An active domain still has its ordinary empty History');
$nativeSuccessful=true;echo "Registration history passed.\n";
