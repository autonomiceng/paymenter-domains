<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, RegistrationTransport, WriteGate};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AdmittedOperation, DispatchReadiness, DriverResult, RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Named bridge only; raw mappings and descriptors cannot bypass the live engine + durable checks. */
abstract class RegistrationBridge implements AdmittedRegistrarDriver, WriteGate
{
    private readonly \SensitiveParameterValue $transport;
    protected function __construct(private readonly AccountBinding $binding, private readonly WriteFeatures $features,
        private readonly WriteAcceptance $accepted, private readonly \Closure $engine,
        #[\SensitiveParameter] RegistrationTransport $transport)
    { $this->transport = new \SensitiveParameterValue($transport); }
    final public function requireEnabled(Action $action): void { $this->features->requireEnabled($action); }
    final public function reserve(): void { OperationClaims::outside(); $this->accepted->reserve($this->binding); }
    final public function requireAtSeal(Operation $op, Domain $domain, DispatchReadiness $ready): void
    {
        $this->requireEnabled(Action::Register); $this->binding->requireMatch($ready->binding);
        ContactSetup::requireReady($op);
        $this->accepted->requireCurrent($this->binding, OperationClaims::query($op, $domain), $op, true);
    }
    final public function register(AdmittedOperation $op, RequestContext $context): DriverResult
    {
        OperationClaims::outside(); $this->requireEnabled(Action::Register);
        $this->binding->requireMatch($op->binding); $this->binding->requireMatch($context->binding);
        $engine = ($this->engine)();
        if (!$engine instanceof DomainOperations) { throw new \DomainException('No engine writer scope.'); }
        return $this->transport->getValue()->register($op, $context, $engine, $this->features, $this->accepted);
    }
    final public function transferIn(AdmittedOperation $op, RequestContext $context): DriverResult
    { $this->requireEnabled(Action::Transfer); throw new WriteHeld('write_acceptance_required'); }
    final public function renew(AdmittedOperation $op, RequestContext $context): DriverResult
    { $this->requireEnabled(Action::Renew); throw new WriteHeld('write_acceptance_required'); }
    final public function __debugInfo(): array { return ['registrar' => $this->binding->registrarKey]; }
    final public function __serialize(): never { throw new \LogicException('Write bridges cannot be persisted.'); }
    final public function __clone(): void { throw new \LogicException('Write bridges cannot be cloned.'); }
}
