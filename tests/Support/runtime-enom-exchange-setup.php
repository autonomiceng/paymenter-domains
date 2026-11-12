<?php
// Fresh ZIP authenticated exchange; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/enom-wire.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,Money,QuoteEvidence,RequestBudget,RequestContext,RequestOrigin,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, OperationClaims, RegistrarRegistry, RenewalAcceptance, RuntimePorts, RuntimeWork, TransferInputs, Refunds, WriteAcceptance, WriteFeatures, NativeCatalogue, PriceCatalogue, SellerPolicies, CartAdmission, CheckoutEligibility, InvoiceBinding, TransferPrecheck, Rdap};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
// Exercise the TEST Purchase scope used by the deployment composition. The
// direct fixture below supplies its own audited writer and acceptance.
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,
    'domains.enom_writes.registration'=>true,'domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>100000]);
$account=Account::where('server_id',$tlds[0]->server_id)->sole();$binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);
$identityBytes=$account->getRawOriginal('stable_identity');$changed=clone $account;$changed->stable_identity='other-account';$otherIdentityBytes=$changed->getAttributes()['stable_identity'];
$state=(object)['query'=>null,'reads'=>0];
$precheck=new TransferPrecheck(new Rdap(static fn($url)=>['status'=>200,'body'=>json_encode(str_contains($url,'dns.json')?
    ['services'=>[[['com'],['https://rdap.example.com/']]]]:['objectClassName'=>'domain','ldhName'=>$state->query->name,'status'=>['active'],
    'events'=>[['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-01-01T00:00:00Z']]])]));
$registry=new RegistrarRegistry;
$verify=new class implements AccountVerifier {public function verify(Environment $e,array $c):string{return $c['uid'];}};
$reader=static function($b)use($clock,$state){
    $state->reads++;$quote=new QuoteEvidence($state->query,new Money($state->query->action===\Paymenter\Extensions\Servers\Domains\Data\Action::Transfer?'7.00':'6.00','USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds'));
    return new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(quote:$quote,balance:new Money('100.00','USD'),available:true,premium:false)));
};
$registry->register('enom',['uid','token'],$verify,$reader,null,false,$reader);
$auth=new AccountBindings($registry);$auth->configure($binding->serverId,'enom',Environment::Test,['uid'=>'account-enom','token'=>'SYNTHETIC-WIRE-TOKEN']);
require_once __DIR__.'/installed-module-routing.php';
[$prices,$native,$sellers]=installedModuleRouting($auth,$clock,$ports,activeFixtureClock($sellers),$precheck);
expect(\Paymenter\Extensions\Servers\Domains\Services\EnomProtocol::accepts($binding,\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding)),'Exchange uses the compiled environment route');
Paymenter\Extensions\Servers\Domains\Services\BalanceReads::record($binding,new Money('100.00','USD'),$clock->now(),'manual');
Illuminate\Support\Facades\Queue::fake();
$user=commerceUser();$user->update(['email'=>'acquisition-owner@example.com']);
foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
App\Models\Server::find($binding->serverId)->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);
$features=new WriteFeatures(registration:true,transfer:true);$accepted=new WriteAcceptance($binding,true,true,25,catalogue: $GLOBALS['caps']??null);$renewalAccepted=new RenewalAcceptance($binding);
$factory=$auth->enomRuntimeFactory($binding,$clock,$features,$accepted,$renewalAccepted,$precheck);
$before=Account::find($account->id)->getRawOriginal();DB::enableQueryLog();DB::flushQueryLog();$composition=$factory();same([],DB::getQueryLog(),'Factory performs no database reads');DB::disableQueryLog();
same($before,$account->fresh()->getRawOriginal(),'Pure factory changes no account bytes');expect(!$account->fresh()->processing_enabled,'Factory leaves rotated processing off');
foreach(['acquisition','renewal_writer','management_protocol'] as $role){expect(str_starts_with((new ReflectionClass($composition[$role]))->getFileName(),base_path('extensions/Servers/Domains/')),'Installed Enom composition');}
// A corrupt encrypted setting must not be read while constructing ports or staff readiness.
$setting=App\Models\Server::find($binding->serverId)->settings()->where('key','domains_credentials')->sole();$raw=$setting->getRawOriginal('value');
DB::table('settings')->where('id',$setting->id)->update(['value'=>'invalid-ciphertext']);$factory();
$ports=installedRuntimePorts();$ports->register($binding->fingerprint,$factory);expect($ports->accepted($account),'Pure readiness needs no plaintext');
DB::table('settings')->where('id',$setting->id)->update(['value'=>$raw]);
$auth->setProcessing($binding->serverId,true,static fn()=>null);activeFixtureRepublish($sellers,$tlds[0]);
app()->instance(RuntimePorts::class,$ports);$limiter=new AccountReadBudget($clock,48);app()->instance(ReadLimiter::class,$limiter);
$inputs=new TransferInputs($clock,new Refunds($clock));
$new=static function(string $name,string $action='register')use($user,$native,$tlds,$clock,$state,$inputs):Operation {
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $state->query=new Paymenter\Extensions\Servers\Domains\Data\Query($name,Paymenter\Extensions\Servers\Domains\Data\Action::from($action),1);
    $cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],$action),$name);commerceCheckout($user,$cart)->assertHasNoErrors();
    $op=Domain::where('domain',$name)->sole()->operations()->sole();DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
    App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
    if($action==='transfer'){$inputs->paymentAccepted($op->id);$r=Illuminate\Http\Request::create('/internal-transfer-input','POST',['transfer_code'=>'SYNTHETIC-EPP']);$r->setUserResolver(fn()=>$user);$inputs->submitCode($r,$op->id);}
    $state->query=OperationClaims::query($op,$op->domain);return $op->fresh();
};
$worker=new RuntimeWork($clock,$ports);
$reply=static function($p):string {
    same(0,DB::transactionLevel(),'Authenticated wire outside locks');same('account-enom',$p['UID'],'Current bound UID');same('SYNTHETIC-WIRE-TOKEN',$p['PW'],'Current decrypted token');
    $body=match($p['Command']){
        'Purchase'=>'<RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate>',
        'GetDNS'=>'<dns>ns1.provider.example</dns><dns>ns2.provider.example</dns>',
        default=>'<transferorder><transferorderid>7001</transferorderid></transferorder>',
    };
    return '<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
echo "Installed exchange composition ready.\n";
