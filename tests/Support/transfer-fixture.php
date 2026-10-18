<?php
// Synthetic Enom 444f31c transfer inputs/dispatch scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/commerce.php';
require __DIR__.'/native-commerce.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, CostUnit, Disposition, DriverResult, Environment, Evidence, Money, QuoteEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CartAdmission, CatalogueAcceptances, CheckoutEligibility, ContactProfileSchema, DomainOperations, InvoiceBinding, OperationClaims, Rdap, Refunds, RegistrarRegistry, RegistrationProfiles, TransferInputs, TransferPrecheck, TransferPreflight, WriteAcceptance, WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Tests\{FakeClock, FakeRegistrar};
extract(commerceFixture(true)); $clock = new FakeClock($now);
$guard=new Paymenter\Extensions\Servers\Domains\Services\NativeDomainGuard(new Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices),$clock); $guard->boot();
Carbon\Carbon::setTestNow($now); Carbon\CarbonImmutable::setTestNow($now); ContactProfileSchema::install();
$state = (object) ['query'=>null,'calls'=>[],'reads'=>0,'premium'=>false,'available'=>true,'funds'=>'100.00','mode'=>'','profile'=>[], 'id'=>100,'during'=>null,'rdap'=>0,'rdapUnknown'=>false,'rdap404'=>false];
foreach ($tlds as $tld) { App\Models\Server::find($tld->server_id)->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']); }
$rdap = new Rdap(static function ($url) use ($state): array {
    same(0, DB::transactionLevel(), 'RDAP outside financial locks'); $state->rdap++;
    if ($state->rdapUnknown) { throw new RuntimeException('Synthetic offline failure'); }
    if ($state->rdap404 && !str_contains($url,'dns.json')) { return ['status'=>404,'body'=>'']; }
    return ['status'=>200, 'body'=>json_encode(str_contains($url,'dns.json') ? ['services'=>[[['com'],['https://rdap.example.com/']]]]
        : ['objectClassName'=>'domain','ldhName'=>$state->query?->name ?? 'x.com','status'=>['active'],'events'=>[
            ['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-01-01T00:00:00Z']]])];
});
$admission = new CartAdmission($native,$sellers,new CheckoutEligibility($bindings,$clock,new TransferPrecheck(new Rdap(fn()=>throw new RuntimeException('Synthetic checkout lookup')))),$clock);
$admission->boot(); (new InvoiceBinding($admission,$native,$prices,$clock))->boot();
$user = commerceUser(); $user->update(['email'=>'transfer-owner@example.com']);
foreach (['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value) { $user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]); }
$refunds = new Refunds($clock); $inputs = new TransferInputs($clock,$refunds);
$newTransfer = static function ($name, int $i=0, bool $paid=true, bool $code=true) use ($user,$native,$sellers,$tlds,$clock,$inputs,$state): Operation {
    $policy = DB::table('domains_sales_policies')->where('tld','com')->first();
    if ((int) $policy->server_id !== $tlds[$i]->server_id) { $sellers->change('com',$policy->revision,$tlds[$i]->server_id,true,true,$clock->now()); }
    $cart = commerceCart($user); commerceAdd($cart,$native->product($tlds[$i],'transfer'),$name); commerceCheckout($user,$cart);
    $op = Domain::where('domain',$name)->sole()->operations()->sole();
    if ($paid) { App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount); $inputs->paymentAccepted($op->id); }
    if ($code) { $r = Illuminate\Http\Request::create('/internal-transfer-input','POST',['transfer_code'=>'SyntheticOnly']); $r->setUserResolver(fn()=>$user); $inputs->submitCode($r,$op->id); }
    if ($i === 1) { $op->refresh()->update(['payload'=>[...$op->payload,'nameservers'=>['ns1.provider.net','ns2.provider.net'],'privacy'=>false]]); }
    $state->query = OperationClaims::query($op,$op->domain); return $op->fresh();
};
$registry = new RegistrarRegistry;
$verifier = new class implements AccountVerifier { public function verify(Environment $e, array $c): string { return $c['token']; } };
foreach (['enom','namesilo'] as $key) {
    $registry->register($key,['token'],$verifier,static function ($b,$c) use ($state,$clock) {
        same(0,DB::transactionLevel(),'Fresh reads outside locks'); $state->reads++;
        if ($state->during) { ($state->during)($state->reads); }
        $q = new QuoteEvidence($state->query,new Money('99.00','USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds'));
        return new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,
            new Evidence(quote:$q,balance:new Money($state->funds,'USD'),available:$state->available,premium:$state->premium)));
    });
}
$freshBindings = new AccountBindings($registry); $claims = new OperationClaims($bindings,$clock);
$exchange = static function ($origin,$command,$p,$timeout) use ($state): string {
    same(0,DB::transactionLevel(),'Wire outside locks'); expect($timeout > 0 && $timeout <= 25000,'Bounded wire'); $state->calls[] = $command;
    $reply = ['code'=>300,'detail'=>'synthetic'];
    if ($command === 'contactAdd') { $state->profile=$p; $reply['contact_id']=(string)++$state->id; }
    elseif ($command === 'contactList') { $row=array_combine(['first_name','last_name','company','address','address2','city','state','zip','country','email','phone'],array_values($state->profile)); $reply['contact']=$row+['contact_id'=>(string)$state->id,'default_profile'=>$state->mode==='default'?'1':'0']; }
    else {
        if ($state->mode === 'lost') { throw new RuntimeException('Synthetic response loss'); }
        if ($state->mode === 'malformed') { return '{}'; }
        if ($command === 'TP_CreateOrder') {
            same('https://resellertest.enom.com/interface.asp',$origin,'Fixed Enom route');
            same([1,0,1,0,'no'],[$p['DomainCount'],$p['PreConfig'],$p['Lock'],$p['Renew'],$p['UseCreditCard']],'Frozen external-transfer flags');
            expect(isset($p['AuthInfo1'],$p['RegistrantEmailAddress'],$p['AuxBillingEmailAddress']),'Frozen input and four roles mapped');
            return '<interface-response><Command>TP_CreateOrder</Command><ErrCount>0</ErrCount><Done>true</Done><TransferOrderID>7001</TransferOrderID></interface-response>';
        }
        same('transferDomain',$command,'Only monetary transfer command'); same('https://sandbox.namesilo.com/apibatch',$origin,'Fixed batch route');
        same(0,$p['auto_renew'],'No automatic registrar renewal'); same(0,$p['private'],'Frozen privacy'); expect(str_starts_with($p['auth'],'base64:'),'Encoded auth');
        $reply += ['domain'=>$p['domain'],'order_amount'=>'99.00']; $reply['code']=in_array($state->mode,['301','302'],true)?(int)$state->mode:300;
    }
    return json_encode(['request'=>['operation'=>$command],'reply'=>$reply]);
};
$makeTransfer = static function (int $i=0, ?WriteFeatures $features=null) use ($tlds,$claims,$clock,$freshBindings,$rdap,$exchange,&$engine,&$bridge,&$protocol,&$fresh,&$accepted,&$profiles,&$binding): void {
    $binding = CatalogueAcceptances::binding(Account::where('server_id',$tlds[$i]->server_id)->sole()); $features ??= new WriteFeatures(transfer:true);
    $profiles = new RegistrationProfiles($binding,$claims,$clock,$exchange); $accepted = new WriteAcceptance($binding,true,true,25,$profiles);
    $fresh = new TransferPreflight($freshBindings,$features,$accepted,new TransferPrecheck($rdap),$profiles);
    $class='Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($i===0?'Enom':'NameSilo').'\\';
    $protocol=$i===0?new ($class.'Transfer')($binding,$exchange):new ($class.'Transfer')($binding,$profiles,$exchange);
    $bridge=new ($class.'Writer')($binding,$features,$accepted,static function()use(&$engine){return $engine;},$protocol);
    $engine=new DomainOperations($claims,$fresh,$bridge,$clock); Cache::forget('domains-write-slot:'.$binding->fingerprint);
};
