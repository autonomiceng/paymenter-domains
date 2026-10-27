<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
/** Explicit local OTE enrollment, never a provider-confirmed identity verifier. */
final class NameSiloSandbox
{
    public const KEY='domains_namesilo_sandbox_enrollment';
    public const PREFIX='namesilo-ote-enrollment:';
    public static function enabled(): bool {return config('domains.namesilo_sandbox_enrollment.enabled',false)===true;}
    public static function enrolled(Account $a): bool
    {try{$identity=$a->stable_identity;return is_string($identity)&&str_starts_with($identity,self::PREFIX);}catch(\Throwable){return false;}}
    public static function bootstrap(RegistrarRegistry $registry): bool
    {
        if(!self::enabled()){return false;}
        // Explicit provider compositions use RegistrarFactories::install instead of this OTE opt-in.
        $registry->register('namesilo',['api_key'],new NameSilo\AccountVerifier,static fn()=>throw new \DomainException('Domain reads require separate deployment composition.'),
            static function(AccountBinding $binding,#[\SensitiveParameter] array $credentials): NameSilo\Driver {
                self::requireEnrollment($binding,$credentials);
                return new NameSilo\Driver(new NameSilo\Client($binding,$credentials['api_key']),[]);
            },true);
        return true;
    }
    public static function requireTest(Environment $environment): void
    {if(!self::enabled()||$environment!==Environment::Test){throw new \DomainException('Sandbox enrollment unavailable.');}}
    public static function requireEnrollment(AccountBinding $binding,#[\SensitiveParameter] array $credentials): void
    {
        self::requireTest($binding->environment);
        if($binding->registrarKey!=='namesilo'){throw new \DomainException('Sandbox enrollment unavailable.');}
        $account=Account::where('server_id',$binding->serverId)->firstOrFail();
        $actual=new AccountBinding($account->registrar_key,(int)$account->server_id,Environment::from($account->environment),$account->stable_identity);
        $binding->requireMatch($actual);
        $server=\App\Models\Server::whereKey($binding->serverId)->where('extension','Domains')->where('enabled',true)->firstOrFail();
        $setting=$server->settings()->where('key',self::KEY)->first();
        if(!$setting||!$setting->encrypted||!is_string($setting->value)||$setting->value===''){
            throw new \DomainException('Sandbox enrollment unavailable.');
        }
        $facts=json_decode($setting->value,true,8,JSON_THROW_ON_ERROR);
        self::validate($account,$facts,$credentials);
    }
    public static function facts(int $server,#[\SensitiveParameter] string $label,#[\SensitiveParameter] array $credentials): array
    {
        if(array_keys($credentials)!==['api_key']||!is_string($credentials['api_key'])||$credentials['api_key']===''
            ||strlen($credentials['api_key'])>256||preg_match('/[\x00-\x20\x7f]/',$credentials['api_key'])
            ||$label!==trim($label)||$label===''||strlen($label)>128||!mb_check_encoding($label,'UTF-8')
            ||preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u',$label)||str_contains($label,$credentials['api_key'])){
            throw new \DomainException('Invalid sandbox enrollment input.');
        }
        return ['contract'=>'namesilo_ote_enrollment_v1','enrollment_id'=>bin2hex(random_bytes(16)),'server_id'=>$server,
            'label'=>$label,'key_fingerprint'=>hash('sha256',$credentials['api_key']),
            'provider_confirmed_account'=>false,'authenticated_ote_key'=>true];
    }
    public static function identity(#[\SensitiveParameter] array $facts): string
    {return self::PREFIX.hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR));}
    public static function checkKey(#[\SensitiveParameter] array $credentials): void
    {
        $client=new NameSilo\Api\RegistrarClient($credentials['api_key'],NameSilo\Api\Environment::Sandbox,new NameSilo\Api\CurlTransport);
        $result=$client->execute(NameSilo\Api\RegistrarRequest::balance(),NameSilo\Api\RequestContext::Interactive,10000);
        if($result->outcome!==NameSilo\Api\Outcome::Success){throw new \DomainException('Sandbox key check failed.');}
        // The balance is discarded. This proves key authentication only, never account ownership.
    }
    public static function validate(Account $a,#[\SensitiveParameter] array $facts,#[\SensitiveParameter] array $credentials): void
    {
        self::requireTest(Environment::from($a->environment));
        if($a->registrar_key!=='namesilo'||($facts['contract']??null)!=='namesilo_ote_enrollment_v1'
            ||($facts['server_id']??null)!==(int)$a->server_id||($facts['provider_confirmed_account']??null)!==false
            ||($facts['authenticated_ote_key']??null)!==true||!hash_equals($a->stable_identity,self::identity($facts))
            ||!hash_equals($facts['key_fingerprint']??'',hash('sha256',$credentials['api_key']))){
            throw new \DomainException('Sandbox enrollment changed.');
        }
    }
}
