<?php
// Synthetic account review fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/../native-app.php';require __DIR__.'/staff-tlds.php';
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,NameSiloAccountGuard,RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
$f=commerceFixture();extract($f);tldPanel();Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$wire=new class implements NameSilo\Api\DeadlineTransport {
    public int $calls=0;
    public string $email='new@example.com';
    public string|int $id='901';
    public ?Closure $during=null;
    public bool $failed=false;
    public function send(NameSilo\Api\Environment $environment,NameSilo\Api\RequestContext $context,NameSilo\Api\RegistrarRequest $request,#[SensitiveParameter] string $key): NameSilo\Api\TransportResponse
    {return $this->sendWithin($environment,$context,$request,$key,25000);}
    public function sendWithin(NameSilo\Api\Environment $environment,NameSilo\Api\RequestContext $context,NameSilo\Api\RegistrarRequest $request,#[SensitiveParameter] string $key,int $timeoutMs): NameSilo\Api\TransportResponse
    {
        same(0,Illuminate\Support\Facades\DB::transactionLevel(),'Fresh contact read outside locks');
        same(NameSilo\Api\RequestContext::Interactive,$context,'Staff uses interactive route');
        same('contactList',$request->command,'Only contact inventory is read');same([],$request->parameters(),'No selected contact can replace the default');
        expect($timeoutMs>0&&$timeoutMs<=25000,'Bounded read');$this->calls++;
        if($this->during){($this->during)();}
        if($this->failed){throw new RuntimeException('Synthetic read failure');}
        return new NameSilo\Api\TransportResponse(200,$this->body());
    }
    public function body(): string
    {
        return json_encode(['request'=>['operation'=>'contactList'],'reply'=>['code'=>300,'detail'=>'synthetic','contact'=>[
            ['contact_id'=>$this->id,'email'=>$this->email,'default_profile'=>'1']]]],JSON_THROW_ON_ERROR);
    }
};
$registry=new RegistrarRegistry;
$registry->register('namesilo',['api_key'],new class implements AccountVerifier {
    public function verify(Environment $environment,#[SensitiveParameter] array $credentials): string {return 'account-namesilo';}
},fn(AccountBinding $b,array $credentials)=>new NameSilo\Driver(new NameSilo\Client($b,$credentials['api_key'],$wire)));
$bindings=new AccountBindings($registry);$server=App\Models\Server::findOrFail($tlds[1]->server_id);
$binding=$bindings->configure($server->id,'namesilo',Environment::Test,['api_key'=>'synthetic-key']);
$clock=new class implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public DateTimeImmutable $instant;
    public function __construct() {$this->instant=new DateTimeImmutable('2026-10-26T12:00:00Z');}
    public function now(): DateTimeImmutable {return $this->instant;}
};
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(AccountBindings::class,$bindings);app()->instance(ReadLimiter::class,new AccountReadBudget(app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class),48));
$setExpected=static fn(string $email)=>$server->settings()->updateOrCreate(['key'=>NameSiloAccountGuard::EXPECTED],['encrypted'=>true,'type'=>'string','value'=>$email]);
$before=['binding'=>$binding->fingerprint,'contact_id'=>'900','email'=>'old@example.com'];
$server->settings()->create(['key'=>NameSiloAccountGuard::PIN,'encrypted'=>true,'type'=>'string','value'=>json_encode($before,JSON_THROW_ON_ERROR)]);
$setExpected('new@example.com');
$actor=staffUser(['admin.domains.view','admin.domains.manage']);Illuminate\Support\Facades\Auth::setUser($actor);
$domain=Paymenter\Extensions\Servers\Domains\Models\Domain::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'reset-placeholder.com','sld'=>'reset-placeholder','tld'=>'com','status'=>'registration_pending']);
$held=Paymenter\Extensions\Servers\Domains\Models\Operation::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,
    'domain_id'=>$domain->id,'service_id'=>900,'authority_generation'=>1,'operation_key'=>'synthetic-held-account','action'=>'register','term'=>1,'currency'=>'USD','amount'=>'18.00',
    'state'=>'held','safe_code'=>'namesilo_account_guard','attempt'=>[],'policy'=>['retained'=>'synthetic-policy'],'funding'=>['retained'=>'synthetic-funding']]);
$heldBefore=$held->fresh()->getRawOriginal();Illuminate\Support\Facades\Queue::fake();
