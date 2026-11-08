<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['enom'];
require __DIR__.'/Support/owned.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Route};
use Paymenter\Extensions\Servers\Domains\Services\Storefront;
$store=activeFixtureStorefront($sellers,$prices,$native,$bindings,activeFixtureEligibility($bindings,$clock),$clock);
app()->instance(Storefront::class,$store);storefrontViews();
$route=Route::getRoutes()->getByName('domains.domains.transfer');
expect($route!==null,'A real transfer entry is registered');
expect(in_array('web',$route->gatherMiddleware(),true)&&!in_array('auth',$route->gatherMiddleware(),true),'Transfer entry supports guests in the native web session');
Auth::logout();
$response=app(Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('/domains/transfer','GET'));
same(200,$response->getStatusCode(),'Guest reaches the real transfer page without a login redirect');
$transfer=storefrontDom($response->getContent());
same(1,$transfer->query('//nav[@aria-label="Get a domain"]//a[@aria-current="true" and normalize-space()="Transfer"]')->length,'Transfer tab is current');
same(0,$transfer->query('//a[contains(@class,"domains-back") and @href="'.route('domains.domains.index').'"]')->length,'Guest transfer never points back at an auth-only domain list');
Auth::login($user);$owned=Request::create('/domains/transfer','GET');$owned->setLaravelSession(app('request')->session());$ownedHtml=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedTransferController($store))->show($owned)->render();
expect(str_contains($ownedHtml,'href="'.route('domains.domains.index').'"'),'Authenticated transfer retains the owned-domain back link');Auth::logout();
$data=storefrontData();$search=storefrontDom(view('domains::search',$data)->render());
same(1,$search->query('//nav[@aria-label="Get a domain"]//a[@aria-current="true" and normalize-space()="Register"]')->length,'Register tab is current');
$labels=[];foreach($search->query('//select[@name="term"]/option') as $option){$labels[]=$option->textContent;expect(str_contains($option->textContent,$option->getAttribute('data-total')),'Each year choice names its exact total');}
expect(count($labels)>=3,'Actual multi-year choices were rendered');
expect(str_contains($search->document->textContent,'first year'),'Search labels registration as the first year');
same(0,$transfer->query('//form[@role="search"]/@novalidate')->length,'Transfer keeps native required validation');
same(1,$transfer->query('//input[@name="name" and @required and @pattern]')->length,'Whitespace names fail native form validation');
foreach(['','   '] as $empty){$r=Request::create('/domains/transfer','GET',['name'=>$empty]);$r->setLaravelSession(app('request')->session());$html=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedTransferController($store))->show($r)->render();expect(str_contains($html,'Enter a domain name.'),'Explicit blank GET receives a visible validation reason');}
$nativeSuccessful=true;echo "Native guest transfer entry, tabs and exact year choices passed.\n";
