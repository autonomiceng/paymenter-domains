<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Money};
use Paymenter\Extensions\Servers\Domains\Services\{LiveReservationLimit,LiveReservations,OperationClaims};
/** Worker-local transport guard. NS4 must arm after fresh funded admission, before the engine seals the attempt. */
final class SendReservation
{
    private ?array $scope=null;
    public function __construct(private readonly AccountBinding $binding,private readonly NameSiloLiveReservationPolicy $policy) {}
    public function run(string $feature,int $operationId,\Closure $work): mixed
    {
        if($this->scope!==null||$operationId<1||!in_array($feature,['registration','transfer','renewal','management'],true)){throw new LiveReservationLimit;}
        $this->policy->requireFeature($this->binding,$feature);
        $this->scope=['feature'=>$feature,'id'=>$operationId,'grant'=>null,'consumed'=>false];
        try{return $work();}finally{$this->scope=null;}
    }
    public function reserve(string $command,#[\SensitiveParameter] array $parameters,?Money $cost=null): void
    {
        OperationClaims::outside();
        $s=$this->scope;
        if(!$s||$s['grant']!==null||$s['consumed']){throw new LiveReservationLimit;}
        $feature=AdmittedTransport::feature($command);
        if($feature!==$s['feature']||in_array($command,['registerDomain','transferDomain','renewDomain'],true)&&($cost===null||$cost->currency!=='USD'||!preg_match('/[1-9]/',$cost->amount))){throw new LiveReservationLimit;}
        AdmittedTransport::parameters($command,$parameters);
        $this->policy->requireFeature($this->binding,$feature);
        $day=(new LiveReservations($this->policy))->reserve($this->binding,$cost);
        $this->scope['grant']=[$command,self::digest($parameters),$day];
    }
    private static function digest(#[\SensitiveParameter] array $parameters): string
    {ksort($parameters);return hash('sha256',json_encode($parameters,JSON_THROW_ON_ERROR));}
    public function consume(string $command,#[\SensitiveParameter] array $parameters): void
    {
        $s=$this->scope;$g=$s['grant']??null;
        if(!$g||$s['consumed']||$g!==[$command,self::digest($parameters),LiveReservations::day()]){throw new LiveReservationLimit;}
        $this->policy->requireFeature($this->binding,$s['feature']);
        $this->scope['consumed']=true;
    }
    public function unconsumed(int $operationId): bool
    {return $this->scope!==null&&$this->scope['id']===$operationId&&$this->scope['grant']!==null&&!$this->scope['consumed'];}
    public function __debugInfo(): array {return [];}
    public function __serialize(): never {throw new \LogicException('Send reservations cannot be persisted.');}
    public function __clone(): void {throw new \LogicException('Send reservations cannot be cloned.');}
}
