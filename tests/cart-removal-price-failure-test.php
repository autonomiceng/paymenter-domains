<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/offer-commerce.php';
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission,CartCheckoutRefresh,CartLinesRemoved,CartNotice};
extract(offerCommerceFixture());$user=commerceUser();$cart=commerceCart($user);
$line=commerceAdd($cart,$storefront->offer('com','register',3)['product'],'kept-price-placeholder.com',3);
Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
$missing=App\Models\Gateway::create(['name'=>'Synthetic missing gateway','type'=>'gateway','extension'=>'MissingFixtureGateway','enabled'=>true]);
// Exercise the extension listener with real native prices, gateway lookup and dispatch storage.
// Admission's removal result is already covered by the reload/removal checkout matrices.
$admission=new class($native,$sellers,$eligibility,$clock) extends CartAdmission {
    public function refresh():void {throw new CartLinesRemoved(['removed-price-placeholder.com']);}
};
CartCheckoutRefresh::boot($admission);
$listeners=(new ReflectionProperty(Livewire\EventBus::class,'listeners'))->getValue(app(Livewire\EventBus::class));
$mount=end($listeners['mount']);$render=end($listeners['render']);
foreach([false,true] as $repriced){
    session()->put('domains.cart_shown.'.$cart->ulid,$repriced?[$line->id=>['plan_id'=>(int)$line->plan_id,'quantity'=>(int)$line->quantity,'amount'=>'1.00']]:[]);
    $component=new App\Livewire\Cart;$escaped=null;
    try{$mount($component);}catch(Throwable $error){$escaped=$error;}
    expect($escaped===null,'Gateway failure cannot escape removal with repricing '.(int)$repriced);
    $notice=CartNotice::get($component);
    same($repriced?'Your cart changed':'Domain removed',$notice['title']??null,'Removal/repricing notice survives gateway failure');
    same(['removed-price-placeholder.com'],$notice['names']??null,'Removal notice retains the removed domain');
    same($repriced?[$line->id=>'1.00']:[],$notice['was']??null,'Combined notice retains the prior line price');
    $render($component)('<div></div>');
    $dispatches=collect(array_map(static fn($event)=>$event->serialize(),Livewire\store($component)->get('dispatched',[])));
    expect($dispatches->contains('name','cartUpdated'),'Removal still refreshes the header cart badge');
    expect(str_contains($dispatches->firstWhere('name','notify')['params'][0]['message']??'','removed-price-placeholder.com'),'Fallback notification retains the removal after gateway failure');
}
$missing->delete();$nativeSuccessful=true;echo "Removal and repricing gateway failure passed.\n";
