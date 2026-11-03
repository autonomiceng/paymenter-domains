<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AdmittedOperation, DispatchReadiness, RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\WriteHeld;
/** Explicitly injected bounded protocol port; no default network sender or installed composition. */
abstract class RegistrationProtocol extends RegistrationTransport
{
    private readonly \SensitiveParameterValue $exchange;
    private ?WriteReservation $reservation=null;
    final public function useReservation(WriteReservation $reservation): void
    { if($this->reservation||!$reservation->bindsTo($this->binding)){throw new \LogicException('Reservation binding unavailable.');}$this->reservation=$reservation; }
    final public function withinReservation(Operation $op,\Closure $work): mixed
    {
        if(!$this->reservation){return $work();}
        if($op->server_id!==$this->binding->serverId||$op->account_fingerprint!==$this->binding->fingerprint){throw new WriteHeld('write_acceptance_required');}
        $entered=false;
        try{return $this->reservation->run($op->action==='transfer'?'transfer':'registration',(int)$op->id,function()use(&$entered,$work){$entered=true;return $work();});}
        catch(\Throwable $error){if($entered){throw $error;}throw new WriteHeld('write_acceptance_required');}
    }
    final public function unconsumed(int $id): bool { return $this->reservation?->unconsumed($id)??false; }
    final protected function reserved(): bool { return $this->reservation!==null; }
    final public function reservePurchase(Operation $op,DispatchReadiness $ready): void
    { if($this->reservation){[$command,$parameters]=$this->purchaseReservation($op,$ready);$this->reservation->reserve($command,$parameters,$ready->cost);} }
    protected function purchaseReservation(Operation $op,DispatchReadiness $ready): array
    { throw new \DomainException('Purchase reservation mapping unavailable.'); }
    protected function __construct(protected readonly AccountBinding $binding, #[\SensitiveParameter] \Closure $exchange)
    { $this->exchange = new \SensitiveParameterValue($exchange); }
    final public function guardAccount(RequestContext $context): void
    {
        if($this->binding->registrarKey==='namesilo'){
            \Paymenter\Extensions\Servers\Domains\Services\NameSiloAccountGuard::check($this->binding,$context,$this->exchange->getValue());
        }
    }
    final public function bindsTo(AccountBinding $binding): bool { return $this->binding->matches($binding); }
    final protected function request(AdmittedOperation $op, RequestContext $ctx, string $origin, string $command,
        #[\SensitiveParameter] array $parameters): string
    {
        $timeout = $this->requestTimeout($op, $ctx); $this->binding->requireMatch($op->binding);
        $body = $ctx->withinClaimedWire($op->binding,function(int $remaining)use($origin,$command,$parameters,$timeout){return ($this->exchange->getValue())($origin,$command,$parameters,min($timeout,$remaining));});
        if (!is_string($body) || strlen($body) > 1048576) { throw new \RuntimeException('Registration response unavailable.'); }
        return $body;
    }
}
