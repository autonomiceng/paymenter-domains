<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Environment, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, OperationClaims, WriteAcceptance, WriteFeatures};
/** Final admitted entry; slice21 implements only the protected named wire method, never a raw public sender.
 * No network implementation ships in20. Descriptors/payloads/receipts alone cannot reach the wire method.
 */
abstract class RegistrationTransport
{
    final public function register(AdmittedOperation $operation, RequestContext $context, DomainOperations $engine,
        WriteFeatures $features, WriteAcceptance $acceptance): DriverResult
    {
        OperationClaims::outside(); $features->requireEnabled(Action::Register);
        $operation->binding->requireMatch($context->binding);
        if ($operation->query->action !== Action::Register || $context->origin !== RequestOrigin::Background) {
            throw new \DomainException('Invalid registration dispatch context.');
        }
        $payload = $engine->consume($operation, $context, $acceptance);
        $timeout = min(25000, $context->claimRead($operation->binding));
        $live = $operation->binding->environment === Environment::Live;
        $origin = match ($operation->binding->registrarKey) {
            'enom' => $live ? 'https://reseller.enom.com/interface.asp' : 'https://resellertest.enom.com/interface.asp',
            'namesilo' => ($live ? \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Live
                : \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Sandbox)->origin().'/apibatch',
        };
        try {
            $result = $this->sendRegistration($operation, $context, $payload, $origin, $timeout);
            $operation->binding->requireMatch($result->binding); return $result;
        } catch (\Throwable) { throw new \RuntimeException('Registrar registration outcome is unresolved.'); }
    }
    abstract protected function sendRegistration(AdmittedOperation $operation, RequestContext $context,
        #[\SensitiveParameter] array $frozenPayload, string $origin, int $timeoutMs): DriverResult;
    final public function __debugInfo(): array { return []; }
    final public function __serialize(): never { throw new \LogicException('Registration transports cannot be persisted.'); }
    final public function __clone(): void { throw new \LogicException('Registration transports cannot be cloned.'); }
}
