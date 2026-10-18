<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Environment, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, OperationClaims, WriteAcceptance, WriteFeatures};
/** Final admitted entry. Every real sender MUST extend RegistrationProtocol with its bound bridge/preflight.
 * Direct subclasses are synthetic seams only. Descriptors/payloads/receipts alone cannot reach real senders.
 */
abstract class RegistrationTransport
{
    private ?array $flight = null;
    final public function register(AdmittedOperation $operation, RequestContext $context, DomainOperations $engine,
        WriteFeatures $features, WriteAcceptance $acceptance): DriverResult
    {
        OperationClaims::outside(); $action = $this instanceof TransferProtocol ? Action::Transfer : Action::Register; $features->requireEnabled($action);
        if ($this->flight !== null) { throw new \DomainException('Registration is already in flight.'); }
        $operation->binding->requireMatch($context->binding);
        if ($operation->query->action !== $action || $context->origin !== RequestOrigin::Background) {
            throw new \DomainException('Invalid registration dispatch context.');
        }
        $payload = $engine->consume($operation, $context, $acceptance, $features, $this);
        $timeout = $this instanceof RegistrationProtocol ? 25000 : min(25000, $context->claimRead($operation->binding));
        $live = $operation->binding->environment === Environment::Live;
        $origin = match ($operation->binding->registrarKey) {
            'enom' => $live ? 'https://reseller.enom.com/interface.asp' : 'https://resellertest.enom.com/interface.asp',
            'namesilo' => ($live ? \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Live
                : \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Sandbox)->origin().'/apibatch',
        };
        $this->flight = [$operation, $context, $payload, $origin, $timeout];
        try {
            $result = $this->sendRegistration($operation, $context, $payload, $origin, $timeout);
            $operation->binding->requireMatch($result->binding); return $result;
        } catch (\Throwable) { throw new \RuntimeException('Registrar registration outcome is unresolved.'); }
        finally { $this->flight = null; }
    }
    /** First instruction of each protocol implementation. Protected visibility alone permits sibling calls. */
    final protected function requireInFlight(AdmittedOperation $op, RequestContext $context,
        #[\SensitiveParameter] array $payload, string $origin, int $timeout): void
    {
        if ($this->flight !== [$op, $context, $payload, $origin, $timeout]) { throw new \DomainException('No in-flight registration authority.'); }
        $this->flight[] = true;
    }
    final protected function requestTimeout(AdmittedOperation $op, RequestContext $context): int
    {
        if (!$this->flight || $this->flight[0] !== $op || $this->flight[1] !== $context || ($this->flight[5] ?? false) !== true) {
            throw new \DomainException('No active registration protocol.');
        }
        return min(25000, $context->claimRead($op->binding));
    }
    abstract protected function sendRegistration(AdmittedOperation $operation, RequestContext $context,
        #[\SensitiveParameter] array $frozenPayload, string $origin, int $timeoutMs): DriverResult;
    final public function __debugInfo(): array { return []; }
    final public function __serialize(): never { throw new \LogicException('Registration transports cannot be persisted.'); }
    final public function __clone(): void { throw new \LogicException('Registration transports cannot be cloned.'); }
}
