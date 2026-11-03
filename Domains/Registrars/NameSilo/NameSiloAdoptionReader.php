<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdoptionSource,Clock,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CanonicalName,Disposition,ExpiryPrecision,Query,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\AdoptionReadback;

/** Exact-name import through the admitted read runtime; no writer or renewal quote is exposed. */
final readonly class NameSiloAdoptionReader implements AdoptionSource
{
    public function __construct(private Driver $driver,private Clock $clock,private ?ReadLimiter $limiter=null) {}
    /** Pass AccountBindings::nameSiloRuntimeReaderFactory with revision-bound acceptance. Does not register anything. */
    public static function factory(#[\SensitiveParameter] \Closure $runtime,?ReadLimiter $limiter=null): \Closure
    {
        $private=new \SensitiveParameterValue($runtime);
        return static function(AccountBinding $binding,#[\SensitiveParameter] array $credentials,Clock $clock)use($private,$limiter):self {
            $driver=($private->getValue())($binding);
            $binding->requireMatch($driver->binding());
            return new self($driver,$clock,$limiter);
        };
    }
    public function binding(): AccountBinding {return $this->driver->binding();}
    public function read(string $name,string $suffix): AdoptionReadback
    {
        Query::requireName($name);CanonicalName::parse($name,[$suffix]);
        $start=$this->clock->now();
        $context=new RequestContext($this->binding(),RequestOrigin::Interactive,$start->modify('+90 seconds'),new RequestBudget(1),$this->clock,$this->limiter);
        $failure=fn(string $reason)=>AdoptionReadback::refused($this->binding(),$reason);
        try {
            $reply=$this->driver->readDomain($name,$context);$domain=$reply->evidence()->domain;
            if($reply->disposition!==Disposition::Accepted||$this->clock->now()>=$context->deadline){return $failure('read_failed');}
            if(!$domain||!$this->binding()->matches($domain->binding)||$domain->name!==$name||$domain->owned!==true){return $failure('identity_unconfirmed');}
            if($domain->providerAutoRenew!==false){return $failure($domain->providerAutoRenew===true?'auto_renew_on':'auto_renew_unknown');}
            if(!$domain->expiry||$domain->expiry->precision!==ExpiryPrecision::Date){return $failure('expiry_unconfirmed');}
            // Date-only evidence is compared as a UTC calendar day, never given invented instant precision.
            $expiry=new \DateTimeImmutable($domain->expiry->value.'T00:00:00Z');
            if($expiry<=$this->clock->now()){return $failure('expired');}
            if($expiry>$start->modify('+10 years')||$domain->active!==true||!$domain->nameservers
                ||$domain->privacy===null||$domain->locked===null){return $failure('state_unconfirmed');}
            return AdoptionReadback::verified($this->binding(),['name'=>$name,'provider_id'=>null,'expiry'=>$domain->expiry,
                'auto_renew'=>false,'ordinary'=>true,'registrar_hold'=>false,'redemption'=>false,
                'settings'=>['privacy'=>$domain->privacy,'locked'=>$domain->locked,'nameservers'=>$domain->nameservers->nameservers],
                'read_start'=>$start->format(DATE_ATOM),'read_end'=>$this->clock->now()->format(DATE_ATOM),'command_set_version'=>1]);
        }catch(\Throwable){return $failure('read_failed');}
    }
    public function __debugInfo(): array {return ['registrar'=>'namesilo'];}
    public function __serialize(): array {throw new \LogicException('Reader cannot be serialized.');}
}
