<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Full HTTP requests are needed: Livewire::test shares an in-memory session and does not test session serialization.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Http\Request;
use Illuminate\Cookie\CookieValuePrefix;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Intent,Query};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
extract(offerCommerceFixture()); storefrontViews();
$user=commerceUser(); $product=$storefront->offer('com','register',3)['product'];
$cart=commerceCart($user); $domainLine=commerceAdd($cart,$product,'session-price-placeholder.com',3);
$hosting=commerceAdd($cart,commerceHosting(),'unused-placeholder.test');
$views=getenv('PAYMENTER_TEST_FIXTURE').'/resources/views/domains-harness';
file_put_contents($views.'/cart.blade.php','<div>@includeIf(\'domains::cart-notice\')@foreach(\App\Classes\Cart::items() as $item)@includeIf(\'domains::cart-was\',[\'item\'=>$item]){{ $item->price->format($item->price->total) }}@endforeach</div>');
$app['view']->getFinder()->flush();
// Exercise native /cart with durable sessions, encrypted browser cookies, and a fresh auth guard per request.
config(['session.driver'=>'file','session.files'=>getenv('PAYMENTER_TEST_FIXTURE').'/storage/framework/sessions']);
$app['session']->forgetDrivers();
$jar=['cart'=>$app['encrypter']->encrypt(CookieValuePrefix::create('cart',$app['encrypter']->getKey()).$cart->ulid,false)];
$app['session']->driver()->start(); auth()->login($user); session()->put('user_session',App\Models\UserSession::create(['user_id'=>$user->id,'last_activity'=>now()])->ulid); $app['session']->driver()->save();
$jar[config('session.cookie')]=$app['encrypter']->encrypt(CookieValuePrefix::create(config('session.cookie'),$app['encrypter']->getKey()).session()->getId(),false);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$get=static function()use(&$jar,$app,$kernel){
    Illuminate\Support\Once::flush(); $app['auth']->forgetGuards(); $app['session']->forgetDrivers();
    $req=Request::create('http://domains-tests.invalid/cart','GET',[], $jar);
    $res=$kernel->handle($req);
    foreach($res->headers->getCookies() as $cookie){$jar[$cookie->getName()]=$cookie->getValue();}
    $kernel->terminate($req,$res);
    same(200,$res->getStatusCode(),'Native cart request renders: '.substr(strip_tags($res->getContent()),0,160));
    return $res->getContent();
};
$html=$get(); expect(str_contains($html,'$28.60'),'Original HTTP cart shows 28.60');
$q=new Query($binding,['com'],'register',3);$rev=offerCommerceAsStaff(static fn()=>$ports->readOffers($q,Context::Staff)[0]->revision);
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:3,transfers:true),[...PricingRule::DEFAULT,'markup'=>'50.00']),$rev);
// A non-checkout Livewire update may race publication. It must not silently advance the saved shown amount.
// Use the actual signed page snapshot through Livewire's native component lifecycle.
$x=storefrontDom($html);$snapshot=json_decode($x->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot'),true);
Illuminate\Support\Once::flush();
[$updated,$effects]=app('livewire')->update($snapshot,[],[['method'=>'updateQuantity','params'=>[$hosting->id,2],'path'=>'']]);
expect(str_contains($effects['html']??'','Your cart changed'),'Editing another line cannot hide a concurrent domain price publication');
session()->save();
$html=$get();$x=storefrontDom($html);
same('Your cart changed',trim($x->query('//*[@data-domains-cart-notice]//strong')->item(0)?->textContent??''),'HTTP reload announces the changed price');
same('Was $28.60',trim($x->query('//s')->item(0)?->textContent??''),'HTTP reload strikes the total the browser last saw');
expect(str_contains($html,'$33.00'),'HTTP reload shows the new quote');
if(isset($argv[1])){require __DIR__.'/Support/demo-c-r11-cart-render.php';}
// Stale review metadata cannot be restored onto a different cart snapshot.
$shownKey='domains.cart_shown.'.$cart->ulid;
$review=session()->get($shownKey.'.review');$review['shown'][$domainLine->id]['quantity']=99;
session()->put($shownKey.'.review',$review);session()->save();
$mismatched=$get();
expect(!str_contains($mismatched,'Your cart changed'),'A mismatched saved review is not restored');
$x=storefrontDom($mismatched);
// Checkout rechecks the signed page even if another publish follows the reload.
$snapshot=json_decode($x->query('//*[@*[name()="wire:snapshot"]]')->item(0)->getAttribute('wire:snapshot'),true);
$rev=offerCommerceAsStaff(static fn()=>$ports->readOffers($q,Context::Staff)[0]->revision);
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:3,transfers:true),[...PricingRule::DEFAULT,'markup'=>'70.00']),$rev);
$calls=[['method'=>'checkout','params'=>[['contact'=>commerceOrderContact($user),'save_account'=>false]],'path'=>'']];
Illuminate\Support\Once::flush();
[$reviewed,$effects]=app('livewire')->update($snapshot,[],$calls);
same(0,App\Models\Order::count(),'Checkout cannot invoice a quote the customer has not seen');
$x=storefrontDom($effects['html']);same('Was $33.00',trim($x->query('//s')->item(0)?->textContent??''),'Checkout strikes the latest displayed quote');
expect(str_contains($effects['html'],'Your cart changed'),'Checkout requires review after a later publish');
Illuminate\Support\Once::flush();
request()->headers->set('X-Livewire','true');
app('livewire')->update($reviewed,[],$calls);
same(1,App\Models\Order::count(),'A second checkout accepts the reviewed quote exactly once');
same(null,session()->get($shownKey.'.review'),'Accepted checkout clears the saved review');
same('37.40',Paymenter\Extensions\Servers\Domains\Models\Operation::sole()->policy['price']['retail'],'Invoice freezes the reviewed quote');
$nativeSuccessful=true;echo "HTTP cart session price passed.\n";
