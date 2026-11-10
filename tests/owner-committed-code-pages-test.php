<?php
// Native owner POSTs need real checkout, encrypted payloads, native sessions and Blade.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-actions.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{OwnerSetup, DomainManagement};
// An unpaid transfer explains payment before the first code is saved, beside the open form.
$controller=new OwnedDomainController($owners);
foreach ([false,true] as $paid) {
    $op=$newTransfer('payment-before-code-'.($paid?'paid':'unpaid').'-placeholder.com',0,$paid,false);
    storefrontViews();app('view')->getFinder()->flush();
    $r=Request::create('/domains/'.$op->domain_id.'/code','GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
    $calls=count($state->calls);$view=$controller->code($r,$op->domain_id);$dto=$view->getData()['domain'];
    same([true,false,$paid],[$dto->transfer['open'],$dto->transfer['received'],$dto->transfer['paid']],'Initial transfer form uses the actual payment state');
    $dom=storefrontDom($view->render());
    same($paid?0:1,$dom->query('//p[normalize-space()="The transfer starts after the invoice is paid."]')->length,'Payment condition is shown before saving only when unpaid');
    same(1,$dom->query('//form[input[@name="transfer_code"]]//button[@type="submit"][normalize-space()="Save"][not(@disabled)]')->length,'The owner can still save a code before paying');
    same('',$dom->query('//input[@name="transfer_code"]')->item(0)?->getAttribute('value'),'The first code form has no prefilled secret');
    same($calls,count($state->calls),'Opening the first code form dispatches no provider work');
}
require __DIR__.'/Support/committed-code-pages.php';
$nativeSuccessful=true;echo "Native owner action matrix passed.\n";
