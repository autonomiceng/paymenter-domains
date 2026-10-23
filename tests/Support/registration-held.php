<?php
// Installed-package default authority acceptance; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/commerce.php';require __DIR__.'/native-commerce.php';require __DIR__.'/staff-panel.php';
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{Action,AdmittedOperation,Environment,Money,Query,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,NativeCatalogue,OperationClaims,RegistrationProfiles,RuntimePorts,RuntimeWork,SellerPolicies,StaffSettingsReview as Review,StaffSettingsWrites,Storefront,WriteAcceptance,WriteFeatures,WriteHeld};
PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());
$entry=new Paymenter\Extensions\Servers\Domains\Domains;$entry->boot();
same([],app(Storefront::class)->endings(),'Fresh installed catalogue exposes no registration offers');
same(0,Account::count(),'Fresh package has no activated or accepted registrar');
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
// Inject identity verification only. This is not provider/write acceptance or staff activation.
$registry=app(Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry::class);
$registry->register('namesilo',['token'],new class implements AccountVerifier {
    public function verify(Environment $environment,#[SensitiveParameter] array $credentials): string {return 'synthetic-held-account';}
},static fn()=>throw new RuntimeException('A default-held account must not read the provider'));
$server=Server::create(['name'=>'Synthetic held registrar','type'=>'server','extension'=>'Domains','enabled'=>true]);
$bindings=app(AccountBindings::class);$binding=$bindings->configure($server->id,'namesilo',Environment::Test,['token'=>'synthetic']);
$account=Account::where('server_id',$server->id)->sole();same(false,$account->processing_enabled,'Verified identity starts with processing off');
$tld=Tld::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,'tld'=>'com']);
$native=app(NativeCatalogue::class);$native->publish($tld->id,new DateTimeImmutable);
$sellers=app(SellerPolicies::class);$sellers->initialize('com');
$product=$native->product($tld,'register');same(null,$product->stock,'Fresh withdrawn registration product has unlimited stock');
same([],app(Storefront::class)->endings(),'Configured identity alone publishes no offer');
commerceReject(fn()=>app(Storefront::class)->offer('com','register',1));
commerceReject(fn()=>CatalogueAcceptances::load($binding));
// A guessed native product/plan and forged positive stock/price cannot admit checkout.
$stockRefused=false;try{$product->update(['stock'=>10]);}catch(Illuminate\Validation\ValidationException $e){$stockRefused=true;expect(isset($e->errors()['stock']),'Installed finite stock refusal names the staff field');}
expect($stockRefused,'Installed package refuses finite managed stock');
DB::table('products')->where('id',$product->id)->update(['stock'=>10]);$product->refresh();$plan=$product->plans()->sole();$plan->prices()->updateOrCreate(['currency_code'=>'USD'],['price'=>'20.00','setup_fee'=>'0.00']);
$user=commerceUser();$cart=commerceCart($user);
commerceReject(fn()=>commerceAdd($cart,$product,'default-held.com'));
App\Models\CartItem::withoutEvents(fn()=>commerceAdd($cart,$product,'default-held.com'));
same(1,$cart->items()->count(),'Adversarial cart contains a real native line');
// Native checkout saves forged finite stock before Order Creating; its generic error still rolls back.
commerceCheckout($user,$cart)->assertDispatched('notify',fn($event,$params)=>$params[0]['message']==='An error occurred while processing your order. Please try again later.');
same(0,App\Models\Order::count(),'Finite corruption cannot create an order');same(10,$product->fresh()->stock,'Native finite save rolled back');
DB::table('products')->where('id',$product->id)->update(['stock'=>null]);
commerceCheckout($user,$cart)->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>'Your domain offer changed or expired. Remove this line from your cart and search again for a current offer.','type'=>'error']);
same(0,App\Models\Order::count(),'Held checkout rolls back the native order');same(0,DB::transactionLevel(),'Held checkout closes its transaction');
same(0,Operation::count(),'Held checkout creates no registration');
same(0,App\Models\Service::count(),'Held checkout creates no service');same(0,App\Models\Invoice::count(),'Held checkout creates no debt');
// The existing staff review is explicit and audited; acceptance does not implicitly activate writes.
$staff=staffUser(array_keys(Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()));
$review=static fn($action,$tldId)=>new Review($staff->id,$server->id,$tldId,$action,Review::ticket($staff->id,$server->id,$tldId,$action,Review::stamp($server->id,$tldId)),'Review synthetic held account');
$settings=new StaffSettingsWrites($native,$sellers,$bindings);
commerceReject(fn()=>$settings->run($review('acceptance',null),['ending'=>'com','accept'=>true,'verified'=>false,'terms'=>[1],'registry'=>true]));
$settings->run($review('acceptance',null),['ending'=>'com','accept'=>true,'verified'=>true,'terms'=>[1],'registry'=>true]);
expect(count(Review::historyRows($server->fresh()))>0,'Explicit acceptance recorded in staff audit');
commerceReject(fn()=>$bindings->setProcessing($server->id,true));
commerceReject(fn()=>$settings->run($review('processing',null),['enabled'=>true,'confirmed'=>true]));
same(false,$account->fresh()->processing_enabled,'Staff acceptance without reviewed runtime/shared workers cannot enable spending');
same([],app(Storefront::class)->endings(),'Explicit catalogue review alone leaves offers held');
// A queued row represents an adversarial/backfilled job, never a successful checkout.
$holding=Domain::create(['service_id'=>9001,'user_id'=>$user->id,'server_id'=>$server->id,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,
    'domain'=>'queued-held.com','sld'=>'queued-held','tld'=>'com','status'=>'registration_pending','provenance'=>'purchased']);
$op=Operation::create(['domain_id'=>$holding->id,'service_id'=>9001,'server_id'=>$server->id,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,
    'authority_generation'=>$holding->authority_generation,'operation_key'=>'held-package-operation','action'=>'register','term'=>1,'state'=>'queued','currency'=>'USD','amount'=>'20.00']);
$before=$op->fresh()->getRawOriginal();Queue::fake();$ports=app(RuntimePorts::class);expect(!$ports->accepted($account),'No default runtime provider factory');
app(RuntimeWork::class)->operation($op->id);same($before,$op->fresh()->getRawOriginal(),'Runtime does not dispatch or claim queued registration');
commerceReject(fn()=>$ports->operation($op));Queue::assertNothingPushed();
// Hit the real writer entry with an adversarial descriptor: the default feature must refuse first.
$features=new WriteFeatures;$exchange=static fn()=>throw new RuntimeException('Default features must precede all wire calls');
$profiles=new RegistrationProfiles($binding,app(OperationClaims::class),app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class),$exchange);
$protocol=new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration($binding,$profiles,$exchange);
$writer=new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Writer($binding,$features,new WriteAcceptance($binding),static fn()=>throw new RuntimeException('Default features must precede engine resolution'),$protocol);
$admitted=new AdmittedOperation($binding,new Query('queued-held.com',Action::Register,1),'operation','claim',1,'funding',str_repeat('a',64),new Money('6.00','USD'));
try{$writer->register($admitted,new RequestContext($binding,RequestOrigin::Background,new DateTimeImmutable('+10 seconds'),new RequestBudget(1)));expect(false,'Default registration must refuse');}
catch(WriteHeld $held){same('write_disabled',$held->reason,'Default registration feature explicitly disabled');}
foreach([Action::Transfer,Action::Renew] as $action){try{$features->requireEnabled($action);expect(false,'Independent feature disabled');}catch(WriteHeld $held){same('write_disabled',$held->reason,'Independent default-off feature');}}
(new WriteFeatures(registration:true))->requireEnabled(Action::Register);
echo "Fresh installed registration: catalogue/checkout/queued runtime/real writer held; explicit review audited; no provider calls.\n";
