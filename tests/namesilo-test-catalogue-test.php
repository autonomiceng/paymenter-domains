<?php
// Native installed routing/publication/checkout cannot be proven by a standalone provider fake.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/namesilo-test-activation-test.php';$nativeSuccessful=false;
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Category,Currency,Setting};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,CallerRouting,Context,Edit,Intent,OfferPorts,OfferReadiness,OfferScheduler,Query,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CartAdmission,CatalogueAcceptances,InvoiceBinding,PricingRule,RuntimePorts,SellerPolicies,Storefront};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
expect(CallerRouting::supportsSales('namesilo'),'Installed catalogue routes NameSilo sales through neutral gates');
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
        'getPrices'=>['code'=>300,'com'=>['registration'=>'10.00','renew'=>'12.00','transfer'=>'11.00'],
            'net'=>['registration'=>'13.00','renew'=>'14.00','transfer'=>'15.00']],
        'getAccountBalance'=>['code'=>300,'balance'=>'123.45'],
        'checkRegisterAvailability'=>['code'=>300,'available'=>[['domain'=>$p['domains'],'price'=>'10.00','premium'=>0,'duration'=>1]]],
        default=>throw new \RuntimeException('Unexpected synthetic wire command.'),
    };
    $body=json_encode(['request'=>['operation'=>$command],'reply'=>$reply]);
    return ($o[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
$clock=new FakeClock(new \DateTimeImmutable('2026-11-03T12:00:00Z'));app()->instance(Clock::class,$clock);$now=$clock->now();
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
Category::create(['name'=>'Domains','slug'=>'domains']);
Setting::updateOrCreate(['key'=>'domains_pricing_default_rule','settingable_type'=>null,'settingable_id'=>null],
    ['type'=>'array','encrypted'=>false,'value'=>PricingRule::DEFAULT]);
auth()->login($staff);$binding=CatalogueAcceptances::binding($account->fresh());$bindings=app(AccountBindings::class);
$gate=new UpgradeGate;$gate->pause();$capture=$gate->capture();
do{$converted=$gate->batch($binding,$capture);}while(!$converted['complete']);
$gate->activate($capture);
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
$evidence=['fingerprint'=>$binding->fingerprint,'credential_revision'=>$bindings->credentialRevision($binding),'facts'=>['com'=>$proof]];
config(['domains.namesilo_test.accounts'=>[$account->stable_identity=>$evidence]]);
$scheduler->requestFacts($binding,'com');$scheduler->tick(1);
$offer=$storefront->offer('com','register',1);
same(['$13.00','$15.60'],[$offer['price'],$offer['renew']],'Installed public route prices annual registration and retained renewal');
expect($storefront->hasTransferOffers(),'NameSilo annual transfers follow the same seller and price gates');
$readiness=OfferReadiness::routed()->review($account->fresh(),['registration'=>true,'transfer'=>true]);
same(null,$readiness['missing'],'Neutral activation readiness accepts supported NameSilo prices');
$before=count($GLOBALS['nsCatalogueCalls']);
$locked=DB::transaction(fn()=>app(SellerPolicies::class)->admit([$offer['selection']],$now));
same('13.00',$locked[0]->retail,'Locked seller admission uses the same exact annual retail');
same($before,count($GLOBALS['nsCatalogueCalls']),'Local readiness and locked sale admission never send');
$checklist=\Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($account->fresh(),['label'=>'Connected','reason'=>null]);
$rows=array_column($checklist,null,'label');same('On, follows Orders',$rows['Registrations']['value'],'Checklist registrations follow the installed TEST engine');
same('On',$rows['Transfers']['value'],'Checklist accepts installed TEST transfer gate');
app(CartAdmission::class)->boot();app(InvoiceBinding::class)->boot();
$user=commerceUser();$cart=commerceCart($user);commerceAdd($cart,$offer['product'],'ns16-checkout-placeholder.com');
commerceCheckout($user,$cart);
same(1,Operation::count(),'Real native checkout binds one NameSilo order');
$op=Operation::sole();same('namesilo',$op->registrar_key,'Checkout freezes the selected NameSilo seller');
same('13.00',$op->policy['price']['retail'],'Invoice retains the admitted price');
$before=count($GLOBALS['nsCatalogueCalls']);auth()->login($staff);
config(['domains.namesilo_test.registration'=>false]);
$refuses=static function(callable $work):void {try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Expected refusal.');};
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
$nativeSuccessful=true;
