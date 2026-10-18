<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, ManagementRequest, ManagementResult, RequestContext};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement, OperationClaims};
/** Named capability port. No default exchange, public command execution, purchase or retry path. */
abstract class ManagementProtocol
{
    private readonly \SensitiveParameterValue $exchange;
    private ?ManagementRequest $active = null;
    private ?RequestContext $context = null;
    private ?DomainManagement $engine = null;
    private bool $sent = false;
    public function __construct(protected readonly AccountBinding $binding, #[\SensitiveParameter] \Closure $exchange)
    { $this->exchange = new \SensitiveParameterValue($exchange); }
    final public function bindsTo(AccountBinding $binding): bool { return $this->binding->matches($binding); }
    abstract public function capabilities(): Capabilities;
    abstract public function validate(string $action, #[\SensitiveParameter] array $payload, string $name): array;
    abstract public function prepare(ManagementRequest $request, RequestContext $context): array;
    abstract public function observe(ManagementRequest $request, RequestContext $context): ManagementResult;
    abstract protected function apply(ManagementRequest $request, RequestContext $context): ManagementResult;
    abstract protected function readCommands(): array;
    abstract protected function validateRead(string $command, #[\SensitiveParameter] array $parameters): void;
    abstract protected function writeCommand(string $action): string;
    abstract protected function origin(RequestContext $context): string;
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
        return $this->exchange($r, $c, $command, $parameters);
    }
    final protected function write(ManagementRequest $r, RequestContext $c, string $command, #[\SensitiveParameter] array $parameters): string
    {
        if ($this->active !== $r || $this->context !== $c || $this->sent || !$this->engine || $command!==$this->writeCommand($r->action)) { throw new \DomainException('No live management grant.'); }
        $this->engine->consume($r, $c, $this); $this->sent = true;
        return $this->exchange($r, $c, $command, $parameters);
    }
    final protected function acknowledge(ManagementRequest $r, RequestContext $c, array $facts): void
    {
        if($this->active!==$r||$this->context!==$c||!$this->sent||!$this->engine){throw new \DomainException('No acknowledgement grant.');}
        $this->engine->acknowledge($r,$c,$this,$facts);
    }
    private function exchange(ManagementRequest $r, RequestContext $c, string $command, #[\SensitiveParameter] array $parameters): string
    {
        OperationClaims::outside(); $this->binding->requireMatch($r->binding); $this->binding->requireMatch($c->binding);
        $timeout = min(25000, $c->claimRead($this->binding));
        try { $body = ($this->exchange->getValue())($this->origin($c), $command, $parameters, $timeout); }
        catch (\Throwable) { throw new \RuntimeException('Management transport unavailable.'); }
        if (!is_string($body) || strlen($body) > 1048576) { throw new \RuntimeException('Management response unavailable.'); }
        return $body;
    }
    final public function __debugInfo(): array { return []; }
    final public function __serialize(): never { throw new \LogicException('Management ports are server-only.'); }
    final public function __clone(): void { throw new \LogicException('Management ports cannot be cloned.'); }
}
