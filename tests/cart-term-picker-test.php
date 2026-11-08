<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['enom'];require __DIR__.'/Support/owned.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\CartEditController;
use Paymenter\Extensions\Servers\Domains\Services\{HolderPricing,NativePresenter,Storefront};
app()->instance(Storefront::class,activeFixtureStorefront($sellers,$prices,$native,$bindings,activeFixtureEligibility($bindings,$clock),$clock));
$cart=commerceCart($user);$item=commerceAdd($cart,$native->product($tlds[0],'register'),'picker-placeholder.com',3);
app('request')->cookies->set('cart',$cart->ulid);Illuminate\Support\Once::flush();
$request=Request::create('/domains/cart/'.$item->id,'GET');$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('request')->session());
$controller=new CartEditController($native,new NativePresenter($owners,$native,new HolderPricing($prices,$caps)));
$dom=storefrontDom($controller->edit($request,$item->id)->render());
$options=$dom->query('//select[@name="term"]/option');same(2,$options->length,'Only currently sellable year choices appear');
foreach($options as $option){
 $plan=$item->product->plans()->where('billing_period',(int)$option->getAttribute('value'))->sole();
 $probe=$item->replicate();$probe->plan_id=$plan->id;$probe->setRelation('plan',$plan);$price=$probe->price;
 expect(str_contains($option->textContent,$price->format($price->total*$item->quantity)),'Each cart edit choice names its native line total');
}
same('3',$dom->query('//select[@name="term"]/option[@selected]')->item(0)?->getAttribute('value'),'The retained term remains selected');
$nativeSuccessful=true;echo "Native cart year choices show exact line totals.\n";
