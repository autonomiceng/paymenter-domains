<?php
// Synthetic Enom 444f31c transfer inputs/dispatch scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/commerce.php';
require_once __DIR__.'/namesilo-account-guard.php';
require __DIR__.'/native-commerce.php';
require_once __DIR__.'/native-financial-snapshot.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, CostUnit, Disposition, DriverResult, Environment, Evidence, Money, QuoteEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CartAdmission, CheckoutEligibility, ContactProfileSchema, DomainOperations, InvoiceBinding, OperationClaims, Rdap, Refunds, RegistrarRegistry, RegistrationProfiles, TransferInputs, TransferPrecheck, TransferPreflight, WriteAcceptance, WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Tests\{FakeClock, FakeRegistrar};
if (($transferEnvironment??Environment::Test)===Environment::Live) { config(['app.url'=>'https://domains-tests.invalid']); }
extract(commerceFixture(true,environment:$transferEnvironment??Environment::Test,active:$activeCommerceFixture??true)); $clock ??= new FakeClock($now);
$guard=new Paymenter\Extensions\Servers\Domains\Services\NativeDomainGuard(new Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices),$clock); $guard->boot();
Carbon\Carbon::setTestNow($now); Carbon\CarbonImmutable::setTestNow($now); ContactProfileSchema::install(); Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$state = (object) ['query'=>null,'calls'=>[],'reads'=>0,'readers'=>[],'premium'=>false,'available'=>true,'funds'=>'100.00','mode'=>'','profile'=>[], 'id'=>100,'during'=>null,'rdap'=>0,'rdapUnknown'=>false,'rdap404'=>false,'createShape'=>'nested','enomOrigin'=>($transferEnvironment??Environment::Test)===Environment::Live?'https://reseller.enom.com/interface.asp':'https://resellertest.enom.com/interface.asp'];
foreach ($tlds as $tld) { App\Models\Server::find($tld->server_id)->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']); }
$rdap = new Rdap(static function ($url) use ($state): array {
    same(0, DB::transactionLevel(), 'RDAP outside financial locks'); $state->rdap++;
    if ($state->rdapUnknown) { throw new RuntimeException('Synthetic offline failure'); }
    if ($state->rdap404 && !str_contains($url,'dns.json')) { return ['status'=>404,'body'=>'']; }
    return ['status'=>200, 'body'=>json_encode(str_contains($url,'dns.json') ? ['services'=>[[['com'],['https://rdap.example.com/']]]]
        : ['objectClassName'=>'domain','ldhName'=>$state->query?->name ?? 'example.com','status'=>['active'],'events'=>[
            ['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-01-01T00:00:00Z']]])];
});
$checkoutTransfer=new TransferPrecheck(new Rdap(fn()=>throw new RuntimeException('Synthetic checkout lookup')));
if(isset($offers)){
    require_once __DIR__.'/offer-commerce.php';
    $eligibility=new class($bindings,$clock,$checkoutTransfer) extends CheckoutEligibility {use OfferCommerceRoute;};$eligibility->fixtureOffers=$offers;
    $admission=new class($native,$sellers,$eligibility,$clock) extends CartAdmission {use OfferCommerceRoute;};$admission->fixtureOffers=$offers;
}else{$admission = activeFixtureAdmission($native,$sellers,activeFixtureEligibility($bindings,$clock,$checkoutTransfer),$clock);}
$invoiceBinding = new InvoiceBinding($admission,$native,$prices,$clock);
app()->instance(InvoiceBinding::class,$invoiceBinding);
$admission->boot(); $invoiceBinding->boot();
$user = commerceUser(); $user->update(['email'=>'transfer-owner@example.com']);
foreach (['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value) { $user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]); }
$refunds = new Refunds($clock); $inputs = new TransferInputs($clock,$refunds);
// Recovery tests start from historical Live debt, not a new sale without Activate. TEST still exercises checkout.
$historicalTransfer = static function(string $name,int $i)use($user,$native,$prices,$tlds,$clock):Operation {
    return DB::transaction(static function()use($name,$i,$user,$native,$prices,$tlds,$clock):Operation {
        $t=$tlds[$i];$product=$native->product($t,'transfer');$plan=$native->annualPlan($product);
        $price=$prices->current($t->server_id,$t->account_fingerprint,$t->tld,'transfer',1,'USD',$clock->now());
        $renew=$prices->current($t->server_id,$t->account_fingerprint,$t->tld,'renew',1,'USD',$clock->now());
        $snapshot=static fn($p)=>[...get_object_vars($p),'observedAt'=>$p->observedAt->format(DATE_ATOM),'freshUntil'=>$p->freshUntil->format(DATE_ATOM)];
        $decimal=static fn($v)=>Paymenter\Extensions\Servers\Domains\Services\RetailLine::decimal($v);
        $order=App\Models\Order::withoutEvents(fn()=>App\Models\Order::create(['user_id'=>$user->id,'currency_code'=>'USD']));
        $service=App\Models\Service::withoutEvents(fn()=>App\Models\Service::create(['order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,
            'user_id'=>$user->id,'currency_code'=>'USD','quantity'=>1,'price'=>$decimal($renew->retail),'status'=>'pending','label'=>$name,'expires_at'=>null]));
        App\Models\Property::withoutEvents(fn()=>$service->properties()->create(['key'=>'domain','value'=>$name]));
        $invoice=App\Models\Invoice::withoutEvents(fn()=>App\Models\Invoice::create(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'pending','due_at'=>$clock->now()]));
        $item=App\Models\InvoiceItem::withoutEvents(fn()=>$invoice->items()->create(['description'=>'Historical synthetic transfer','price'=>$decimal($price->retail),'quantity'=>1,'reference_type'=>$service->getMorphClass(),'reference_id'=>$service->id]));
        $checkout=['total'=>$decimal($price->retail),'tax'=>'0.00','discount'=>'0.00','net'=>$decimal($price->retail),'name'=>$name,
            'user_id'=>(int)$user->id,'order_id'=>(int)$order->id,'service_id'=>(int)$service->id,'product_id'=>(int)$product->id,'plan_id'=>(int)$plan->id,
            'annual_plan_id'=>(int)$plan->id,'annual_price'=>$decimal($renew->retail),'invoice_id'=>(int)$invoice->id,'invoice_item_id'=>(int)$item->id,'term'=>1,'action'=>'transfer','currency'=>'USD'];
        $domain=Paymenter\Extensions\Servers\Domains\Services\DomainReservations::reserve(['service_id'=>$service->id,'user_id'=>$user->id,'domain'=>$name,
            'server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,'authority_generation'=>1,
            'status'=>'transfer_pending','snapshot'=>['checkout'=>$checkout],'renewal_policy'=>$snapshot($renew)],[$t->tld]);
        $op=Operation::create(['domain_id'=>$domain->id,'service_id'=>$service->id,'server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,
            'account_fingerprint'=>$t->account_fingerprint,'authority_generation'=>1,'invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id,'operation_key'=>'invoice-item:'.$item->id,
            'action'=>'transfer','term'=>1,'funding_basis'=>'paid_invoice','state'=>'awaiting_payment','amount'=>$decimal($price->retail),'currency'=>'USD',
            'policy'=>['checkout'=>$checkout,'price'=>$snapshot($price),'seller_revision'=>1],'cost'=>['estimated'=>$price->wholesale,'currency'=>'USD','unit'=>'term_total'],
            'payload'=>Paymenter\Extensions\Servers\Domains\Services\ContactSetup::capture($user,$t)]);
        InvoiceBinding::requireIdentity($op,$domain,$service,$invoice,$item);return $op;
    });
};
$newTransfer = static function ($name, int $i=0, bool $paid=true, bool $code=true) use ($user,$native,$sellers,$tlds,$clock,$inputs,$state,$historicalTransfer): Operation {
    $policy = DB::table('domains_sales_policies')->where('tld','com')->first();
    if ((int) $policy->server_id !== $tlds[$i]->server_id) { activeFixtureSeller($sellers,'com',$policy->revision,$tlds[$i]->server_id,true,true,$clock->now()); }
    if(Account::where('server_id',$tlds[$i]->server_id)->sole()->environment==='live'){$op=$historicalTransfer($name,$i);}
    else{$cart = commerceCart($user); commerceAdd($cart,$native->product($tlds[$i],'transfer'),$name);
        // Synthetic retained transfers can have incomplete contacts from the former checkout.
        try { \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::validate($tlds[$i]->registrar_key, \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::collect($user)); $legacyContacts=false; }
        catch (\Paymenter\Extensions\Servers\Domains\Services\ContactValidation) { $legacyContacts=true; }
        $legacyContacts?commerceLegacyCheckout($user,$cart):commerceCheckout($user,$cart);
        $op = Domain::where('domain',$name)->sole()->operations()->sole();}
    if ($paid) { App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount); $inputs->paymentAccepted($op->id); }
    if ($code) { $r = Illuminate\Http\Request::create('/internal-transfer-input','POST',['transfer_code'=>'SyntheticOnly']); $r->setUserResolver(fn()=>$user); $inputs->submitCode($r,$op->id); }
    if ($i === 1) { $op->refresh()->update(['payload'=>[...$op->payload,'nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]); }
    $state->query = OperationClaims::query($op,$op->domain); return $op->fresh();
};
$registry = new RegistrarRegistry;
$verifier = new class implements AccountVerifier, \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator {
    public function verify(Environment $e, array $c): string { return $c['token']; }
    public function authenticate(Environment $e, array $c): void { /* Synthetic connectivity only, as in commerceFixture. */ }
};
foreach (['enom','namesilo'] as $key) {
    $registry->register($key,['token'],$verifier,static function ($b,$c) use ($state,$clock) {
        same(0,DB::transactionLevel(),'Fresh reads outside locks'); $state->reads++;
        if ($state->during) { ($state->during)($state->reads); }
        $q = new QuoteEvidence($state->query,new Money($state->quote??'7.00','USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds'));
        $reader = new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,
            new Evidence(quote:$q,balance:new Money($state->funds,'USD'),available:$state->available,premium:$state->premium)));
        $state->readers[]=$reader;return $reader;
    },authenticator:$key==='namesilo'?$verifier:null);
}
$freshBindings = new AccountBindings($registry); $claims = new OperationClaims($bindings,$clock,$caps??null);
$exchange = static function ($origin,$command,$p,$timeout) use ($state): string {
    if($command==='contactList'&&$p===[]){return nameSiloDefaultContactReply();}
    same(0,DB::transactionLevel(),'Wire outside locks'); expect($timeout > 0 && $timeout <= 25000,'Bounded wire'); $state->calls[] = $command;
    $reply = ['code'=>300,'detail'=>'synthetic'];
    if ($command === 'contactAdd') { $state->profile=$p; $reply['contact_id']=(string)++$state->id; }
    elseif ($command === 'contactList') { $row=array_combine(['first_name','last_name','company','address','address2','city','state','zip','country','email','phone'],array_values($state->profile)); $reply['contact']=$row+['contact_id'=>(string)$state->id,'default_profile'=>$state->mode==='default'?'1':'0']; }
    else {
        if ($state->mode === 'lost') { throw new RuntimeException('Synthetic response loss'); }
        if ($state->mode === 'malformed') { return '{}'; }
        if ($command === 'TP_CreateOrder') {
            same($state->enomOrigin,$origin,'Fixed Enom route');
            same([1,0,1,0,'no'],[$p['DomainCount'],$p['PreConfig'],$p['Lock'],$p['Renew'],$p['UseCreditCard']],'Frozen external-transfer flags');
            expect(isset($p['AuthInfo1'],$p['RegistrantEmailAddress'],$p['AuxBillingEmailAddress']),'Frozen input and four roles mapped');
            // Documented nested ID, top-only compatibility, agreeing IDs and conflicting IDs.
            $id=['nested'=>'<transferorder><transferorderid>7001</transferorderid><statusid>5</statusid></transferorder>','top'=>'<TransferOrderID>7001</TransferOrderID>',
                'agree'=>'<transferorder><transferorderid>7001</transferorderid></transferorder><TransferOrderID>7001</TransferOrderID>',
                'conflict'=>'<transferorder><transferorderid>7001</transferorderid></transferorder><TransferOrderID>7002</TransferOrderID>'][$state->createShape];
            return '<interface-response><Command>TP_CreateOrder</Command><ErrCount>0</ErrCount><Done>true</Done>'.$id.'</interface-response>';
        }
        same('transferDomain',$command,'Only monetary transfer command'); same('https://ote.namesilo.com/apibatch',$origin,'Fixed batch route');
        same(0,$p['auto_renew'],'No automatic registrar renewal'); same(0,$p['private'],'Frozen privacy'); expect(str_starts_with($p['auth'],'base64:'),'Encoded auth');
        $reply += ['domain'=>$p['domain'],'order_amount'=>$state->quote??'7.00']; $reply['code']=in_array($state->mode,['301','302'],true)?(int)$state->mode:300;
    }
    return json_encode(['request'=>['operation'=>$command],'reply'=>$reply]);
};
$caps??=null;
$makeTransfer = static function (int $i=0, ?WriteFeatures $features=null) use ($tlds,$claims,$clock,$freshBindings,$rdap,$caps,&$exchange,&$engine,&$bridge,&$protocol,&$fresh,&$accepted,&$profiles,&$binding): void {
    $binding = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$tlds[$i]->server_id)->sole()); $features ??= new WriteFeatures(transfer:true);
    if($binding->registrarKey==='namesilo'&&!\App\Models\Server::findOrFail($binding->serverId)->settings()->where('key','domains_namesilo_expected_default_contact_email')->exists()){\App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>'domains_namesilo_expected_default_contact_email'],['value'=>'expected@example.com','type'=>'string','encrypted'=>true]);}
    $profiles = new RegistrationProfiles($binding,$claims,$clock,$exchange); $accepted = new WriteAcceptance($binding,true,true,25,$profiles,catalogue:$caps??null);
    $fresh = new TransferPreflight($freshBindings,$features,$accepted,new TransferPrecheck($rdap),$profiles);
    $class='Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($i===0?'Enom':'NameSilo').'\\';
    $protocol=$i===0?new ($class.'Transfer')($binding,$exchange):new ($class.'Transfer')($binding,$profiles,$exchange);
    $bridge=new ($class.'Writer')($binding,$features,$accepted,static function()use(&$engine){return $engine;},$protocol);
    $engine=new DomainOperations($claims,$fresh,$bridge,$clock); Cache::forget('domains-write-slot:'.$binding->fingerprint);
};
