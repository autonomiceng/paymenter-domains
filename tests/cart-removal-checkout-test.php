<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/offer-commerce.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Intent,Query};
extract($fixture=offerCommerceFixture());[$secondBinding,$second]=offerCommerceSecond($fixture);
$user=commerceUser();$cart=commerceCart($user);
commerceAdd($cart,$storefront->offer('com','register',3)['product'],'removed-load-placeholder.com',3);
commerceAdd($cart,$second['product'],'retained-load-placeholder.net');
$ports->apply(Edit::override($binding,'com',new Intent(sell:false,longest:3,transfers:true)),offerCommerceAsStaff(static fn()=>$ports->readOffers(new Query($binding,['com'],'register',3),Context::Staff)[0]->revision));
Illuminate\Support\Once::flush();
$page=Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class);
$page->assertDispatched('notify',static fn($event,$params)=>str_contains($params[0]['message'],'removed'));
Illuminate\Support\Once::flush();$page->call('checkout',['contact'=>commerceOrderContact($user),'save_account'=>false]);
same(1,App\Models\Order::count(),'The first checkout after page-load Domain removed succeeds');
same('net',Paymenter\Extensions\Servers\Domains\Models\Operation::latest('id')->first()->domain->tld,'Only the remaining domain is bought');
$nativeSuccessful=true;echo "First checkout after removal passed.\n";
