<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AdmittedOperation, RequestContext};
/** Explicitly injected bounded protocol port; no default network sender or installed composition. */
abstract class RegistrationProtocol extends RegistrationTransport
{
    private readonly \SensitiveParameterValue $exchange;
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
