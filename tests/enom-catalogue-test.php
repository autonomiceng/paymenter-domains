<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-catalogue-wire.php';
require __DIR__.'/Support/enom-catalogue.php';
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimit,EnomTestSendLimitSchema,NativeCatalogue,PriceSync,TestCatalogue};
// A cost change must update both saved retail and the native price in the real daily sync.
// The installed native fixture is needed for publication, public offers and retained carts.
DB::table('domains_read_budgets')->where('server_id',$server->id)->update(['window_at'=>0]);
$syncCart=commerceCart($user);commerceAdd($syncCart,$product,placeholderComFixtureName('price-sync-placeholder.com'));
$GLOBALS['cataloguePrice']='12.0000';
DB::table('domains_observation_queues')->where('server_id',$server->id)->update(['catalogue_due'=>0,'catalogue_cursor'=>0]);
app(PriceSync::class)->daily($a->fresh());
same('15.60',Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::retail(Paymenter\Extensions\Servers\Domains\Models\Price::where(['server_id'=>$server->id,'tld'=>'com','action'=>'register','term'=>1])->sole()->retail),'Daily sync applies the saved markup to changed cost');
same('15.60',Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::retail((string)$product->plans()->sole()->prices()->sole()->price),'Daily sync keeps native checkout price equal to public retail');
same('$15.60',$front->offer('com','register',1)['price'],'Published ending still has a public offer after sync');
same(1,$syncCart->items()->count(),'Changed cost preserves a still-eligible retained cart');
// Publication failure must roll back the imported cost and retail too.
$priceRows=DB::table('domains_prices')->orderBy('id')->get()->toJson();
$nativeRows=DB::table('prices')->orderBy('id')->get()->toJson();
$failPublication=true;
App\Models\Price::saving(static function()use(&$failPublication):void{if($failPublication){throw new RuntimeException('Synthetic native publication failure');}});
$GLOBALS['cataloguePrice']='14.0000';
DB::table('domains_read_budgets')->where('server_id',$server->id)->update(['window_at'=>0]);
DB::table('domains_observation_queues')->where('server_id',$server->id)->update(['catalogue_due'=>0,'catalogue_cursor'=>0]);
app(PriceSync::class)->daily($a->fresh());$failPublication=false;
same($priceRows,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Failed native publication retains every saved cost and retail');
same($nativeRows,DB::table('prices')->orderBy('id')->get()->toJson(),'Failed native publication retains every native price');
same('$15.60',$front->offer('com','register',1)['price'],'Failed sync leaves the previous offer selling');
// The same staff path handles falling costs and partial batches without enabling other endings.
$write('acceptance',['changes'=>['net'=>['terms'=>[1],'transfer'=>false],'org'=>['terms'=>[1],'transfer'=>false]]]);
$write('tld_settings',[...$availability,'enabled'=>false,'register'=>false],$net->id);
$GLOBALS['catalogueList']=['com','net','org'];$GLOBALS['cataloguePrice']='8.0000';$GLOBALS['catalogueBadPriceEndings']=['net'];
$netPrices=DB::table('domains_prices')->where('tld','net')->orderBy('id')->get()->toJson();
DB::table('domains_read_budgets')->where('server_id',$server->id)->update(['window_at'=>0]);
$partial=$settings->run($review('import'),[]);
same(['net'],array_keys($partial['failed']),'Partial sync reports the failed ending');
same($netPrices,DB::table('domains_prices')->where('tld','net')->orderBy('id')->get()->toJson(),'Failed ending keeps all saved price bytes');
same(false,$net->fresh()->enabled,'Sync leaves a staff-disabled ending off');
$org=Paymenter\Extensions\Servers\Domains\Models\Tld::where(['server_id'=>$server->id,'tld'=>'org'])->sole();
same(false,$org->enabled,'Newly imported ending stays unpublished');
same(null,app(NativeCatalogue::class)->product($org,'register'),'Sync creates no native product for a never-published ending');
same('$10.40',$front->offer('com','register',1)['price'],'Staff sync publishes a falling cost using the saved markup');
same('10.40',Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::retail((string)$product->plans()->sole()->prices()->sole()->price),'Falling native price equals the public offer');
$GLOBALS['catalogueBadPriceEndings']=[];
$write('import',['ending'=>'net']);
same(false,$net->fresh()->enabled,'A successful sync also leaves a staff-disabled ending off');
same(0,app(NativeCatalogue::class)->product($net,'register')->plans()->sole()->prices()->count(),'Successful sync withdraws native sale prices for the disabled ending');
$syncCart->items()->delete();
// This separate cart-cleanup case starts a later read-budget window, after the shared catalogue scenarios.
DB::table('domains_read_budgets')->where('server_id',$server->id)->update(['window_at'=>0]);
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
// Exercise both sync entry points with capacity available at preflight, then exhausted at the send guard.
// The synthetic wire counts calls only at curl_exec, so a refused send cannot look like a completed read.
foreach (['daily','staff'] as $syncEntry) {
    foreach (['limit','guard'] as $refusalKind) {
        DB::table('domains_read_budgets')->where('server_id',$server->id)->update(['window_at'=>0]);
        Illuminate\Support\Facades\Cache::flush();
        config(['domains.enom_test_sends_per_day'=>100000,'domains.enom_test_sends_per_minute'=>100000]);
        $wireBefore=count($GLOBALS['catalogueCalls']);
        $countBefore=DB::table(EnomTestSendLimitSchema::SENDS)->count();
        $pricesBefore=DB::table('domains_prices')->orderBy('id')->get()->toJson();
        $guards=[];
        config(['domains.provider_send_guard'=>static function($registrar,$environment,$command)use(&$guards,$wireBefore,$refusalKind):void {
            same(['enom','test','GetTLDList'],[$registrar,$environment,$command],'Sync sends traverse the provider guard');
            same($wireBefore,count($GLOBALS['catalogueCalls']),'Provider guard runs before any sync read reaches cURL');
            $guards[]=$command;
            if($refusalKind==='guard'){throw new RuntimeException('Synthetic sync refusal');}
            $used=EnomTestSendLimit::usage('catalogue-uid')['day'];
            config(['domains.enom_test_sends_per_day'=>$used]);
        }]);
        try {
            if($syncEntry==='daily') {
                DB::table('domains_observation_queues')->where('server_id',$server->id)->update(['catalogue_due'=>0,'catalogue_cursor'=>0]);
                app(PriceSync::class)->daily($a->fresh());
            } else {
                Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds::class)
                    ->mountAction('sync_prices_'.$server->id)->callMountedAction()->assertHasNoActionErrors()->assertNotified("Couldn't sync prices");
            }
            same(['GetTLDList'],$guards,$syncEntry.' sync reaches the send guard exactly once');
            same($wireBefore,count($GLOBALS['catalogueCalls']),$syncEntry.' sync '.$refusalKind.' refusal occurs before any physical send');
            same($countBefore,DB::table(EnomTestSendLimitSchema::SENDS)->count(),$syncEntry.' refused sync records no send');
            same($pricesBefore,DB::table('domains_prices')->orderBy('id')->get()->toJson(),$syncEntry.' refused sync preserves saved prices');
        } finally {
            config(['domains.provider_send_guard'=>null,'domains.enom_test_sends_per_day'=>500]);
        }
    }
}
echo "Price sync: daily and staff limits and provider guard refuse before the first physical send.\n";
$nativeSuccessful=true;
