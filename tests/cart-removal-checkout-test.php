<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/offer-commerce.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Intent,Query};
extract(offerCommerceFixture());
$user=commerceUser();$cart=commerceCart($user);
commerceAdd($cart,$storefront->offer('com','register',3)['product'],'removed-load-placeholder.com',3);
commerceAdd($cart,$storefront->offer('com','register',1)['product'],'retained-load-placeholder.com');
// Withdraw only the three-year term; a separate account is unnecessary for this checkout boundary.
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:1,transfers:true)),offerCommerceAsStaff(static fn()=>$ports->readOffers(new Query($binding,['com'],'register',3),Context::Staff)[0]->revision));
Illuminate\Support\Once::flush();
$page=Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class);
$page->assertDispatched('notify',static fn($event,$params)=>str_contains($params[0]['message'],'removed'));
Illuminate\Support\Once::flush();$page->call('checkout',['contact'=>commerceOrderContact($user),'save_account'=>false]);
same(1,App\Models\Order::count(),'The first checkout after page-load Domain removed succeeds');
$bought=Paymenter\Extensions\Servers\Domains\Models\Operation::latest('id')->first();
same(['retained-load-placeholder.com',1],[$bought->domain->domain,(int)$bought->term],'Only the remaining domain and its term are bought');
// Native removal must notify the header on this render, including the final line and a repeated stale removal.
$cart=commerceCart($user);$first=commerceAdd($cart,$storefront->offer('com','register',1)['product'],'remove-first-placeholder.com');$last=commerceAdd($cart,$storefront->offer('com','register',1)['product'],'remove-last-placeholder.com');
Illuminate\Support\Once::flush();
$page=Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class);
Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
// The upstream badge view is empty at zero; exercise its real listener without rendering that unrelated view.
$badge=new App\Livewire\Components\Cart;$badge->mount();
same(2,$badge->cartCount,'Header starts with both cart lines');
foreach([[$first,1],[$last,0],[$last,0]]as[$line,$remaining]){
    Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
    $page->call('removeProduct',$line->id);
    expect(collect($page->effects['dispatches']??[])->contains('name','cartUpdated'),'Customer removal dispatches the header refresh before navigation');
    same($remaining,$cart->items()->count(),'Customer removal updates the cart immediately');
    // Deliver the event the browser sends to the real native badge listener, without navigating.
    Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
    $badge->onCartUpdated();same($remaining,$badge->cartCount,'Native header shows the remaining count without navigation');
}
$nativeSuccessful=true;echo "First checkout after removal passed.\n";
