<?php
// Fresh ZIP authenticated exchange; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/enom-wire.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,Money,QuoteEvidence,RequestBudget,RequestContext,RequestOrigin,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,CatalogueAcceptances,OperationClaims,RegistrarRegistry,RenewalAcceptance,RuntimePorts,RuntimeWork,TransferInputs,Refunds,WriteAcceptance,WriteFeatures,NativeCatalogue,PriceCatalogue,SellerPolicies,CartAdmission,CheckoutEligibility,InvoiceBinding,TransferPrecheck,Rdap};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
// Exercise the TEST Purchase scope used by the deployment composition. The
// direct fixture below supplies its own audited writer and acceptance.
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,
    'domains.enom_writes.registration'=>true,'domains.enom_test_daily_call_cap'=>1000]);
$account=Account::where('server_id',$tlds[0]->server_id)->sole();$binding=CatalogueAcceptances::binding($account);
$identityBytes=$account->getRawOriginal('stable_identity');$changed=clone $account;$changed->stable_identity='other-account';$otherIdentityBytes=$changed->getAttributes()['stable_identity'];
$state=(object)['query'=>null,'reads'=>0];
$precheck=new TransferPrecheck(new Rdap(static fn($url)=>['status'=>200,'body'=>json_encode(str_contains($url,'dns.json')?
    ['services'=>[[['com'],['https://rdap.example.com/']]]]:['objectClassName'=>'domain','ldhName'=>$state->query->name,'status'=>['active'],
    'events'=>[['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-01-01T00:00:00Z']]])]));
$registry=new RegistrarRegistry;
$verify=new class implements AccountVerifier {public function verify(Environment $e,array $c):string{return $c['uid'];}};
$reader=static function($b)use($clock,$state){
    $state->reads++;$quote=new QuoteEvidence($state->query,new Money('99.00','USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds'));
    return new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(quote:$quote,balance:new Money('100.00','USD'),available:true,premium:false)));
};
$registry->register('enom',['uid','token'],$verify,$reader,null,false,$reader);
$auth=new AccountBindings($registry);$auth->configure($binding->serverId,'enom',Environment::Test,['uid'=>'account-enom','token'=>'SYNTHETIC-WIRE-TOKEN']);
$sellers=new SellerPolicies($prices,$auth);
foreach([AccountBindings::class=>$auth,NativeCatalogue::class=>$native,PriceCatalogue::class=>$prices,SellerPolicies::class=>$sellers] as $type=>$instance){app()->instance($type,$instance);}
$acceptedCatalogue=CatalogueAcceptances::load($binding);
DB::transaction(static fn()=>CatalogueAcceptances::saveLocked(new Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance(
    $binding,$acceptedCatalogue->revision,$acceptedCatalogue->endings,'https://resellertest.enom.com/interface.asp',25)));
Paymenter\Extensions\Servers\Domains\Services\BalanceReads::record($binding,new Money('100.00','USD'),$clock->now(),'manual');
$admission=new CartAdmission($native,$sellers,new CheckoutEligibility($auth,$clock,$precheck),$clock);app()->instance(CartAdmission::class,$admission);
app()->instance(InvoiceBinding::class,new InvoiceBinding($admission,$native,$prices,$clock));
(new Paymenter\Extensions\Servers\Domains\Domains)->boot();Illuminate\Support\Facades\Queue::fake();
$user=commerceUser();$user->update(['email'=>'acquisition-owner@example.com']);
foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
App\Models\Server::find($binding->serverId)->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);
$features=new WriteFeatures(registration:true,transfer:true);$accepted=new WriteAcceptance($binding,true,true,25);$renewalAccepted=new RenewalAcceptance($binding);
$factory=$auth->enomRuntimeFactory($binding,$clock,$features,$accepted,$renewalAccepted,$precheck);
$before=Account::find($account->id)->getRawOriginal();DB::enableQueryLog();DB::flushQueryLog();$composition=$factory();same([],DB::getQueryLog(),'Factory performs no database reads');DB::disableQueryLog();
same($before,$account->fresh()->getRawOriginal(),'Pure factory changes no account bytes');expect(!$account->fresh()->processing_enabled,'Factory leaves rotated processing off');
foreach(['acquisition','renewal_writer','management_protocol'] as $role){expect(str_starts_with((new ReflectionClass($composition[$role]))->getFileName(),base_path('extensions/Servers/Domains/')),'Installed Enom composition');}
// A corrupt encrypted setting must not be read while constructing ports or staff readiness.
$setting=App\Models\Server::find($binding->serverId)->settings()->where('key','domains_credentials')->sole();$raw=$setting->getRawOriginal('value');
DB::table('settings')->where('id',$setting->id)->update(['value'=>'invalid-ciphertext']);$factory();
$ports=new RuntimePorts;$ports->register($binding->fingerprint,$factory);expect($ports->accepted($account),'Pure readiness needs no plaintext');
DB::table('settings')->where('id',$setting->id)->update(['value'=>$raw]);
$auth->setProcessing($binding->serverId,true,static fn()=>null);
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
    $body=$p['Command']==='Purchase'?'<RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate>':'<transferorder><transferorderid>7001</transferorderid></transferorder>';
    return '<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
echo "Installed exchange composition ready.\n";
foreach(['register','transfer','register'] as $i=>$action){
    $clock->at=$clock->at->modify('+61 seconds');Cache::flush();
    if($action==='transfer'){
        DB::transaction(static fn()=>CatalogueAcceptances::saveLocked(new Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance(
            $binding,$acceptedCatalogue->revision,$acceptedCatalogue->endings)));
        config(['domains.enom_identity.environment'=>'live','domains.enom_catalogue.enabled'=>false]);
    }
    $op=$new(placeholderComFixtureName('authenticated-'.$i.'-placeholder.com'),$action);
    if($action==='transfer'){
        DB::transaction(static fn()=>CatalogueAcceptances::saveLocked(new Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance(
            $binding,$acceptedCatalogue->revision,$acceptedCatalogue->endings,'https://resellertest.enom.com/interface.asp',25)));
    }
    config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true]);resetWire(['reply'=>$reply]);
    $seen=false;$GLOBALS['enomWire']['during']=static function()use(&$seen,$op,$worker,$auth,$binding,$clock,$limiter){
        if($seen){return;}$seen=true;
        // Reentrant workers model an overlapping delivery after sealing, before the reply.
        $worker->operation($op->id);same('running',$op->fresh()->state,'Duplicate worker cannot steal live seal');
        $context=new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+20 seconds'),new RequestBudget(1),$clock,$limiter);
        $entered=false;
        try{$context->withinRead($binding,static function()use(&$entered){$entered=true;});throw new LogicException('Expected shared physical limit');}
        catch(Paymenter\Extensions\Servers\Domains\Services\ReadDeferred){}
        expect(!$entered,'Interactive reader shares physical slot with background write');
    };
    $worker->operation($op->id);same(1,count($GLOBALS['enomWire']['forms']),'One authenticated monetary send: '.json_encode([
        'state'=>$op->fresh()->state,'safe_code'=>$op->fresh()->safe_code,'attempt'=>$op->fresh()->attempt,
        'used'=>DB::table(Paymenter\Extensions\Servers\Domains\Services\EnomTestCallSchema::TABLE)->where('day',Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::day())->value('used'),
        'config'=>[config('domains.enom_identity.environment'),config('domains.enom_catalogue.enabled'),config('domains.enom_writes.registration')],
    ]));
    same($action==='register'?'pending':'uncertain',$op->fresh()->state,'Acknowledgement never completes acquisition');same('sealed',$op->fresh()->attempt['seal'],'Actual paid operation sealed');if($action==='register'){$registered=$op->fresh();}
    $freshPorts=new RuntimePorts;$freshPorts->register($binding->fingerprint,$auth->enomRuntimeFactory($binding,$clock,$features,$accepted,$renewalAccepted,$precheck));
    Cache::flush();(new RuntimeWork($clock,$freshPorts))->operation($op->id);same(1,count($GLOBALS['enomWire']['forms']),'Reconstructed worker never replays');
    expect(DB::table('domains_read_budgets')->where('server_id',$binding->serverId)->value('used')>=1,'Physical send counted');
}
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('lost-after-send-placeholder.com'));resetWire(['timeout'=>true]);$worker->operation($op->id);
same('uncertain',$op->fresh()->state,'Timeout after send remains uncertain');same(1,count($GLOBALS['enomWire']['forms']),'Timeout sends once');
Cache::flush();$worker->operation($op->id);same(1,count($GLOBALS['enomWire']['forms']),'Timeout never replayed');
expect(!str_contains(json_encode($op->fresh()->attempt),'SYNTHETIC-WIRE-TOKEN'),'No credentials retained in outcome');
echo "Installed exchange acquisition routing passed.\n";
// Before-send capacity refusal must not consume credentials or create a financial seal.
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('capacity-held-placeholder.com'));resetWire(['reply'=>$reply]);
$token=$limiter->acquire($binding,RequestOrigin::Interactive);$worker->operation($op->id);same(0,count($GLOBALS['enomWire']['forms']),'Occupied read slot refuses write');
same('absent',$op->fresh()->attempt['seal'],'Physical refusal precedes seal');$limiter->release($binding,RequestOrigin::Interactive,$token);
// Processing paused before worker entry refuses before dispatch.
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('rotation-before-wire-placeholder.com'));resetWire(['reply'=>$reply]);
$state->query=OperationClaims::query($op,$op->domain);$auth->setProcessing($binding->serverId,false);$worker->operation($op->id);same(0,count($GLOBALS['enomWire']['forms']),'Processing off sends nothing');
// Drift after the durable seal reaches the actual private credential seam. The clock shim
// runs once at exchange entry, with real grants and the physical slot already consumed.
echo "Installed exchange capacity and pre-entry pause passed.\n";
foreach(['processing','server','binding'] as $drift){
    $auth->setProcessing($binding->serverId,true,static fn()=>null);$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('sealed-drift-'.$drift.'-placeholder.com'));
    resetWire(['reply'=>$reply,'before'=>static function()use($op,$drift,$binding,$account,$otherIdentityBytes){
        same('sealed',$op->fresh()->attempt['seal'],'Drift occurs after real seal');same(0,DB::transactionLevel(),'Drift outside financial locks');
        if($drift==='processing'){DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);}
        if($drift==='server'){DB::table((new App\Models\Server)->getTable())->where('id',$binding->serverId)->update(['enabled'=>false]);}
        if($drift==='binding'){$other=new AccountBinding('enom',$binding->serverId,Environment::Test,'other-account');DB::table('domains_accounts')->where('id',$account->id)->update(['stable_identity'=>$otherIdentityBytes,'account_fingerprint'=>$other->fingerprint]);}
    }]);
    $worker->operation($op->id);same(0,$GLOBALS['enomWire']['inits'],'Post-seal drift refused before cURL: '.$drift);same('uncertain',$op->fresh()->state,'Sealed local refusal remains uncertain');
    DB::table((new App\Models\Server)->getTable())->where('id',$binding->serverId)->update(['enabled'=>true]);
    DB::table('domains_accounts')->where('id',$account->id)->update(['stable_identity'=>$identityBytes,'account_fingerprint'=>$binding->fingerprint,'processing_enabled'=>false]);
}
echo "Installed exchange post-seal drift passed.\n";
// Test-only reflection verifies the new lazy credential seam independently of earlier engine guards.
$runtimeWire=(new ReflectionFunction($factory))->getStaticVariables()['wire'];
$secretBox=(new ReflectionProperty($runtimeWire,'credentials'))->getValue($runtimeWire);$load=$secretBox->getValue();
$seamDenied=static function(callable $call,string $why):void{try{$call();}catch(Throwable){return;}throw new LogicException('Expected lazy credential refusal: '.$why);};
$seamDenied(fn()=>$load(true),'processing');same('account-enom',$load(false)['uid'],'Paused reads load only current bound credentials');
$seamDenied(fn()=>DB::transaction(fn()=>$load(false)),'transaction');
DB::table((new App\Models\Server)->getTable())->where('id',$binding->serverId)->update(['enabled'=>false]);$seamDenied(fn()=>$load(false),'server');
DB::table((new App\Models\Server)->getTable())->where('id',$binding->serverId)->update(['enabled'=>true]);
$otherBinding=new AccountBinding('enom',$binding->serverId,Environment::Test,'other-account');
// Keep the changed binding's verification digest valid, isolating the expected-fingerprint guard.
$proof=App\Models\Server::find($binding->serverId)->settings()->where('key','domains_verified_credentials')->sole();$proofBytes=$proof->getRawOriginal('value');
$otherDigest=hash_hmac('sha256',$otherBinding->fingerprint.json_encode(['uid'=>'account-enom','token'=>'SYNTHETIC-WIRE-TOKEN'],JSON_THROW_ON_ERROR),(string)config('app.key'));
DB::table('settings')->where('id',$proof->id)->update(['value'=>Illuminate\Support\Facades\Crypt::encryptString($otherDigest)]);
DB::table('domains_accounts')->where('id',$account->id)->update(['stable_identity'=>$otherIdentityBytes,'account_fingerprint'=>$otherBinding->fingerprint]);
$seamDenied(fn()=>$load(false),'binding');DB::table('settings')->where('id',$proof->id)->update(['value'=>$proofBytes]);DB::table('domains_accounts')->where('id',$account->id)->update(['stable_identity'=>$identityBytes,'account_fingerprint'=>$binding->fingerprint]);
DB::table('settings')->where('id',$setting->id)->update(['value'=>'invalid-ciphertext']);$seamDenied(fn()=>$load(false),'encrypted configuration');
DB::table('settings')->where('id',$setting->id)->update(['value'=>$raw]);
echo "Installed exchange credential seam passed.\n";
require __DIR__.'/runtime-enom-reconciliation.php';
require __DIR__.'/runtime-enom-held.php';
echo "Installed authenticated Enom: paid action routing, shared capacity, duplicate workers, restart and timeout uncertainty passed.\n";
