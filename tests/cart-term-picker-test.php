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
// Retained unavailable terms never silently turn into the browser's first healthy choice.
$failures=[];$case=static function(string $name,Closure $body)use(&$failures):void{try{$body();echo "PASS contract: $name\n";}catch(Throwable $e){$failures[]=$name.': '.$e->getMessage();echo "FAIL contract: ".end($failures)."\n";}};
$render=static fn()=>storefrontDom($controller->edit($request,$item->id)->render());
$three=$item->product->plans()->where('billing_period',3)->sole()->prices()->where('currency_code','USD')->sole();
Illuminate\Support\Facades\DB::table('prices')->where('id',$three->id)->update(['price'=>'0.01']);
$before=[$item->fresh()->plan_id,$item->fresh()->config_options,App\Models\Order::count(),App\Models\Invoice::count(),$state->calls];
$case('explicit replacement',static function()use($render,$controller,$request,$item,$before):void{
    $x=$render();$selected=$x->query('//select[@name="term"]/option[@selected]')->item(0);
    expect($selected!==null&&$selected->getAttribute('value')===''&&$selected->hasAttribute('disabled'),'Unavailable current term has a selected disabled placeholder');
    same(1,$x->query('//select[@name="term"][@required]')->length,'An explicit replacement is required');
    $post=Illuminate\Http\Request::create('/domains/cart/'.$item->id,'POST',['domain'=>'picker-renamed-placeholder.com','term'=>'']);$post->setUserResolver($request->getUserResolver());$post->setLaravelSession($request->session());
    $post->request->set('_request_key',Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($post,'cart_edit:'.$item->id));$controller->update($post,$item->id);
    same($before,[$item->fresh()->plan_id,$item->fresh()->config_options,App\Models\Order::count(),App\Models\Invoice::count(),$GLOBALS['state']->calls],'Name-only submission cannot change a term, order or provider state');
    $post->request->set('term','1');$post->request->set('_request_key',Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($post,'cart_edit:'.$item->id));$controller->update($post,$item->id);
    same(1,(int)$item->fresh()->plan->billing_period,'Explicit healthy replacement succeeds');
    same(array_slice($before,2),[App\Models\Order::count(),App\Models\Invoice::count(),$GLOBALS['state']->calls],'Cart edit never orders, invoices or sends');
});
$eligibility=activeFixtureEligibility($bindings,$clock);
$broken=new class($sellers,$prices,$native,$bindings,$eligibility,$clock) extends Storefront{
    public bool $broken=true;public Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $offers;
    protected function catalogue():?Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers{if($this->broken){throw new RuntimeException('Synthetic private read failure');}return $this->offers;}
};$broken->offers=$offers;app()->instance(Storefront::class,$broken);
$case('read failure and retry',static function()use($broken,$render,$item):void{
    $logs=[];Illuminate\Support\Facades\Log::listen(static function($e)use(&$logs):void{if($e->message==='Domains cart term choices unavailable.'){$logs[]=$e->context;}});
    foreach(range(1,2)as$unused){$x=$render();expect(str_contains($x->evaluate('string(//body)'),'could not load the year choices'),'Read failure is a retry state');same(1,$x->query('//button[@type="submit"][@disabled]')->length,'Failed read cannot submit');}
    same([['product_id'=>(int)$item->product_id]],$logs,'Diagnostic is bounded and contains no exception or customer details');
    $cache=Illuminate\Support\Facades\Cache::getFacadeRoot();$log=Illuminate\Support\Facades\Log::getFacadeRoot();
    $mockCache=Mockery::mock();$mockCache->shouldReceive('add')->once()->andReturn(true);Illuminate\Support\Facades\Cache::swap($mockCache);
    $mockLog=Mockery::mock(Psr\Log\LoggerInterface::class);$mockLog->shouldReceive('warning')->once()->andThrow(new RuntimeException('Synthetic logger failure'));Illuminate\Support\Facades\Log::swap($mockLog);
    try{expect($broken->checkoutPlanChoices($item->product)['failed'],'Logger failure preserves the failed-read outcome');}finally{Illuminate\Support\Facades\Cache::swap($cache);Illuminate\Support\Facades\Log::swap($log);}
    $broken->broken=false;$x=$render();expect(!str_contains($x->evaluate('string(//body)'),'could not load the year choices'),'Fresh healthy read recovers');
    same(1,$x->query('//select[@name="term"]/option[@value="1"]')->length,'Healthy sibling survives');
    Illuminate\Support\Facades\DB::table('prices')->where('plan_id',$item->plan_id)->update(['price'=>'0.01']);
    $x=$render();expect(str_contains($x->evaluate('string(//body)'),'No years are currently available'),'Legitimate empty offers differ from read failure');
});
expect($failures===[],implode("\n",$failures));
$nativeSuccessful=true;echo "Native cart year choices show exact line totals.\n";
