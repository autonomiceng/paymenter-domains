<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Intent,Query};
use Paymenter\Extensions\Servers\Domains\Services\{CartNotice,PricingRule};
extract($fixture=offerCommerceFixture());storefrontViews();
[$secondBinding,$second]=offerCommerceSecond($fixture);
$user=commerceUser();$cart=commerceCart($user);
$product=$storefront->offer('com','register',3)['product'];
$line=commerceAdd($cart,$product,'review-lines-placeholder.com',3);
commerceAdd($cart,$second['product'],'example.net');
$open=static function()use($user,$cart){Illuminate\Support\Once::flush();return Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class);};
$open();
$publish=static function($b,string $ending,string $markup,int $term=1)use($ports):void{
    $q=new Query($b,[$ending],'register',$term);
    $rev=offerCommerceAsStaff(static fn()=>$ports->readOffers($q,Context::Staff)[0]->revision);
    $ports->apply(Edit::override($b,$ending,new Intent(sell:true,longest:$term,transfers:true),[...PricingRule::DEFAULT,'markup'=>$markup]),$rev);
};
$publish($binding,'com','50.00',3);$page=$open();
same(['23.40'],array_values(CartNotice::get($page->instance())['was']),'First review records the old .com total');
$publish($secondBinding,'net','50.00');$page=$open();
same(['23.40','6.50'],array_values(CartNotice::get($page->instance())['was']),'A second publication retains unchanged lines from the pending review');
$page=$open();
same(['23.40','6.50'],array_values(CartNotice::get($page->instance())['was']),'Reload retains both pending line changes');
// A later change to the same line strikes its latest displayed total.
$publish($binding,'com','70.00',3);$page=$open();
same(['27.00','6.50'],array_values(CartNotice::get($page->instance())['was']),'Changed lines use their latest displayed total while other review details survive');
// Native Edit keeps the line id but invalidates the previous review snapshot.
Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
App\Classes\Cart::add($product,$product->plans()->where('billing_period',1)->sole(),[],['domain'=>'review-lines-placeholder.com'],1,$line->id);
$page=$open();
same(null,CartNotice::get($page->instance()),'An edited term cannot restore the old cart review');
$nativeSuccessful=true;echo "Cart review lines passed.\n";
