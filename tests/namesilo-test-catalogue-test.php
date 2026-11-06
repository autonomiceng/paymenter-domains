<?php
// Native installed routing/publication/checkout cannot be proven by a standalone provider fake.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/namesilo-test-activation-test.php';$nativeSuccessful=false;
require __DIR__.'/Support/native-commerce.php';
// Checklist and checkout both render the extension's scoped native views.
app('view')->addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
require_once __DIR__.'/Support/FakeRegistrar.php';
use App\Models\{Category,Currency,Setting};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Capabilities,CallerPorts,CallerRouting,Context,Edit,Intent,OfferPorts,OfferReadiness,OfferScheduler,Query,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CartAdmission,InvoiceBinding,PricingRule,RuntimePorts,SellerPolicies,Storefront};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
expect(CallerRouting::supportsSales('namesilo'),'Installed catalogue routes NameSilo sales through neutral gates');
\Illuminate\Support\Facades\Schema::create('cache',static function($t):void {
    $t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');
});
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($h,array $options): bool {$GLOBALS['nsCatalogueOptions']=array_replace($GLOBALS['nsCatalogueOptions']??[],$options);return true;}
function curl_getinfo($h,int $option): int {return 200;}
function curl_close($h): void {}
function curl_exec($h): bool {
    same(0,DB::transactionLevel(),'All installed catalogue and checkout reads stay outside locks');
    $o=$GLOBALS['nsCatalogueOptions'];$url=parse_url($o[CURLOPT_URL]);parse_str($url['query'],$p);$command=basename($url['path']);
    same('ote.namesilo.com',$url['host'],'Catalogue and checkout use only OTE');
    $GLOBALS['nsCatalogueCalls'][]=$command;
    $reply=match($command){
        'getPrices'=>['code'=>300,'com'=>$GLOBALS['nsCatalogueRow']??['registration'=>'10.00','renew'=>'12.00','transfer'=>'11.00'],
            'net'=>['registration'=>'13.00','renew'=>'14.00','transfer'=>'15.00']],
        'getAccountBalance'=>['code'=>300,'balance'=>'123.45'],
        'checkRegisterAvailability'=>['code'=>300,'available'=>[['domain'=>$p['domains'],'price'=>'10.00','premium'=>0,'duration'=>1]]],
        default=>throw new \RuntimeException('Unexpected synthetic wire command.'),
    };
    $body=json_encode(['request'=>['operation'=>$command],'reply'=>['detail'=>'success']+$reply]);
    $GLOBALS['nsCatalogueClock']->instant=$GLOBALS['nsCatalogueClock']->now()->modify('+1 second');
    return ($o[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
$clock=new FakeClock(new \DateTimeImmutable('2026-11-03T12:00:00Z'));app()->instance(Clock::class,$clock);$now=$clock->now();$GLOBALS['nsCatalogueClock']=$clock;
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
Category::create(['name'=>'Domains','slug'=>'domains']);
Setting::updateOrCreate(['key'=>'domains_pricing_default_rule','settingable_type'=>null,'settingable_id'=>null],
    ['type'=>'array','encrypted'=>false,'value'=>PricingRule::DEFAULT]);
auth()->login($staff);$binding=AccountBindings::binding($account->fresh());$bindings=app(AccountBindings::class);
same('active',UpgradeGate::routeState()['state'],'Real fresh installation has already activated current catalogue');
// This commerce fixture needs a reviewed renewal path, including both billing switches.
$writes->run($review('test_renewal'),['accept'=>true,'verified'=>true]);
$ports=CallerPorts::routed();$scheduler=OfferScheduler::routed();$storefront=app(Storefront::class);
$offerPorts=new OfferPorts($bindings,$clock);same(25,$offerPorts->allowance($binding,RequestOrigin::Background),'NameSilo TEST shares catalogue headroom');
$scheduler->sync($binding);$scheduler->tick(1);
same(['getPrices'],$GLOBALS['nsCatalogueCalls'],'One installed account feed discovers both TLDs');
same(2,Tld::where('server_id',$binding->serverId)->count(),'Both priced suffixes are retained');
$revision=static fn()=>$ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0]->revision;
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:1,transfers:true)),$revision());
$ports->apply(Edit::seller($binding,'com',['register','transfer']),$revision());
same([],$storefront->endings(),'Account prices alone cannot authorize a sale');
$proof=['terms'=>['register'=>[1],'renew'=>[1],'transfer'=>[1]],'registry_fields'=>[],
    'renewal_safe'=>true,'requires_nameservers'=>false,'auto_renew_only'=>false,
    'observed_at'=>$now->format(DATE_ATOM),'fresh_until'=>$now->modify('+1 day')->format(DATE_ATOM)];
// Native routing is needed to prove stored protocol equality and same-date refresh recovery.
$proof['term_evidence']=['format'=>1,'entries'=>[]];
$proof['renewal_families']=['format'=>1,'entries'=>[]];
$evidence=['fingerprint'=>$binding->fingerprint,'credential_revision'=>$bindings->credentialRevision($binding),'facts'=>['com'=>$proof]];
config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$evidence]]);
$refuses=static function(callable $work):void {try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Expected refusal.');};
foreach([
    ['facts.com.terms.register',[1,2]],
    ['facts.com.terms',['register'=>[1],'renew'=>[1]]],
    ['facts.com.terms',['register'=>[1],'renew'=>[1],'transfer'=>[1],'delete'=>[]]],
    ['facts.com.terms',['register'=>[1],'renew'=>[1],'delete'=>[1]]],['facts.com.observed_at',$now->modify('+1 hour')->format(DATE_ATOM)],
    ['facts.com.fresh_until',$now->format(DATE_ATOM)],['facts.com.registry_fields',null],
    ['credential_revision','wrong-revision'],['fingerprint','wrong-binding'],
] as [$key,$value]){
    $broken=$evidence;\Illuminate\Support\Arr::set($broken,$key,$value);
    config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$broken]]);
    $refuses(fn()=>TestCatalogueFacts::read($binding,$evidence['credential_revision'],'com',$clock));
}
// Deployment configuration is a keyed map: insertion order carries no action authority.
foreach([
    ['register','renew','transfer'],['register','transfer','renew'],['renew','register','transfer'],
    ['renew','transfer','register'],['transfer','register','renew'],['transfer','renew','register'],
] as $actions){
    $reordered=$evidence;$reordered['facts']['com']['terms']=array_fill_keys($actions,[1]);
    config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$reordered]]);
    $facts=TestCatalogueFacts::read($binding,$evidence['credential_revision'],'com',$clock);
    foreach(['register','renew','transfer'] as $action){same([1],$facts->terms[$action],'TEST term keys accept any insertion order');}
}
config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$evidence]]);

$scheduler->requestFacts($binding,'com');$scheduler->tick(1);
$storedFacts=static fn()=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::facts(Tld::where('server_id',$binding->serverId)->where('tld','com')->sole()->catalogue_facts);
same($proof['term_evidence'],$storedFacts()->expansion['term_evidence']??null,'Facts writer preserves TEST expansion evidence');
expect(TestCatalogueFacts::current($binding,'com',$storedFacts(),$clock),'Stored expansion passes current TEST protocol equality');
// A changed annual feed reconstructs facts as well; optional evidence must survive it.
$cost=new \Paymenter\Extensions\Servers\Domains\Catalogue\Cost('renew',1,true,'12.0000',\Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,
    'namesilo_account_annual',$clock->now(),$clock->now()->modify('+1 day'));
$ports->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding,'com',[$cost]),$revision());
expect(TestCatalogueFacts::current($binding,'com',$storedFacts(),$clock),'Changed price feed preserves expansion and protocol acceptance');
// Reproduce a row stripped by the previous writer, retaining the protocol's immutable observation date.
$stripped=json_decode(Tld::where('server_id',$binding->serverId)->where('tld','com')->sole()->catalogue_facts,true);
unset($stripped['term_evidence'],$stripped['renewal_families']);
DB::table('domains_tlds')->where('server_id',$binding->serverId)->where('tld','com')->update(['catalogue_facts'=>json_encode($stripped)]);
expect(!TestCatalogueFacts::current($binding,'com',$storedFacts(),$clock),'Previously stripped facts fail current protocol equality');
$scheduler->requestFacts($binding,'com');$scheduler->tick(1);
expect(TestCatalogueFacts::current($binding,'com',$storedFacts(),$clock),'Established refresh repairs stripped facts with the same observation date');
$offer=$storefront->offer('com','register',1);
same(['$13.00','$15.60'],[$offer['price'],$offer['renew']],'Installed public route prices annual registration and retained renewal');
expect($storefront->hasTransferOffers(),'NameSilo annual transfers follow the same seller and price gates');
$readiness=OfferReadiness::routed()->review($account->fresh(),['registration'=>true,'transfer'=>true]);
same(null,$readiness['missing'],'Neutral activation readiness accepts supported NameSilo prices');
$capabilities=Capabilities::routed();
same([1],$capabilities->requireOrder($binding,'com','register',1),'Current TEST evidence admits orders');
expect($capabilities->requireRenewal($binding,'com')->renewalSafe,'Current TEST evidence admits renewals');
same(['com'],$capabilities->endings($binding),'Current TEST evidence lists the supported ending');
$saleFailures=[];
$saleRefuses=static function(callable $work,string $label)use(&$saleFailures):void {
    try{$work();}catch(\DomainException){return;}$saleFailures[]=$label;
};
$capabilityRefuses=static function(string $label)use($capabilities,$binding,$saleRefuses,&$saleFailures):void {
    foreach(['register','transfer'] as $action){$saleRefuses(fn()=>$capabilities->requireOrder($binding,'com',$action,1),$label.' refuses '.$action);}
    $saleRefuses(fn()=>$capabilities->requireRenewal($binding,'com'),$label.' refuses renewal');
    if($capabilities->endings($binding)!==[]){$saleFailures[]=$label.' excludes unsupported endings';}
};
$expired=$evidence;$expired['facts']['com']['fresh_until']=$clock->now()->format(DATE_ATOM);
config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$expired]]);
$capabilityRefuses('Expired TEST evidence with a fresh stored observation');
config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$evidence]]);
$before=count($GLOBALS['nsCatalogueCalls']);
$saved=Tld::where('server_id',$binding->serverId)->where('tld','com')->sole()->catalogue_facts;
$withdrawn=$evidence;unset($withdrawn['facts']['com']);
config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$withdrawn]]);
$refuses(fn()=>TestCatalogueFacts::read($binding,$evidence['credential_revision'],'com',$clock));
$capabilityRefuses('Withdrawn TEST evidence');
$saleRefuses(fn()=>$storefront->offer('com','register',1),'Withdrawn TLD evidence must refuse public offers');
$saleRefuses(fn()=>DB::transaction(fn()=>app(SellerPolicies::class)->admit([$offer['selection']],$clock->now())),
    'Withdrawn TLD evidence must refuse locked admission');
same($before,count($GLOBALS['nsCatalogueCalls']),'Withdrawn TLD evidence refusal never sends');
same($saved,Tld::where('server_id',$binding->serverId)->where('tld','com')->sole()->catalogue_facts,
    'Withdrawing evidence preserves the original cached observation');
config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$evidence]]);
$before=count($GLOBALS['nsCatalogueCalls']);
$locked=DB::transaction(fn()=>app(SellerPolicies::class)->admit([$offer['selection']],$now));
same('13.00',$locked[0]->retail,'Locked seller admission uses the same exact annual retail');
same($before,count($GLOBALS['nsCatalogueCalls']),'Local readiness and locked sale admission never send');
$checklist=\Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($account->fresh(),['label'=>'Connected','reason'=>null]);
$rows=array_column($checklist,null,'label');same('On, follows Orders',$rows['Registrations']['value'],'Checklist registrations follow the installed TEST engine');
same('On',$rows['Transfers (Experimental)']['value'],'Checklist accepts installed TEST transfer gate');
$summary=\Paymenter\Extensions\Servers\Domains\Services\EnomLiveActivation::summary($account->fresh(),['registration'=>true,'renewal'=>true,'management'=>true,'transfer'=>true]);
same([1,1,1],array_values($summary['counts']),'Shared activation summary reads each retained NS16 action');
same(1,$summary['tlds'],'Shared activation summary counts the offered TLD once');
same('Cost + 30%',$rows['Customer pays']['value'],'Shared pricing summary uses the current default rule');
same('Used by 1 TLD',$rows['Customer pays']['cause'],'Shared pricing summary counts retained catalogue defaults');
same('.com',$rows['What you sell']['value'],'Checklist uses the current installed NS16 TLD selection');
expect(!str_contains(json_encode($offer,JSON_THROW_ON_ERROR),'experimental'),'Customer offer carries no staff Experimental label');
app(CartAdmission::class)->boot();app(InvoiceBinding::class)->boot();
$user=commerceUser();$cart=commerceCart($user);commerceAdd($cart,$offer['product'],'ns16-checkout-placeholder.com');
commerceCheckout($user,$cart);
same(1,Operation::count(),'Real native checkout binds one NameSilo order');
$op=Operation::sole();same('namesilo',$op->registrar_key,'Checkout freezes the selected NameSilo seller');
same('13.00',$op->policy['price']['retail'],'Invoice retains the admitted price');
// Real writer/projection proves an unusable feed retracts saved costs, including holder estimates.
auth()->login($staff);
$feedContext=static fn()=>new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+120 seconds'),new RequestBudget(1),$clock);
$applyFeed=static function()use($offerPorts,$binding,$feedContext,$ports,$revision):void {
    foreach($offerPorts->feed($binding)->prices($feedContext()) as $command){
        if($command->tld()==='com'){$ports->apply($command,$revision());}
    }
};
$saved=Tld::where('server_id',$binding->serverId)->where('tld','com')->sole()->catalogue_facts;
$GLOBALS['nsCatalogueRow']=['registration'=>'10.00','renew'=>'broken','transfer'=>'11.00'];
$applyFeed();
foreach(['register','renew','transfer'] as $action){
    $cost=$ports->loadBatch([new Query($binding,['com'],$action)],Context::Staff)[0][0]->observation();
    if($cost?->enabled!==false||$cost->total!==null){$saleFailures[]='Unusable row retracts saved '.$action.' cost';}
}
$saleRefuses(fn()=>$storefront->offer('com','register',1),'Unusable price row refuses registration at the old cost');
$saleRefuses(fn()=>$storefront->offer('com','transfer',1),'Unusable price row refuses transfer at the old cost');
$saleRefuses(fn()=>$offerPorts->feed($binding)->facts('com',$feedContext()),'Unusable price row refuses a cold facts refresh');
if($capabilities->cost($binding,'com')!==null){$saleFailures[]='Unusable price row removes the holder renewal estimate';}
same('15.60',$capabilities->holder($binding,'com')->retail,'Unusable price row preserves retained holder retail');
$retracted=json_decode($saved,true);$retracted['terms']['renew']=[];$retracted['terms']['transfer']=[];
same(json_encode($retracted),Tld::where('server_id',$binding->serverId)->where('tld','com')->sole()->catalogue_facts,'Price retraction removes annual support without redating facts or losing evidence');
unset($GLOBALS['nsCatalogueRow']);$applyFeed();
same('$13.00',$storefront->offer('com','register',1)['price'],'A usable row restores registration');
same('12.0000',$capabilities->cost($binding,'com')->total,'A usable row restores the holder cost estimate');
$before=count($GLOBALS['nsCatalogueCalls']);auth()->login($staff);
config(['domains.namesilo_test.registration'=>false]);
$refuses(fn()=>DB::transaction(fn()=>app(SellerPolicies::class)->admit([$offer['selection']],$now)));
config(['domains.namesilo_test.registration'=>true]);
$refuses(fn()=>$storefront->offer('com','register',2));$refuses(fn()=>$storefront->offer('net','register',1));
$saved=Tld::where('server_id',$binding->serverId)->where('tld','com')->sole()->catalogue_facts;
$clock->instant=$now->modify('+1 day +1 second');
$refuses(fn()=>$storefront->offer('com','register',1));
$ctx=new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+120 seconds'),new RequestBudget(2),$clock);
$refuses(fn()=>$offerPorts->feed($binding)->facts('com',$ctx));
same($saved,Tld::where('server_id',$binding->serverId)->where('tld','com')->sole()->catalogue_facts,'Expired protocol evidence cannot be redated by prices');
same($before,count($GLOBALS['nsCatalogueCalls']),'Withdrawal, unsupported terms and stale evidence never send');
$clock->instant=$now;
$writes->run($review('configure'),['registrar'=>'namesilo','environment'=>'test','credentials'=>['api_key'=>'synthetic-next-key']]);
$refuses(fn()=>TestCatalogueFacts::read($binding,$evidence['credential_revision'],'com',$clock));
$refuses(fn()=>DB::transaction(fn()=>app(SellerPolicies::class)->admit([$offer['selection']],$now)));
same($before,count($GLOBALS['nsCatalogueCalls']),'Rotated-key sale refusal performs no provider work');
$next=['fingerprint'=>$binding->fingerprint,'credential_revision'=>$bindings->credentialRevision($binding),'facts'=>[]];
config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$next]]);
$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true]);
$refuses(fn()=>TestCatalogueFacts::read($binding,$next['credential_revision'],'com',$clock));
$capabilityRefuses('Replacement credentials without current TLD evidence');
$rotatedSelection=$offer['selection'];
$saleRefuses(static function()use($storefront,&$rotatedSelection):void {
    $rotatedSelection=$storefront->offer('com','register',1)['selection'];
},'Replacement credential header without TLD facts must refuse public offers');
$saleRefuses(fn()=>DB::transaction(fn()=>app(SellerPolicies::class)->admit([$rotatedSelection],$clock->now())),
    'Replacement credential header without TLD facts must refuse locked admission');
same($before,count($GLOBALS['nsCatalogueCalls']),'Replacement evidence refusal performs no provider work');
same($saved,Tld::where('server_id',$binding->serverId)->where('tld','com')->sole()->catalogue_facts,
    'Rotation and replacement evidence preserve the original cached observation');
foreach($saleFailures as $failure){fwrite(STDERR,$failure.PHP_EOL);}
same([],$saleFailures,'Current TLD evidence is required by public and locked sales');
$nativeSuccessful=true;
