<?php
// Enom d1392a8 Extend/UpdateExpiredDomains; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\AdmittedRegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AdmittedOperation, Disposition, DriverResult, Evidence, RenewalEvidence, RejectionBasis, RequestContext, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{RegistrationCommand, ResponseParser as EnomParser};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Environment as NameSiloEnvironment, Outcome, PurchaseMapping, ResponseParser as NameSiloParser};
/** Named bounded protocol port; default composition supplies no sender. One call consumes one live grant. */
final class RenewalWriter implements AdmittedRegistrarDriver
{
    private readonly \SensitiveParameterValue $exchange;
    private ?\Paymenter\Extensions\Servers\Domains\Contracts\WriteReservation $reservation=null;
    private ?int $reservationId=null;
    public function useReservation(\Paymenter\Extensions\Servers\Domains\Contracts\WriteReservation $reservation): void
    {if($this->reservation||$this->binding->registrarKey!=='namesilo'||!$reservation->bindsTo($this->binding)){throw new \LogicException('Reservation binding unavailable.');}$this->reservation=$reservation;}
    public function withinReservation(\Paymenter\Extensions\Servers\Domains\Models\Operation $op,\Closure $work): mixed
    {
        if(!$this->reservation){return $work();}
        if($op->action!=='renew'||$op->registrar_key!=='namesilo'||$op->server_id!==$this->binding->serverId||$op->account_fingerprint!==$this->binding->fingerprint){throw new WriteHeld('write_acceptance_required');}
        return $this->reservation->run('renewal',(int)$op->id,function()use($op,$work){
            $this->reservationId=(int)$op->id;try{return $work();}finally{$this->reservationId=null;}
        });
    }
    public function reservePurchase(string $name,int $years,\Paymenter\Extensions\Servers\Domains\Data\Money $cost): void
    {$this->reservation?->reserve('renewDomain',self::parameters($name,$years),$cost);}
    private static function parameters(string $name,int $years): array
    {return ['domain'=>$name,'years'=>$years,'auto_renew'=>0];}
    private function unconsumed(): bool
    {return $this->reservationId!==null&&($this->reservation?->unconsumed($this->reservationId)??false);}

    public function __construct(private readonly AccountBinding $binding, private readonly WriteFeatures $features,
        private readonly RenewalAcceptance $accepted, private readonly \Closure $engine, #[\SensitiveParameter] \Closure $exchange)
    { $this->exchange = new \SensitiveParameterValue($exchange); }
    public function guardAccount(RequestContext $context): void
    {
        if($this->binding->registrarKey==='namesilo'){NameSiloAccountGuard::check($this->binding,$context,$this->exchange->getValue());}
    }
    public function requirePolicy(AccountBinding $binding, WriteFeatures $features, RenewalAcceptance $accepted): void
    {
        $this->binding->requireMatch($binding);
        if ($features !== $this->features || $accepted !== $this->accepted) { throw new \DomainException('Renewal policy composition changed.'); }
        $features->requireEnabled(Action::Renew); $accepted->requireCurrent($binding);
    }
    public function renew(AdmittedOperation $op, RequestContext $context): DriverResult
    {
        OperationClaims::outside(); $this->binding->requireMatch($op->binding); $this->binding->requireMatch($context->binding);
        $engine = ($this->engine)();
        if (!$engine instanceof RenewalExecution || $op->query->action !== Action::Renew) { throw new \DomainException('No renewal engine scope.'); }
        try { $mode = $engine->consume($op, $context, $this); }
        catch (RenewalUnsent) { return self::unsent($op); }
        try { $remaining = $context->claimRead($op->binding); if ($remaining < 1) { return self::unsent($op); } }
        catch (\Throwable) { return self::unsent($op); }
        $timeout = min(25000, $remaining);
        if ($op->binding->registrarKey === 'enom') {
            $parts = explode('.', $op->query->name, 2); $command = $mode === 'grace' ? RegistrationCommand::GraceRenew : RegistrationCommand::Renew;
            $params = ['SLD' => $parts[0], 'TLD' => $parts[1], 'NumYears' => $op->query->years, 'UseCreditCard' => 'no'];
            if ($mode === 'grace') { $params = ['DomainName' => $op->query->name, 'NumYears' => 1, 'UseCreditCard' => 'no']; }
            try{$body=$this->wire($op,$context,($op->binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?'https://reseller.enom.com/interface.asp':'https://resellertest.enom.com/interface.asp'),$command->value,$params,$timeout);}catch(RenewalUnsent){return self::unsent($op);}
            $reply = (new EnomParser)->parse($command, self::body($body)); $ref = $reply->value('orderid');
            $grace = $command === RegistrationCommand::GraceRenew && $reply->value('reactivatedomainname.status') === 'true';
            if ($grace) {
                $nested = $reply->value('reactivatedomainname.orderid');
                if ($ref !== null && $ref !== $nested) { return self::unknown($op); }
                $ref = $nested;
            }
            $ref = is_string($ref) && preg_match('/^[1-9][0-9]{0,19}$/D', $ref) ? $ref : null;
            if ($reply->status !== 'success' || (!$grace && !in_array($reply->code, ['200', '1300'], true))) { return self::unknown($op); }
            return new DriverResult($op->binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
                new Evidence(renewalExecution: new RenewalEvidence($op->query, $ref)));
        }
        // NameSilo support recommends the explicit flag; fresh completion readback remains mandatory.
        $mapping = PurchaseMapping::renew($op->query->name, $op->query->years, false);
        $environment = $op->binding->environment === \Paymenter\Extensions\Servers\Domains\Data\Environment::Live ? NameSiloEnvironment::Live : NameSiloEnvironment::Sandbox;
        try{$body=$this->wire($op,$context,$environment->origin().'/apibatch','renewDomain',self::parameters($op->query->name,$op->query->years),$timeout);}catch(RenewalUnsent){return self::unsent($op);}
        $reply = (new NameSiloParser)->parse(self::body($body), $mapping, '');
        if ($reply->outcome !== Outcome::Success) { return self::unknown($op); }
        return new DriverResult($op->binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
            new Evidence(renewalExecution: new RenewalEvidence($op->query, null)));
    }
    private function wire(AdmittedOperation $op,RequestContext $ctx,string $origin,string $command,array $parameters,int $timeout): mixed
    {
        $started=false;
        try{return $ctx->withinClaimedWire($op->binding,function(int $remaining)use(&$started,$origin,$command,$parameters,$timeout){$started=true;return ($this->exchange->getValue())($origin,$command,$parameters,min($timeout,$remaining));});}
        catch(EnomTestCallLimit $e){if(!EnomTestWriteReservation::unsent($op->binding,'renewal')){throw $e;}throw new RenewalUnsent('TEST send refused before the wire.');}
        catch(ReadDeferred $e){if(!$started||$this->unconsumed()){throw new RenewalUnsent('Wire capacity unavailable.');}throw new \RuntimeException('Renewal outcome unresolved.');}
        catch(\Throwable $e){if($this->unconsumed()){throw new RenewalUnsent('Reserved send refused before the wire.');}throw $e;}
    }
    private static function unsent(AdmittedOperation $op): DriverResult
    { return new DriverResult($op->binding, Disposition::Rejected, SendState::DefinitelyUnsent, ResultReason::Unsent, rejectionBasis: RejectionBasis::DefinitelyUnsent); }
    private static function body(mixed $body): string
    { if (!is_string($body) || strlen($body) > 1048576) { throw new \RuntimeException('Renewal response unavailable.'); } return $body; }
    private static function unknown(AdmittedOperation $op): DriverResult
    { return new DriverResult($op->binding, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved); }
    public function register(AdmittedOperation $op, RequestContext $context): DriverResult { throw new \DomainException('Renewal writer only.'); }
    public function transferIn(AdmittedOperation $op, RequestContext $context): DriverResult { throw new \DomainException('Renewal writer only.'); }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('Renewal writers cannot be persisted.'); }
    public function __clone(): void { throw new \LogicException('Renewal writers cannot be cloned.'); }
}
