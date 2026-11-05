<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real native pricing, Livewire mount and Blade must agree across separate requests.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
require_once __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Intent, Query};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
extract($fixture=offerCommerceFixture());
[$secondBinding,$second]=offerCommerceSecond($fixture);
$user=commerceUser(); $com=$storefront->offer('com','register',3)['product'];
$q=new Query($binding,['com'],'register',3);
$rev=static fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;
$cart=commerceCart($user); $line=commerceAdd($cart,$com,'reload-price-placeholder.com',3);
commerceAdd($cart,$second['product'],'reload-removed-placeholder.net');
$views=getenv('PAYMENTER_TEST_FIXTURE').'/resources/views/reload-cart'; mkdir($views,0700,true);
file_put_contents($views.'/cart.blade.php', '<div>@includeIf(\'domains::cart-notice\')@foreach (\App\Classes\Cart::items() as $item)@includeIf(\'domains::cart-was\',[\'item\'=>$item]){{ $item->price->format($item->price->total) }}@endforeach</div>');
Illuminate\Support\Facades\View::getFinder()->prependLocation($views);
Illuminate\Support\Facades\View::getFinder()->flush();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$open=static function()use($user,$cart){Illuminate\Support\Once::flush();return Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class);};
$page=$open(); same('29.90',number_format((float)$page->get('total')->total,2,'.',''),'Original cart total');
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:3,transfers:true),[...PricingRule::DEFAULT,'markup'=>'50.00']),$rev());
$ports->apply(Edit::override($secondBinding,'net',new Intent(sell:false,transfers:true)),$ports->readOffers(new Query($secondBinding,['net'],'register'),Context::Staff)[0]->revision);
$page=$open(); $dom=storefrontDom($page->html());
same('Your cart changed',trim($dom->query('//*[@data-domains-cart-notice]//strong')->item(0)?->textContent??''),'Reload announces the changed cart even when another line was removed');
expect(str_contains($page->html(),'reload-removed-placeholder.net'),'The notice still identifies the removed domain');
same('Was $23.40',trim($dom->query('//s')->item(0)?->textContent??''),'Reload strikes the amount the customer last saw');
same('27.00',number_format((float)$page->get('total')->total,2,'.',''),'Reload shows the new price');
Illuminate\Support\Once::flush(); $page->call('checkout',['contact'=>commerceOrderContact($user),'save_account'=>false]);
same(1,App\Models\Order::count(),'One checkout accepts the price already shown on reload');
$nativeSuccessful=true; echo "Reload price notice passed.\n";
