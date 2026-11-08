<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline customer transfer results.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/Support/storefront-reader.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,AttemptEvidence,Capabilities,DriverResult,Query,ReconciliationResult,RequestContext};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedTransferController, StorefrontController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{Rdap, TransferPrecheck};
extract(commerceFixture(true));
require dirname(__DIR__).'/Domains/routes/web.php';
require dirname(__DIR__).'/Domains/routes/owned.php';
storefrontViews();
$session = app('request')->session();
$state = (object) ['calls' => 0, 'fail' => false];
$transferBindings = storefrontTransferReader($state);
activeFixtureOffers($transferBindings, activeFixtureOffers($bindings));
$public = (object) ['status' => 200, 'states' => ['client transfer prohibited'], 'created' => '2020-01-01T00:00:00Z', 'transferred' => null];
$rdap = new Rdap(static function ($url) use ($public): array {
    $events = [['eventAction' => 'registration', 'eventDate' => $public->created], ['eventAction' => 'expiration', 'eventDate' => '2027-01-01T00:00:00Z']];
    if ($public->transferred) { $events[] = ['eventAction' => 'transfer', 'eventDate' => $public->transferred]; }
    return ['status' => str_contains($url, 'dns.json') ? 200 : $public->status,
        'body' => json_encode(str_contains($url, 'dns.json') ? ['services' => [[['com'], ['https://rdap.example.com/']]]]
            : ['objectClassName' => 'domain', 'ldhName' => 'transfer-placeholder.com', 'status' => $public->states, 'events' => $events])];
});
$eligibility = activeFixtureEligibility($transferBindings, $clock, new TransferPrecheck($rdap));
$store = activeFixtureStorefront($sellers, $prices, $native, $transferBindings, $eligibility, $clock);
$controller = new StorefrontController($store);
$server = App\Models\Server::findOrFail($tlds[0]->server_id);
$setting = $server->settings()->create(['key' => 'domains_transfer_endings', 'value' => 'com']);
$req = static function (array $input = [], string $method = 'POST') use ($session): Request {
    $r = Request::create('/domains/check', $method, $input, server: ['REMOTE_ADDR' => '192.0.2.77']);
    $r->setLaravelSession($session); return $r;
};
$key = FormKeys::issue($req(), 'search');
$check = static fn (bool $transfer = true) => $controller->check($req(['domain' => 'transfer-placeholder.com', '_request_key' => $key, 'mode' => $transfer ? 'transfer' : 'register']))->getData(true);
$assertRefusal = static function (array $result, string $reason) : void {
    expect(str_contains($result['html'], $reason), 'Transfer result explains: '.$reason.'; actual: '.strip_tags($result['html']));
    expect(!str_contains($result['html'], 'name="action"'), 'Refused transfer has no purchase action');
    expect(!str_contains($result['html'], '>Taken<'), 'Transfer refusal does not say Taken');
};
$assertRefusal($check(), 'Unlock this domain at your current registrar, then try again.');
same('taken', $check(false)['status'], 'Registration search keeps its Taken result');
$public->states = ['active']; $public->created = $now->modify('-30 days')->format(DATE_ATOM);
$assertRefusal($check(), 'registered less than 60 days ago');
$public->created = '2020-01-01T00:00:00Z'; $public->transferred = $now->modify('-30 days')->format(DATE_ATOM);
$assertRefusal($check(), 'transferred less than 60 days ago');
$public->transferred = null; $public->status = 404;
$flags=$store->check('transfer-placeholder.com',true);same([true,false],[$flags['register'],$flags['support']],'Only an unregistered refusal offers registration');
$unregistered=$check();$assertRefusal($unregistered, 'isn’t registered.');
expect(str_contains($unregistered['html'],'href="'.e(route('domains.domains.search',['name'=>'transfer-placeholder.com'])).'"')&&str_contains($unregistered['html'],'Register this domain'),'Unregistered transfer offers the real safe registration search link');
$public->status = 200; $setting->update(['value' => 'net']);
config(['settings.tickets_disabled'=>true,'domains.support_url'=>'https://support.example.test/domains']);
$flags=$store->check('transfer-placeholder.com',true);same([false,true],[$flags['register'],$flags['support']],'Unsupported ending offers support, never registration');
$unsupported=$check();$assertRefusal($unsupported, 'We don’t offer transfers for this ending.');
expect(str_contains($unsupported['html'],'href="https://support.example.test/domains"')&&str_contains($unsupported['html'],'Contact support'),'Unsupported ending offers the configured safe support link');
expect(!str_contains($unsupported['html'],'Register this domain'),'An unsupported ending does not imply registration is available');
$setting->update(['value' => 'com']);
$state->transferable='unknown';
$flags=$store->check('transfer-placeholder.com',true);same(['transfer_unconfirmed',false,true],[$flags['status'],$flags['register'],$flags['support']],'Unknown registrar eligibility stays unconfirmed with support and no registration offer');
$unknown=$check();$assertRefusal($unknown,'Try again in a few minutes.');
expect(str_contains($unknown['html'],'data-domains-retry'),'Unknown eligibility retains Retry without a purchase submit');
$state->transferable='true';
$result = $check();
same('transferable', $result['status'], 'Eligible name offers a transfer');
expect(str_contains($result['html'], 'value="transfer"'), 'Eligible name has the Transfer action');
// The first registrar read succeeds; admission defers the second, transfer-specific read.
$limiter = new class implements Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter {
    public int $calls = 0;
    public function acquire($binding, $origin): string {
        if (++$this->calls === 2) { throw new Paymenter\Extensions\Servers\Domains\Services\ReadDeferred; }
        return 'synthetic-read';
    }
    public function release($binding, $origin, string $token): void {}
};
app()->instance(Paymenter\Extensions\Servers\Domains\Services\Runtime::class, new Paymenter\Extensions\Servers\Domains\Services\Runtime);
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class, $limiter);
$deferred = $check();
same(2,$limiter->calls,'The transfer precheck reaches the second read admission');
same('busy',$deferred['status'],'Deferred transfer read is retryable');
expect(str_contains($deferred['html'],'data-domains-retry'),'Deferred transfer keeps the Retry control');
$limiter->calls=0;
$deferredRegister=$check(false);
same(2,$limiter->calls,'Registration search confirms availability before the transfer read defers');
same('taken',$deferredRegister['status'],'A deferred transfer read preserves confirmed registration unavailability');
app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Services\Runtime::class);
app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class);
$public->states = ['pending transfer'];
$assertRefusal($check(), 'already in progress');
$page = (new OwnedTransferController($store))->show($req(['name' => 'transfer-placeholder.com'], 'GET'))->render();
expect(str_contains($page, 'data-mode="transfer"'), 'Transfer page requests transfer results');
// Transfer-only sales must not depend on a one-year registration check. The real Enom
// adapter rejects that query when the synthetic registry requires at least two years.
$public->states = ['active'];
$b = Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole());
$registry = (new ReflectionProperty($transferBindings,'registry'))->getValue($transferBindings);
$wire = new class implements Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Transport {
    public function send($environment,$command,array $parameters,string $uid,string $token,int $timeoutMs): string {
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done><Domains><Domain><Name>transfer-placeholder.com</Name><RRPCode>211</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Properties><Transferable>true</Transferable><MinRegYear>2</MinRegYear></Properties></Domain></Domains></interface-response>';
    }
};
$registry->registerBoundReader($b,static function($binding,$credentials)use($wire,$state){
    $driver=new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client($binding,$credentials['token'],'synthetic',$wire),['com']);
    return new class($driver,$state) implements RegistrarDriver {
        public function __construct(private RegistrarDriver $driver,private object $state){}
        public function binding():AccountBinding{return $this->driver->binding();}
        public function accountCapabilities(RequestContext $c):Capabilities{return $this->driver->accountCapabilities($c);}
        public function balance(RequestContext $c):DriverResult{return $this->driver->balance($c);}
        public function quote(Query $q,RequestContext $c):DriverResult{return $this->driver->quote($q,$c);}
        public function availability(Query $q,RequestContext $c):DriverResult{if(isset($this->state->exception)){$this->state->plainThrown=($this->state->plainThrown??0)+1;throw $this->state->exception;}return $this->driver->availability($q,$c);}
        public function readDomain(string $n,RequestContext $c):DriverResult{return $this->driver->readDomain($n,$c);}
        public function readRenewalDomain(string $n,RequestContext $c):DriverResult{return $this->driver->readRenewalDomain($n,$c);}
        public function renewalAssessment(Query $q,RequestContext $c):DriverResult{return $this->driver->renewalAssessment($q,$c);}
        public function reconcile(AttemptEvidence $a,RequestContext $c):ReconciliationResult{return $this->driver->reconcile($a,$c);}
    };
});
$q = new Paymenter\Extensions\Servers\Domains\Catalogue\Query($b,['com'],'register');
$ports->apply(Paymenter\Extensions\Servers\Domains\Catalogue\Edit::seller($b,'com',['transfer']),$ports->readOffers($q,Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
foreach ([true,false] as $transferMode) {
    $onlyTransfer = $check($transferMode);
    same('transferable',$onlyTransfer['status'],'Transfer-only sales bypass registration minimum terms');
    expect(str_contains($onlyTransfer['html'],'value="transfer"'),'Transfer-only sales retain the Transfer action');
}
// Without registration offers, the initial Taken placeholder is not confirmed availability.
$limiter->calls=1;
app()->instance(Paymenter\Extensions\Servers\Domains\Services\Runtime::class,new Paymenter\Extensions\Servers\Domains\Services\Runtime);
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,$limiter);
same('busy',$check(false)['status'],'A transfer-only seller keeps Retry when no registration read confirmed Taken');
app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Services\Runtime::class);
app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class);
// Plain reader exceptions must not impersonate typed public eligibility reasons.
$state->exception=new DomainException('premium');$public->states=['active'];
$failures=[];$spoof=$check();same(1,$state->plainThrown,'The actual bound reader throws the plain exception');if($spoof['status']!=='transfer_unconfirmed'||!str_contains($spoof['html'],'data-domains-retry')||str_contains($spoof['html'],'premium domains')){$failures[]='Plain provider exception must stay unconfirmed with Retry';}
$state->exception=new Paymenter\Extensions\Servers\Domains\Services\WriteHeld('premium');$typed=$check();same('transfer_refused',$typed['status'],'Typed premium refusal remains authoritative');expect(str_contains($typed['html'],'premium domains')&&!str_contains($typed['html'],'data-domains-retry'),'Typed refusal retains fixed safe guidance');
unset($state->exception);
$user=commerceUser();$product=$native->product($tlds[0],'register');
$order=App\Models\Order::create(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'pending']);
$service=App\Models\Service::withoutEvents(fn()=>App\Models\Service::create(['order_id'=>$order->id,'user_id'=>$user->id,'product_id'=>$product->id,'plan_id'=>$product->plans()->where('billing_period',1)->sole()->id,'price'=>10,'quantity'=>1,'currency_code'=>'USD','status'=>'pending']));
Paymenter\Extensions\Servers\Domains\Models\Domain::create(['domain'=>'transfer-placeholder.com','sld'=>'transfer-placeholder','tld'=>'com','reservation_name'=>'transfer-placeholder.com','user_id'=>$user->id,'service_id'=>$service->id,'server_id'=>$tlds[0]->server_id,'registrar_key'=>'enom','account_fingerprint'=>$tlds[0]->account_fingerprint,'authority_generation'=>1,'status'=>'needs_review']);
$reads=$state->calls;$reserved=$check();
if($reserved['status']!=='transfer_refused'||!str_contains($reserved['html'],'Contact support')||str_contains($reserved['html'],'data-domains-retry')||str_contains($reserved['html'],'name="action"')){$failures[]='Local reservation must refuse with support and no retry or purchase';}
same($reads,$state->calls,'Reserved names never reach the provider');
same('taken',$check(false)['status'],'Registration reservation remains Taken');
foreach($failures as $failure){echo 'FAIL: '.$failure."\n";}expect($failures===[],implode('; ',$failures));
$nativeSuccessful = true;
echo "Customer transfer reasons, next steps and registration isolation passed.\n";
