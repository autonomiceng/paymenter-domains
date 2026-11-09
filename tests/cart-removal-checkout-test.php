<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/offer-commerce.php';require __DIR__.'/Support/storefront.php';
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
// Mount the native header badge through Livewire. Its theme view prints nothing at zero, which Livewire refuses
// as a component without a root element, so this fixture wraps the same view; the native listener is unchanged.
$harness=getenv('PAYMENTER_TEST_FIXTURE').'/resources/views/domains-harness';if(!is_dir($harness)){mkdir($harness,0700,true);}
file_put_contents($harness.'/cart-badge.blade.php','<div data-cart-badge>@include(\'components.cart\')</div>');
Livewire\Livewire::listen('render',static function($component,$view)use($harness):void{if($component instanceof App\Livewire\Components\Cart){$view->setPath($harness.'/cart-badge.blade.php');}});
$header=static function(App\Models\Cart $cart)use($user){Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
    return Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Components\Cart::class);};
// Deliver cartUpdated through the badge's registered listener, as the browser does after the response.
$deliver=static function($badge,$cart,int $count,string $case):void{Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
    $badge->dispatch('cartUpdated');same($count,$badge->get('cartCount'),'Native header shows the remaining count without navigation: '.$case);
    $shown=storefrontDom($badge->html())->query('//*[@data-cart-badge]//a//div');
    same($count?[(string)$count]:[],array_map(static fn($node)=>trim($node->textContent),iterator_to_array($shown)),'Header badge prints the count, and nothing at zero: '.$case);};
$badge=$header($cart);same(2,$badge->get('cartCount'),'Header starts with both cart lines');
foreach([['ordinary',$first,1],['final line',$last,0],['repeated stale',$last,0]]as[$case,$line,$remaining]){
    Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
    $page->call('removeProduct',$line->id);
    expect(collect($page->effects['dispatches']??[])->contains('name','cartUpdated'),'Customer removal dispatches the header refresh before navigation');
    same($remaining,$cart->items()->count(),'Customer removal updates the cart immediately');
    $deliver($badge,$cart,$remaining,$case);
}
// A native remove commits the deletion before recalculating the remaining line's gateway choices.
$cart=commerceCart($user);$remove=commerceAdd($cart,$storefront->offer('com','register',1)['product'],'remove-recalc-placeholder.com');$keep=commerceAdd($cart,$storefront->offer('com','register',1)['product'],'keep-recalc-placeholder.com');
Illuminate\Support\Once::flush();$page=Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class);
$badge=$header($cart);same(2,$badge->get('cartCount'),'Header starts with both recalculation lines');
$missing=App\Models\Gateway::create(['name'=>'Synthetic missing gateway','type'=>'gateway','extension'=>'MissingFixtureGateway','enabled'=>true]);
$caughtError=null;Livewire\Livewire::listen('exception',static function($component,$error)use(&$caughtError):void{if($component::class===App\Livewire\Cart::class){$caughtError=$error;}});
$logged=[];Illuminate\Support\Facades\Log::listen(static function($event)use(&$logged):void{if($event->message==='Domains cart recalculation failed after removal.'){$logged[]=[$event->level,$event->context];}});
Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);$orders=App\Models\Order::count();
$escaped=null;try{$page->call('removeProduct',$remove->id);}catch(Throwable $error){$escaped=$error;}
expect($caughtError!==null,'The real native remove reached its post-delete failure');
expect($escaped===null,'A committed deletion still gets a successful response');
same([['warning',['exception_class'=>$caughtError::class]]],$logged,'The recalculation failure is logged once, by class only');
same(1,$cart->items()->count(),'The item deletion committed before recalculation failed');
// The browser applies only a successful response: check the effects it receives.
same(1,collect($page->effects['dispatches']??[])->where('name','cartUpdated')->count(),'The response carries exactly one cart badge update');
$html=$page->html();expect(!str_contains($html,'removeProduct('.$remove->id.')')&&str_contains($html,'removeProduct('.$keep->id.')'),'The response shows the remaining line only');
same(App\Classes\Cart::items()->sole()->price->total,$page->get('total')->total,'The response total covers the remaining line');
$deliver($badge,$cart,1,'gateway failure after a committed deletion');
same($orders,App\Models\Order::count(),'Error recovery never retries checkout');$missing->delete();
// A failed reload of the remaining lines is not a gateway failure: it keeps its error instead of an empty cart.
$cart=commerceCart($user);$remove=commerceAdd($cart,$storefront->offer('com','register',1)['product'],'remove-reload-placeholder.com');$keep=commerceAdd($cart,$storefront->offer('com','register',1)['product'],'keep-reload-placeholder.com');
Illuminate\Support\Once::flush();$page=Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class);
$badge=$header($cart);same(2,$badge->get('cartCount'),'Header starts with both reload lines');
// One shot: the first read of the named model after the committed deletion fails, as a dropped connection would.
// The error is created where it is thrown, so its trace shows which native step failed.
$inject=null;$thrown=null;App\Models\CartItem::deleted(static function()use(&$inject):void{if($inject){$inject['armed']=true;}});
foreach([App\Models\CartItem::class,App\Models\Price::class]as$model){$model::retrieved(static function($row)use(&$inject,&$thrown):void{
    if(($inject['armed']??false)&&$row instanceof $inject['model']){$thrown=new RuntimeException($inject['message']);$inject=null;throw $thrown;}});}
$frames=static fn(Throwable $error)=>array_map(static fn($frame)=>($frame['class']??'').'::'.($frame['function']??''),$error->getTrace());
$inject=['model'=>App\Models\CartItem::class,'message'=>'Synthetic remaining line reload failure','armed'=>false];
$caughtError=null;$logged=[];$escaped=null;
Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
try{$page->call('removeProduct',$remove->id);}catch(Throwable $error){$escaped=$error;}
same(null,$inject,'The reload failure fired once');same($thrown,$caughtError,'The real native remove reached the one-shot reload failure');
expect(in_array('App\\Classes\\Cart::remove',$frames($thrown),true)&&!in_array('App\\Livewire\\Cart::updateTotal',$frames($thrown),true),'The fixture failure is in the native line reload');
same($thrown,$escaped,'The reload failure is not hidden behind a successful response');
same([],$logged,'No gateway recovery is logged for a reload failure');
same([(int)$keep->id],array_map('intval',$cart->items()->pluck('id')->all()),'The item deletion committed and the other line remains');
$deliver($badge,$cart,1,'reload failure after a committed deletion');
same($orders,App\Models\Order::count(),'Reload failure never retries checkout');
// A failure after a complete reload, but outside the gateway lookup, keeps its error too.
$cart=commerceCart($user);$remove=commerceAdd($cart,$storefront->offer('com','register',1)['product'],'remove-total-placeholder.com');$keep=commerceAdd($cart,$storefront->offer('com','register',1)['product'],'keep-total-placeholder.com');
Illuminate\Support\Once::flush();$page=Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class);
$thrown=null;$inject=['model'=>App\Models\Price::class,'message'=>'Synthetic plan price read failure','armed'=>false];
$caughtError=null;$logged=[];$escaped=null;
Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
try{$page->call('removeProduct',$remove->id);}catch(Throwable $error){$escaped=$error;}
same(null,$inject,'The plan price failure fired once');same($thrown,$caughtError,'The real native remove reached the one-shot failure');
expect(in_array('App\\Livewire\\Cart::updateTotal',$frames($thrown),true)&&!in_array('App\\Helpers\\ExtensionHelper::getCheckoutGateways',$frames($thrown),true),'The fixture failure is in the native total after the reload, outside the gateway lookup');
same($thrown,$escaped,'A non-gateway failure is not hidden behind a successful response');
same([],$logged,'No gateway recovery is logged for a non-gateway failure');
same([(int)$keep->id],array_map('intval',$cart->items()->pluck('id')->all()),'The item deletion committed and the other line remains');
same($orders,App\Models\Order::count(),'A non-gateway failure never retries checkout');
$nativeSuccessful=true;echo "First checkout after removal passed.\n";
