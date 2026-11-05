<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/price-sync.php';
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
                queuedPriceSync(fn()=>Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds::class)->mountAction('sync_prices_'.$server->id)->callMountedAction(),"Couldn't sync prices");
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
// A list our cap cannot cover keeps the priced TLDs fresh. A new one waits for a day the cap covers.
$binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($a->fresh());$accepted=Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::load($binding);
$priced=array_keys(array_filter($accepted->endings,static fn(array $row)=>$row['register']!==[]));$before=count($GLOBALS['catalogueCalls']);
Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::save($binding,$accepted->revision,$accepted->endings+['info'=>['register'=>[1],'renew'=>[1],'transfer'=>[],'registry'=>true,'renewal_safe'=>true]]);
config(['domains.enom_test_sends_per_day'=>EnomTestSendLimit::usage('catalogue-uid')['day']+PriceSync::batchedCalls($accepted,$priced)]);
DB::table('domains_read_budgets')->where('server_id',$server->id)->update(['window_at'=>0]);DB::table('domains_observation_queues')->where('server_id',$server->id)->update(['catalogue_due'=>0,'catalogue_cursor'=>0]);
app(PriceSync::class)->daily($a->fresh());config(['domains.enom_test_sends_per_day'=>500]);
$read=array_values(array_unique(array_filter(array_column(array_slice($GLOBALS['catalogueCalls'],$before),'TLD'))));sort($read);sort($priced);
same($priced,$read,'Only the priced TLDs are read when the cap cannot cover the list');
echo "Price sync: daily and staff limits and provider guard refuse before the first physical send.\n";

// Whole-run failures must get one automatic scheduled retry before asking staff.
// Native account names are not unique; both alert and list failure lines must identify the account.
$duplicate=App\Models\Server::create(['name'=>$server->name,'type'=>'server','extension'=>'Domains','enabled'=>true]);
Paymenter\Extensions\Servers\Domains\Models\Account::withVerifiedBindingWrite(static fn()=>Paymenter\Extensions\Servers\Domains\Models\Account::create([
    'server_id'=>$duplicate->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic-same-name-account']));
same(2,App\Models\Server::where('name',$server->name)->count(),'Two connected accounts can share a display name');
$syncClock=new class implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public DateTimeImmutable $at;
    public function __construct() {$this->at=new DateTimeImmutable;}
    public function now():DateTimeImmutable{return $this->at;}
};
$scheduled=new PriceSync($bindings,app(Paymenter\Extensions\Servers\Domains\Services\CatalogueSync::class),$syncClock);
$queue=DB::table('domains_observation_queues')->where('server_id',$server->id);
$GLOBALS['catalogueList']=['com','net','org','info'];
foreach(['list','capacity'] as $failureKind){
    $syncClock->at=$syncClock->at->modify('+1 hour');
    $queue->update(['catalogue_due'=>0,'catalogue_cursor'=>0,'catalogue_audit'=>'[]']);
    $noticeCount=App\Models\Notification::where('user_id',$staff->id)->where('title','Price sync keeps failing')->count();
    $GLOBALS['catalogueListFailed']=$failureKind==='list';
    config(['domains.enom_test_sends_per_day'=>$failureKind==='capacity'?EnomTestSendLimit::usage('catalogue-uid')['day']:100000]);
    DB::table('domains_read_budgets')->where('server_id',$server->id)->update(['window_at'=>0]);
    // A slow first list failure and a faster daily retry must still alert on the next scheduled day.
    if($failureKind==='list'){$GLOBALS['catalogueDuring']=static function()use($syncClock):void{$syncClock->at=$syncClock->at->modify('+25 seconds');};}
    $scheduled->daily($a->fresh());
    same(null,Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncFailure($server->id),'First failure waits for automatic retry');
    same($noticeCount,App\Models\Notification::where('user_id',$staff->id)->where('title','Price sync keeps failing')->count(),'First failure does not ask staff');
    $syncClock->at=$syncClock->at->modify($failureKind==='list'?'+86380 seconds':'+1 day');
    $scheduled->daily($a->fresh());
    expect(Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncFailure($server->id)!==null,'Repeated '.$failureKind.' failure reaches the staff surface');
    same($noticeCount+1,App\Models\Notification::where('user_id',$staff->id)->where('title','Price sync keeps failing')->count(),'Repeated failure delivers a staff inbox alert');
    $alert=App\Models\Notification::where('user_id',$staff->id)->where('title','Price sync keeps failing')->latest('id')->firstOrFail();
    same(route('filament.admin.resources.domain-tlds.index'),$alert->url,'Sync failure alert opens the real TLD list');
    $accountLabel=$server->name.' (account '.$server->id.')';
    same($accountLabel.': '.Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncFailure($server->id)['cause'].'.',$alert->body,'Sync alert identifies the account even when its name is duplicated');
    same($accountLabel,Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource::syncFailures()[0]['account'],'TLD failure line identifies the same account');
    DB::table('domains_prices')->where('server_id',$server->id)->where('tld','com')->update(['observed_at'=>$syncClock->now()->format('Y-m-d H:i:s')]);
    expect(Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncFailure($server->id)!==null,'Updating one TLD cannot hide an incomplete account sync');
    $syncClock->at=$syncClock->at->modify('+1 day');$scheduled->daily($a->fresh());
    same($noticeCount+1,App\Models\Notification::where('user_id',$staff->id)->where('title','Price sync keeps failing')->count(),'Continuing failure does not repeat the staff alert');
    $GLOBALS['catalogueListFailed']=false;config(['domains.enom_test_sends_per_day'=>100000]);
    DB::table('domains_read_budgets')->where('server_id',$server->id)->update(['window_at'=>0]);
    if($failureKind==='capacity'){
        // Reuse native publication and review fixtures: a smaller batch exercises the same multi-run account path.
        app()->instance(PriceSync::class,new PriceSync($bindings,app(Paymenter\Extensions\Servers\Domains\Services\CatalogueSync::class),$syncClock,8));
        try{
            // A deferred TLD's invalid saved rule must not turn already-written batch prices into a failed action.
            $savedRule=DB::table('domains_tlds')->where('id',$tld->id)->first(['use_default_rule','pricing_mode']);
            DB::table('domains_tlds')->where('id',$tld->id)->update(['use_default_rule'=>false,'pricing_mode'=>'invalid']);
            try{$first=$settings->run($review('import'),[],$syncClock->now());}
            finally{DB::table('domains_tlds')->where('id',$tld->id)->update((array)$savedRule);}

            expect($first['deferred']!==[],'Account exceeds one manual batch');
            expect(Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncFailure($server->id)!==null,'Incomplete current costs retain the failure');
            DB::table('domains_read_budgets')->where('server_id',$server->id)->update(['window_at'=>0]);
            $second=$settings->run($review('import'),[],$syncClock->now());
            expect($second['deferred']!==[],'The final recovery batch still defers previously refreshed endings');
        }finally{app()->forgetInstance(PriceSync::class);}
    }
    else{$syncClock->at=$syncClock->at->modify('+1 day');$scheduled->daily($a->fresh());}
    same(null,Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncFailure($server->id),'A complete automatic or manual sync clears the active failure');
}
$nativeSuccessful=true;
