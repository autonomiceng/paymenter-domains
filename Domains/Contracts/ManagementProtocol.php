<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, ManagementRequest, ManagementResult, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement, OperationClaims, WriteHeld};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Named capability port. No default exchange, public command execution, purchase or retry path. */
abstract class ManagementProtocol
{
    private readonly \SensitiveParameterValue $exchange;
    private ?WriteReservation $reservation=null;
    final public function useReservation(WriteReservation $reservation): void
    {if($this->reservation||$this->binding->registrarKey!=='namesilo'||!$reservation->bindsTo($this->binding)){throw new \LogicException('Reservation binding unavailable.');}$this->reservation=$reservation;}
    final public function withinReservation(Operation $op,\Closure $work): mixed
    {
        if(!$this->reservation){return $work();}
        if(!isset($op->policy['management'])||$op->registrar_key!=='namesilo'||$op->server_id!==$this->binding->serverId||$op->account_fingerprint!==$this->binding->fingerprint){throw new WriteHeld('write_acceptance_required');}
        $entered=false;
        try{return $this->reservation->run('management',(int)$op->id,function()use(&$entered,$work){$entered=true;return $work();});}
        catch(\Throwable $e){if($entered){throw $e;}throw new WriteHeld('write_acceptance_required');}
    }
    final public function unconsumed(int $id): bool {return $this->reservation?->unconsumed($id)??false;}

    private ?ManagementRequest $active = null;
    private ?RequestContext $context = null;
    private ?DomainManagement $engine = null;
    private bool $sent = false;
    protected array $preparedRecords = [];
    final public function preparedRecords(): array { $facts=$this->preparedRecords; $this->preparedRecords=[]; return $facts; }
    public function __construct(protected readonly AccountBinding $binding, #[\SensitiveParameter] \Closure $exchange)
    { $this->exchange = new \SensitiveParameterValue($exchange); }
    final public function bindsTo(AccountBinding $binding): bool { return $this->binding->matches($binding); }
    /** Read-only discovery; unsupported families remain unknown, never empty. No write grant. */
    public function discover(ManagementRequest $request, RequestContext $context, array $knownGlue = []): array
    {
        $this->binding->requireMatch($request->binding); $this->binding->requireMatch($context->binding);
        if (count($knownGlue)>50) { throw new \DomainException('Child-host history limit reached.'); }
        return array_fill_keys(['dns','srv','ds'], ['state'=>'unknown'])+['glue'=>['state'=>'partial','records'=>array_slice($knownGlue,0,50,true)]];
    }
    abstract public function capabilities(): Capabilities;
    abstract public function validate(string $action, #[\SensitiveParameter] array $payload, string $name): array;
    public function freeze(string $key, string $name, string $action, #[\SensitiveParameter] array $payload, int $domainId): array { return $payload; }
    /** Optional frozen prerequisite. Each step remains an ordinary one-write management operation. */
    public function prerequisite(string $action, #[\SensitiveParameter] array $payload): ?array { return null; }
    /** Opt-in only: read a complete preimage outside locks, then freeze bounded deltas on one save. */
    public function sequences(string $action): bool { return false; }
    public function plan(ManagementRequest $request, RequestContext $context): array
    { throw new \DomainException('Management sequence unsupported.'); }
    abstract public function prepare(ManagementRequest $request, RequestContext $context): array;
    abstract public function observe(ManagementRequest $request, RequestContext $context): ManagementResult;
    abstract protected function apply(ManagementRequest $request, RequestContext $context): ManagementResult;
    abstract protected function readCommands(): array;
    abstract protected function validateRead(string $command, #[\SensitiveParameter] array $parameters): void;
    abstract protected function writeCommand(string $action): string;
    abstract protected function origin(RequestContext $context): string;
    protected function requestCommand(ManagementRequest $request): string { return $this->writeCommand($request->action); }
    public function billingTerms(ManagementRequest $request, RequestContext $context): array
    { throw new \DomainException('Paid privacy unsupported.'); }
    final public function perform(ManagementRequest $request, RequestContext $context, DomainManagement $engine): ManagementResult
    {
        OperationClaims::outside(); $this->binding->requireMatch($request->binding); $this->binding->requireMatch($context->binding);
        if ($this->active !== null) { throw new \DomainException('Nested management refused.'); }
        $this->active = $request; $this->context = $context; $this->engine = $engine; $this->sent = false;
        try { return $this->apply($request, $context); }
        finally { $this->active = null; $this->context = null; $this->engine = null; }
    }
    final protected function read(ManagementRequest $r, RequestContext $c, string $command, #[\SensitiveParameter] array $parameters): string
    {
        if (!in_array($command, $this->readCommands(), true)) { throw new \DomainException('Unsupported management read.'); }
        $this->validateRead($command,$parameters);
        return $c->withinRead($this->binding, fn(int $remaining) => $this->exchange($r, $c, $command, $parameters, $remaining));
    }
    final protected function write(ManagementRequest $r, RequestContext $c, string $command, #[\SensitiveParameter] array $parameters): string
    {
        if ($this->active !== $r || $this->context !== $c || $this->sent || !$this->engine || $command!==$this->requestCommand($r)) { throw new \DomainException('No live management grant.'); }
        try{$c->claimRead($this->binding);return $c->withReservedWire(function()use($r,$c,$command,$parameters):string {
            \Paymenter\Extensions\Servers\Domains\Services\EnomTestWriteReservation::reserve($r->binding,'management');
            if($this->reservation){
                if($c->origin!==RequestOrigin::Background){throw new WriteHeld('write_acceptance_required');}
                $this->reservation->reserve($command,$parameters);
            }
            $this->engine->consume($r,$c,$this);return $c->withinClaimedWire($this->binding,function(int $remaining)use($r,$c,$command,$parameters):string{$this->sent=true;return $this->exchange($r,$c,$command,$parameters,$remaining);});
        });}catch(\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred $e){if(!$this->sent){throw new WriteHeld('write_rate_limited');}throw $e;}
    }
    final protected function acknowledge(ManagementRequest $r, RequestContext $c, array $facts): void
    {
        if($this->active!==$r||$this->context!==$c||!$this->sent||!$this->engine){throw new \DomainException('No acknowledgement grant.');}
        $this->engine->acknowledge($r,$c,$this,$facts);
    }
    private function exchange(ManagementRequest $r, RequestContext $c, string $command, #[\SensitiveParameter] array $parameters, ?int $remaining = null): string
    {
        OperationClaims::outside(); $this->binding->requireMatch($r->binding); $this->binding->requireMatch($c->binding);
        $timeout = min(25000, ($remaining ?? $c->claimRead($this->binding)));
        try { $send=fn(int $available)=>($this->exchange->getValue())($this->origin($c),$command,$parameters,min($timeout,$available));
            $body=$remaining===null?$c->withinClaimedWire($this->binding,$send):$send($remaining); }
        catch (\Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit) { throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit; }
        catch (\Throwable) { throw new \RuntimeException('Management transport unavailable.'); }
        if (!is_string($body) || strlen($body) > 1048576) { throw new \RuntimeException('Management response unavailable.'); }
        return $body;
    }
    final public function __debugInfo(): array { return []; }
    final public function __serialize(): never { throw new \LogicException('Management ports are server-only.'); }
    final public function __clone(): void { throw new \LogicException('Management ports cannot be cloned.'); }
}
