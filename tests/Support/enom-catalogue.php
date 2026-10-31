<?php
// Runs with source or independently installed ZIP bytes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,NativeCatalogue,RuntimePorts,SellerPolicies,StaffSettingsReview as Review,StaffSettingsWrites,Storefront,TestCatalogue};
require_once __DIR__.'/staff-tlds.php';require_once __DIR__.'/native-commerce.php';
require_once app_path('Classes/helpers.php');
$hot=storage_path('framework/domains-assets-hot');file_put_contents($hot,'http://assets.example.test');
app(Illuminate\Foundation\Vite::class)->useHotFile($hot);
$session=app('session')->driver();$session->start();app('request')->setLaravelSession($session);
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
// This fixture tests the catalogue's own default-off reads; the separate cap
// fixture owns exhaustion assertions, so keep this shared test day below cap.
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_test_sends_per_day'=>500,'domains.enom_test_sends_per_minute'=>100000]);
(new Paymenter\Extensions\Servers\Domains\Domains)->installed();(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
tldPanel();$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic TEST catalogue','type'=>'server','extension'=>'Domains','enabled'=>true]);
$settings=app(StaffSettingsWrites::class);$bindings=app(AccountBindings::class);$front=app(Storefront::class);
$review=static function(string $action,?int $tld=null)use($server,$staff):Review {
    return new Review((int)$staff->id,(int)$server->id,$tld,$action,Review::ticket((int)$staff->id,(int)$server->id,$tld,$action,Review::stamp((int)$server->id,$tld)),'Review synthetic TEST catalogue');
};
$write=static function(string $action,array $data=[],?int $tld=null)use($review,$settings):void {$settings->run($review($action,$tld),$data);};
$refuse=static function(callable $work,string $reason):void {try{$work();throw new LogicException('Refusal required');}catch(DomainException|RuntimeException|InvalidArgumentException $e){expect($e->getMessage()!=='Refusal required','Expected safe refusal');same($reason,$e->getMessage(),'Exact catalogue refusal');}};
$action=static fn(string $name)=>setupAction($name,$server->id);
$before=count($GLOBALS['catalogueCalls']);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction($action('configure'))->setActionData([
    'registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid','token'=>'catalogue-token','reason'=>'Verify synthetic TEST catalogue'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
same($before+1,count($GLOBALS['catalogueCalls']),'Configure one authenticated read');
$a=Account::where('server_id',$server->id)->sole();$binding=CatalogueAcceptances::binding($a);
$before=count($GLOBALS['catalogueCalls']);$refuse(fn()=>$bindings->reader($server->id),'Registrar reader could not be created.');same($before,count($GLOBALS['catalogueCalls']),'No acceptance means no reader or read');
$write('probe');same($before+1,count($GLOBALS['catalogueCalls']),'Probe one balance read');
$before=count($GLOBALS['catalogueCalls']);$refuse(fn()=>$write('import',['ending'=>'com']),'Catalogue acceptance is unavailable.');same($before,count($GLOBALS['catalogueCalls']),'Unaccepted import makes no call');
$accept=['changes'=>['com'=>['terms'=>[1],'transfer'=>false]]];
$write('acceptance',$accept);
$write('acceptance',['changes'=>['net'=>['terms'=>[1],'transfer'=>false]]]);
$before=count($GLOBALS['catalogueCalls']);tldRefusal(fn()=>$write('import',['ending'=>'org']),'Catalogue ending is not accepted.');same($before,count($GLOBALS['catalogueCalls']),'Unaccepted ending stops before reads');
// Daily sync is intentional; a day our cap cannot cover is skipped, even if we later raise our cap.
$skips=[];\Illuminate\Support\Facades\Log::listen(static function($event)use(&$skips):void{if($event->message==='Domains daily price sync skipped.'){$skips[]=$event->context;}});
$used=Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimit::usage((string)Account::where('server_id',$server->id)->sole()->stable_identity)['day'];config(['domains.enom_test_sends_per_day'=>$used+8]);
// The price fill for What you sell has its own daily turn, so mark it done to watch only this sync.
DB::table(Paymenter\Extensions\Servers\Domains\Services\SellTableSchema::FILLS)->insert(['server_id'=>$server->id,'account_fingerprint'=>Account::where('server_id',$server->id)->sole()->account_fingerprint,'tried_at'=>app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp()]);
$before=count($GLOBALS['catalogueCalls']);DB::table('domains_runtime_turns')->where('server_id',$server->id)->update(['due_at'=>0]);app(Paymenter\Extensions\Servers\Domains\Services\RuntimeWork::class)->tick();
same([['server_id'=>(int)$server->id,'reason'=>'call_cap','needed'=>9,'left'=>8]],$skips,'Daily sync skips and logs a day our cap cannot cover');
config(['domains.enom_test_sends_per_day'=>500]);
app(Paymenter\Extensions\Servers\Domains\Services\ObservationWork::class)->tick();same(null,DB::table('domains_observation_queues')->where('server_id',$server->id)->value('catalogue_hold'),'Manual TEST catalogue does not claim or fail scheduled refresh');
same(0,(int)DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->value('daily_due_at'),'TEST runtime leaves daily balance cadence unclaimed');
same($before,count($GLOBALS['catalogueCalls']),'A skipped daily sync makes no reads');same(0,Tld::where('server_id',$server->id)->count(),'A skipped daily sync imports nothing');
// Expired state and revoked rights refuse before any I/O; in-flight changes refuse before persistence.
$stale=$review('import');$write('default_rule',['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none']);
$before=count($GLOBALS['catalogueCalls']);$refuse(fn()=>$settings->run($stale,['ending'=>'com']),'Settings changed. Reload this page.');same($before,count($GLOBALS['catalogueCalls']),'Stale import makes no call');
$pending=$review('import');$permissions=$staff->role->permissions;
$staff->role->update(['permissions'=>array_values(array_diff($permissions,[Review::permission('import')]))]);
$refuse(fn()=>$settings->run($pending,['ending'=>'com']),'Current staff permissions required.');same($before,count($GLOBALS['catalogueCalls']),'Revoked import permission makes no call');$staff->role->update(['permissions'=>$permissions]);
$GLOBALS['catalogueDuring']=static function()use($staff,$permissions):void {$staff->role->update(['permissions'=>array_values(array_diff($permissions,[Review::permission('import')]))]);};
$refuse(fn()=>$write('import',['ending'=>'com']),'Catalogue import unavailable.');same(0,Tld::where('server_id',$server->id)->count(),'In-flight permission revocation rolls back all import rows');$staff->role->update(['permissions'=>$permissions]);
$GLOBALS['catalogueDuring']=static function()use($write):void {$write('default_rule',['mode'=>'markup_percent','markup'=>'31.00','round_up'=>'none']);};
$refuse(fn()=>$write('import',['ending'=>'com']),'Catalogue import unavailable.');same(0,Tld::where('server_id',$server->id)->count(),'In-flight settings drift rolls back import');
$GLOBALS['catalogueMissing']=true;$before=count($GLOBALS['catalogueCalls']);$refuse(fn()=>$write('import',['ending'=>'com']),'Catalogue import unavailable.');same($before+1,count($GLOBALS['catalogueCalls']),'Ending absent from provider list stops after one read');$GLOBALS['catalogueMissing']=false;
$GLOBALS['catalogueListFailed']=true;$before=count($GLOBALS['catalogueCalls']);
try{$write('import',['ending'=>'com']);expect(false,'Failed initial catalogue read must refuse import');}catch(DomainException $error){
    same('read_failed',\Paymenter\Extensions\Servers\Domains\Services\ReadFailure::code($error),'Initial catalogue transport failure retains its closed cause');
    expect(str_contains(\Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::refusal($error,'import'),\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncCause('read_failed')),'Existing import refusal exposes initial list-read failure');
}finally{$GLOBALS['catalogueListFailed']=false;}
same($before+1,count($GLOBALS['catalogueCalls']),'Failed initial list stops after one read');same(0,Tld::where('server_id',$server->id)->count(),'Failed initial list imports no rows');
$GLOBALS['catalogueBadPrice']=true;
try{$write('import',['ending'=>'com']);expect(false,'Malformed staff import must fail');}catch(DomainException $error){
    same('read_failed',\Paymenter\Extensions\Servers\Domains\Services\ReadFailure::code($error),'Staff TEST import preserves its closed adapter cause');
    expect(str_contains(\Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::refusal($error,'import'),\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::syncCause('read_failed')),'Existing import refusal exposes the closed cause');
}$GLOBALS['catalogueBadPrice']=false;
$write('default_rule',['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none']);
$before=count($GLOBALS['catalogueCalls']);
Livewire::actingAs($staff)->test(ListTlds::class)->mountAction('sync_prices_'.$server->id)
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Prices synced');
$per=['GetTLDDetails','GetExtAttributes','PE_GetProductPrice','PE_GetProductPrice'];
same(['GetTLDList',...$per,...$per],array_column(array_slice($GLOBALS['catalogueCalls'],$before),'Command'),'Account sync reads the list once, then both accepted TLDs');
$tld=Tld::where('server_id',$server->id)->where('tld','com')->sole();same(false,$tld->enabled,'Import leaves ending unpublished');
$before=count($GLOBALS['catalogueCalls']);DB::table('domains_runtime_turns')->where('server_id',$server->id)->update(['due_at'=>0]);app(Paymenter\Extensions\Servers\Domains\Services\RuntimeWork::class)->tick();
app(Paymenter\Extensions\Servers\Domains\Services\ObservationWork::class)->tick();same(null,DB::table('domains_observation_queues')->where('server_id',$server->id)->value('catalogue_hold'),'Imported TEST catalogue leaves scheduler hold clear');
same($before,count($GLOBALS['catalogueCalls']),'A skipped day is not retried after raising our cap');same(1,count($skips),'A skipped daily sync is logged once');
same(0,App\Models\Product::where('server_id',$server->id)->count(),'Runtime leaves TEST native publication to staff');
same(4,Price::where('server_id',$server->id)->count(),'Annual registration and renewal imported for both TLDs');same([],$front->endings(),'No seller after import');
$availability=['enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true];
$write('tld_settings',$availability,$tld->id);
// Saving Edit prices publishes; this TLD keeps the account default.
Livewire::actingAs($staff)->test(ViewTld::class,['record'=>$tld->id])->mountAction('override')->assertSet('mountedActions.0.data.pricing','default')
    ->setActionData(['reason'=>'Publish accepted annual TEST prices'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Prices published');
$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$tld->id);
$rows=$front->endings();same(['com'],array_column($rows,'tld'),'Only published accepted ending appears');same(true,$rows[0]['testEnvironment'],'TEST provenance retained');same('$13.00',$rows[0]['register'],'Exact retail visible');
same([1],$rows[0]['terms'],'Only accepted annual term offered');$refuse(fn()=>$front->offer('com','register',2),'Seller action or term is unavailable.');
// Unverified native credential drift closes both unlocked selection and locked seller writes.
$verified=DB::table('settings')->where('settingable_type',Server::class)->where('settingable_id',$server->id)->where('key','domains_verified_credentials');
$secret=$verified->value('value');$verified->update(['value'=>'invalid-encrypted-proof']);
$refuse(fn()=>app(SellerPolicies::class)->selection('com','register',1,new DateTimeImmutable),'Catalogue account unavailable.');
$refuse(fn()=>$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$tld->id),'Registrar configuration could not be verified.');$verified->update(['value'=>$secret]);
$before=count($GLOBALS['catalogueCalls']);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->assertSee('Mode')->assertSee('Test');Livewire::actingAs($staff)->test(ViewTld::class,['record'=>$tld->id])->assertSee('Prices by registrar account');
foreach(['pricing'=>'/domains/pricing','search'=>'/domains'] as $method=>$path){$request=Illuminate\Http\Request::create($path);$request->setLaravelSession($session);(new Paymenter\Extensions\Servers\Domains\Http\Controllers\StorefrontController($front))->$method($request)->render();}
same($before,count($GLOBALS['catalogueCalls']),'Staff, pricing and search page rendering makes zero provider calls');
same('available',$front->check('catalogue-check-placeholder.com')['status'],'Explicit search confirms ordinary availability');same($before+1,count($GLOBALS['catalogueCalls']),'Explicit search one Check');
$user=commerceUser();$cart=commerceCart($user);$product=app(NativeCatalogue::class)->product($tld->fresh(),'register');
$item=commerceAdd($cart,$product,placeholderComFixtureName('catalogue-cart-placeholder.com'));same($before+2,count($GLOBALS['catalogueCalls']),'Cart creates one fresh Check receipt');
$selection=$front->offer('com','register',1)['selection'];
$refuse(fn()=>DB::transaction(fn()=>app(SellerPolicies::class)->admit([$selection],new DateTimeImmutable)),'Registrar processing is disabled.');
$invoiceCount=App\Models\Invoice::count();$orderCount=App\Models\Order::count();$paymentCount=App\Models\InvoiceTransaction::count();commerceCheckout($user,$cart)->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>'Test domains can’t be ordered. Remove them to check out.','type'=>'error']);same($invoiceCount,App\Models\Invoice::count(),'TEST preview creates no invoice');same($orderCount,App\Models\Order::count(),'TEST preview creates no order');same($paymentCount,App\Models\InvoiceTransaction::count(),'TEST preview creates no payment');
same(0,Domain::where('server_id',$server->id)->count(),'No domain reservation');same(0,Operation::where('server_id',$server->id)->count(),'No operation');
same(false,$a->fresh()->processing_enabled,'Processing remains off');expect(!app(RuntimePorts::class)->accepted($a->fresh()),'No write composition');
// Native stock and price changes cannot be bypassed through a retained direct link.
try{$product->update(['stock'=>0]);expect(false,'Finite stock save must refuse');}catch(Illuminate\Validation\ValidationException){}
DB::table('products')->where('id',$product->id)->update(['stock'=>0]);$refuse(fn()=>$front->offer('com','register',1),'Offer unavailable.');DB::table('products')->where('id',$product->id)->update(['stock'=>null]);$product->refresh();
$nativePrice=$product->plans()->sole()->prices()->sole();$nativePrice->newQuery()->whereKey($nativePrice->id)->update(['price'=>'12.00']);$refuse(fn()=>$front->offer('com','register',1),'Offer changed.');$nativePrice->newQuery()->whereKey($nativePrice->id)->update(['price'=>'13.00']);
$before=count($GLOBALS['catalogueCalls']);$refuse(fn()=>commerceAdd($cart,$product,'example.net'),'This TLD is unavailable.');same($before,count($GLOBALS['catalogueCalls']),'Unsupported ending never reaches Check');
$write('import',['ending'=>'.NET']);$net=Tld::where('server_id',$server->id)->where('tld','net')->sole();
$write('tld_settings',$availability,$net->id);$write('publish',[],$net->id);$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$net->id);
commerceAdd($cart,app(NativeCatalogue::class)->product($net,'register'),'example.net');
commerceReject(fn()=>$write('tld_settings',$availability,$tld->id));same(2,$cart->items()->count(),'Unchanged TLD settings write nothing and preserve eligible cart lines');
$write('seller',['seller'=>'','register'=>false,'transfer'=>false],$tld->id);same(['net'],array_column($front->endings(),'tld'),'Seller withdrawal removes only its storefront ending');same(1,$cart->items()->count(),'Seller withdrawal retains another ending cart');
$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$tld->id);commerceAdd($cart,$product,placeholderComFixtureName('unpublish-cart-placeholder.com'));
$write('tld_settings',[...$availability,'enabled'=>false,'register'=>false],$tld->id);same(['net'],array_column($front->endings(),'tld'),'Unpublish immediately removes only its storefront ending');same(1,$cart->items()->count(),'Unpublish retains another ending cart');
$write('import',['ending'=>'com']);same(false,$tld->fresh()->register_enabled,'Re-import cannot restore withdrawn registration');same(false,$tld->fresh()->enabled,'Re-import cannot restore TLD publication');
$write('tld_settings',$availability,$tld->id);$write('publish',[],$tld->id);commerceAdd($cart,$product,placeholderComFixtureName('revoke-cart-placeholder.com'));
$write('acceptance',['changes'=>['com'=>null]]);same(['net'],array_column($front->endings(),'tld'),'Revocation removes only its cached storefront ending');same(1,$cart->items()->count(),'Revocation removes only its TEST cart lines');
$write('acceptance',['changes'=>['net'=>null]]);same([],$front->endings(),'Second revocation removes the last storefront ending');same(0,$cart->items()->count(),'Second revocation removes the last TEST cart line');
$before=count($GLOBALS['catalogueCalls']);$refuse(fn()=>commerceAdd($cart,$product,placeholderComFixtureName('direct-cart-placeholder.com')),'Seller action or term is unavailable.');same($before,count($GLOBALS['catalogueCalls']),'Revoked direct cart makes no call');
config(['domains.enom_catalogue.enabled'=>false]);expect(!TestCatalogue::enabled($binding),'Deployment opt-out closes preview');same([],$front->endings(),'Opt-out closes cached display');$refuse(fn()=>$bindings->reader($server->id),'Registrar reader could not be created.');
tldRefusal(fn()=>$write('import',['ending'=>'com']),'TEST catalogue reads are unavailable.');
config(['domains.enom_catalogue.enabled'=>'true']);expect(!TestCatalogue::enabled($binding),'String truthiness does not opt in');
config(['domains.enom_catalogue.enabled'=>true,'domains.enom_identity.environment'=>'live']);expect(!TestCatalogue::enabled($binding),'Live opt-in never grants TEST preview');
config(['domains.enom_identity.environment'=>'test']);
expect(!TestCatalogue::enabled(new Paymenter\Extensions\Servers\Domains\Data\AccountBinding('enom',999,Environment::Live,'live-uid')),'Retained LIVE binding cannot use TEST preview');
expect(!TestCatalogue::enabled(new Paymenter\Extensions\Servers\Domains\Data\AccountBinding('namesilo',999,Environment::Test,'synthetic')),'NameSilo stays unavailable');
$liveRegistry=new Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;config(['domains.enom_identity.environment'=>'live']);Paymenter\Extensions\Servers\Domains\Services\RegistrarFactories::bootstrap($liveRegistry);config(['domains.enom_identity.environment'=>'test']);
try{$liveRegistry->reader($binding,['uid'=>'catalogue-uid','token'=>'catalogue-token']);expect(false,'Captured LIVE factory cannot become TEST');}catch(Paymenter\Extensions\Servers\Domains\Services\IdentityRefused $e){same('identity_environment_refused',$e->reason,'Captured environment is immutable');}
$write('acceptance',$accept);$write('import',['ending'=>'com']);$write('tld_settings',$availability,$tld->id);$write('publish',[],$tld->id);
same(['com'],array_column($front->endings(),'tld'),'Restored through acceptance and import, not stale costs');
commerceAdd($cart,$product,placeholderComFixtureName('retained-optout-placeholder.com'));same(['com'],array_column($front->endings(),'tld'),'Warm published offer before opt-out');config(['domains.enom_catalogue.enabled'=>false]);same([],$front->endings(),'Opt-out hides a warm published offer immediately');app(Paymenter\Extensions\Servers\Domains\Services\ObservationWork::class)->tick();same(null,DB::table('domains_observation_queues')->where('server_id',$server->id)->value('catalogue_hold'),'Opt-out does not create a false catalogue refresh failure');
foreach([['enom',Environment::Live],['namesilo',Environment::Test]] as [$registrar,$environment]){TestCatalogue::withdrawCarts(new Paymenter\Extensions\Servers\Domains\Data\AccountBinding($registrar,(int)$server->id,$environment,'synthetic'));same(1,$cart->items()->count(),'Cart cleanup is restricted to Enom TEST bindings');}
foreach([null,'live'] as $mode){config(['domains.enom_identity.environment'=>$mode]);TestCatalogue::withdrawCarts($binding);same(1,$cart->items()->count(),'Cart cleanup leaves accounts outside TEST identity mode untouched');}config(['domains.enom_identity.environment'=>'test']);
$write('tld_settings',[...$availability,'enabled'=>false],$tld->id);same(0,$cart->items()->count(),'Staff withdrawal clears TEST carts while deployment is off');config(['domains.enom_catalogue.enabled'=>true]);same([],$front->endings(),'Re-enabling preview cannot revive an offer withdrawn while off');
$write('tld_settings',$availability,$tld->id);$write('publish',[],$tld->id);
$write('tld_settings',[...$availability,'renew'=>false],$tld->id);$write('import',['ending'=>'com']);same(false,$tld->fresh()->renew_enabled,'Re-import preserves staff withdrawal of renewal support');same([],$front->endings(),'Re-import cannot reopen sales paused through renewal support');
$write('tld_settings',$availability,$tld->id);$write('publish',[],$tld->id);
$futureClock=new class implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {public function now():DateTimeImmutable{return (new DateTimeImmutable)->modify('+2 days');}};
$future=new Storefront(app(SellerPolicies::class),app(Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::class),app(NativeCatalogue::class),$bindings,app(Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility::class),$futureClock);
$before=count($GLOBALS['catalogueCalls']);$currentOffer=$front->offer('com','register',1);$oldOffer=$future->offer('com','register',1);
same([$currentOffer['price'],$currentOffer['renew']],[$oldOffer['price'],$oldOffer['renew']],'Age alone leaves customer checkout registration and renewal retail unchanged');
same([$currentOffer['selection']->priceId,$currentOffer['selection']->priceRevision],[$oldOffer['selection']->priceId,$oldOffer['selection']->priceRevision],'Age alone keeps the selected price and revision');
same($before,count($GLOBALS['catalogueCalls']),'Old saved prices sell without provider reads');
$before=DB::table('domains_prices')->where('server_id',$server->id)->get()->toJson();$GLOBALS['catalogueBadPrice']=true;
$refuse(fn()=>$write('import',['ending'=>'com']),'Catalogue import unavailable.');same($before,DB::table('domains_prices')->where('server_id',$server->id)->get()->toJson(),'Malformed import retains all price bytes');$GLOBALS['catalogueBadPrice']=false;
$wrong=$review('probe');$wrong->local(static fn()=>null);
$clock=app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class);
$context=new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(15),$clock,Paymenter\Extensions\Servers\Domains\Services\Runtime::limiter());
same(['synced'=>0,'failed'=>['.com'],'failure_codes'=>['.com'=>'catalogue_import_failed']],app(Paymenter\Extensions\Servers\Domains\Services\CatalogueSync::class)->refresh($server->id,$context,new Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness,['com'],$wrong),'Another action review cannot authorize import');
same($before,DB::table('domains_prices')->where('server_id',$server->id)->get()->toJson(),'Wrong-action review preserves all prices');
$before=count($GLOBALS['catalogueCalls']);tldRefusal(fn()=>app(Paymenter\Extensions\Servers\Domains\Services\CatalogueSync::class)->refresh($server->id,$context,new Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness,['com']),'TEST catalogue import requires staff review.');same($before,count($GLOBALS['catalogueCalls']),'Direct unreviewed sync makes no call');
$preview=$bindings->reader($server->id);$query=new Paymenter\Extensions\Servers\Domains\Data\Query('held-placeholder.com',Paymenter\Extensions\Servers\Domains\Data\Action::Register,1);
$attempt=new Paymenter\Extensions\Servers\Domains\Data\AttemptEvidence($binding,$query,'preview:1',1,Paymenter\Extensions\Servers\Domains\Data\ExecutionRecord::Absent,Paymenter\Extensions\Servers\Domains\Data\SendState::PossiblySent,$clock->now(),$clock->now());
foreach(['balance'=>[fn()=>$preview->balance($context),'Use the separate account balance reader.'],'quote'=>[fn()=>$preview->quote($query,$context),'Preview quotes require saved catalogue prices.'],'domain'=>[fn()=>$preview->readDomain('held-placeholder.com',$context),'Domain reads require separate deployment composition.'],'renewal'=>[fn()=>$preview->renewalAssessment($query,$context),'Renewal reads require separate deployment composition.'],'reconciliation'=>[fn()=>$preview->reconcile($attempt,$context),'Reconciliation reads require separate deployment composition.']] as $kind=>[$read,$reason]){$refuse($read,$reason);same($before,count($GLOBALS['catalogueCalls']),'Preview refuses '.$kind.' without I/O');}
Auth::setUser($staff);$balanceQuery=DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id);$savedAmount=$balanceQuery->value('amount');
same('configuration_unavailable',app(Paymenter\Extensions\Servers\Domains\Services\RegistrarBalances::class)->refresh((int)$server->id),'Unreviewed balance-page refresh points to staff probe');same($savedAmount,$balanceQuery->value('amount'),'Unavailable balance composition retains cached amount');same('configuration_unavailable',$balanceQuery->value('last_error_code'),'No provider-read failure is recorded for preview refusal');same($before,count($GLOBALS['catalogueCalls']),'Balance-page refresh makes no TEST call');
$phases=array_column(Review::historyRows($server->fresh()),'phase');expect(in_array('catalogue_imported',$phases,true)&&in_array('catalogue_refused',$phases,true),'Encrypted review retains import success and refusal');
echo "Enom TEST catalogue: staff import/publication, search/cart, revocation and no checkout passed.\n";
