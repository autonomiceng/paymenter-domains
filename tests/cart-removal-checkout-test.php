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
$nativeSuccessful=true;echo "First checkout after removal passed.\n";
