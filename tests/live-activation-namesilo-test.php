<?php
// Synthetic local activation policy only; no registrar transport or Live acceptance.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,CredentialAuthenticator};
use Paymenter\Extensions\Servers\Domains\Data\{Environment,SystemClock};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,Installation,LiveActivation,RegistrarRegistry,StaffSettingsReview as Review};
(new Installation)->install();
app()->instance(Clock::class,new SystemClock);
$registry=new RegistrarRegistry;
$registry->register('namesilo',['api_key'],new NameSilo\AccountVerifier,
    static fn()=>throw new LogicException('Activation must not construct a provider reader'),
    authenticator:new class implements CredentialAuthenticator {
        public function authenticate(Environment $environment,#[SensitiveParameter] array $credentials): void {}
    });
$bindings=new AccountBindings($registry);app()->instance(AccountBindings::class,$bindings);
$staff=staffUser(['admin.domains.view','admin.domains.manage-policy',Review::permission('configure')]);
$server=Server::create(['name'=>'Synthetic activation','type'=>'server','extension'=>'Domains','enabled'=>true]);
config(['app.url'=>'https://billing.example.com','domains.namesilo_account.environment'=>'live']);
$configure=static fn()=>new Review($staff->id,$server->id,null,'configure',
    Review::ticket($staff->id,$server->id,null,'configure',Review::stamp($server->id,null)),'Configure synthetic local credentials');
$binding=$bindings->configure($server->id,'namesilo',Environment::Live,['api_key'=>'placeholder-key-one'],$configure());
$bindings->confirmLiveSite($server->id,'https://billing.example.com');
$a=Account::where('server_id',$server->id)->sole();
$history=['timezone'=>'UTC','lag'=>60,'retention'=>86400];
$evidence=['fingerprint'=>$binding->fingerprint,'site'=>'https://billing.example.com','confirmed_on'=>'2026-10-20',
    'reference'=>'OFFLINE synthetic NameSilo answer','sha256'=>hash('sha256','OFFLINE synthetic answer'),
    'currency'=>'USD','complete_history'=>true,'history'=>['registration'=>$history,'renewal'=>$history,'transfer'=>$history]];
$row=['fingerprint'=>$binding->fingerprint,'currency'=>'USD','balance_funding'=>true,
    'daily_call_cap'=>1000,'daily_spend_limit_usd'=>'100.00','evidence'=>$evidence];
config(['domains.namesilo_live'=>['site'=>'https://billing.example.com','catalogue'=>true,'registration'=>true,
    'renewal'=>true,'management'=>true,'transfer'=>true,'runtime_revision'=>'OFFLINE-v1','accounts'=>[$a->stable_identity=>$row]]]);
$engine=new LiveActivation(new NameSilo\ActivationEvidence);
$denied=static function(callable $work,string $message):void {
    try{$work();throw new LogicException($message);}catch(DomainException){}
};
$denied(fn()=>$engine->evidence($binding),'NameSilo activation requires portal-only controls');
echo "NameSilo activation requires portal control evidence without provider requests.\n";
$nativeSuccessful=true;
