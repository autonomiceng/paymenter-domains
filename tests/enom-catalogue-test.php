<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-catalogue-wire.php';
require __DIR__.'/Support/enom-catalogue.php';
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Services\{NativeCatalogue,TestCatalogue};
$withdrawn=commerceCart($user);$lineIds=[];
foreach(['first','second','healthy'] as $suffix){commerceAdd($withdrawn,$product,placeholderComFixtureName('withdraw-'.$suffix.'-placeholder.com'));}
$lineIds=$withdrawn->items()->orderBy('id')->pluck('id')->all();same(3,count($lineIds),'Three eligible retained cart lines');
$mapping=app(NativeCatalogue::class);$mappingCalls=0;$withdrawLogs=[];
Log::listen(static function($event)use(&$withdrawLogs):void{if($event->message==='Domains cart eligibility failed.'){$withdrawLogs[]=$event->context;}});
app()->instance(NativeCatalogue::class,new class($mapping,$mappingCalls) {
    public function __construct(private $real,private int &$calls){}
    public function mapping($product,$plan){$this->calls++;if($this->calls===1){throw new RuntimeException('PRIVATE-cart-detail');}if($this->calls===2){throw new TypeError('PRIVATE-type-detail');}return $this->real->mapping($product,$plan);}
});
try{
    DB::transaction(function()use($binding,$withdrawn):void{TestCatalogue::withdrawCarts($binding,'com',true);same(3,$withdrawn->items()->count(),'Cart withdrawal waits for outer commit');});
    same([$lineIds[2]],$withdrawn->items()->pluck('id')->all(),'Unexpected eligibility failures withdraw only affected lines and continue');
    same([['cart_item_id'=>$lineIds[0],'exception_class'=>RuntimeException::class],['cart_item_id'=>$lineIds[1],'exception_class'=>TypeError::class]],$withdrawLogs,'Cart diagnostics contain class and item ID only');
    TestCatalogue::withdrawCarts($binding,'com',true);same([$lineIds[2]],$withdrawn->items()->pluck('id')->all(),'Healthy retained cart survives a repeated cleanup');
}finally{app()->instance(NativeCatalogue::class,$mapping);}
$nativeSuccessful=true;
