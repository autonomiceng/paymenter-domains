<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{CurlTransport, DeadlineTransport, Environment as WireEnvironment,
    RegistrarClient, RegistrarRequest, RegistrarResult, RequestContext as WireContext};
use Paymenter\Extensions\Servers\Domains\Services\ProviderSendGuard;

/** @internal Only an accepted AccountBindings reader factory may bind credentials. No identity probe exists here. */
final class Client
{
    private readonly \SensitiveParameterValue $client;
    public function __construct(#[\SensitiveParameter] public readonly AccountBinding $binding,
        #[\SensitiveParameter] string $apiKey, #[\SensitiveParameter] ?DeadlineTransport $transport = null)
    {
        if ($binding->registrarKey !== 'namesilo') { throw new \InvalidArgumentException('NameSilo binding required.'); }
        $this->client = new \SensitiveParameterValue(new RegistrarClient($apiKey,
            $binding->environment === Environment::Live ? WireEnvironment::Live : WireEnvironment::Sandbox, $transport ?? self::readTransport()));
    }

    /** The host policy runs immediately before the client enters the physical exchange. */
    public static function readTransport(): DeadlineTransport
    {
        return new CurlTransport(static function (WireEnvironment $environment, string $command): void {
            ProviderSendGuard::beforeSend('namesilo', $environment === WireEnvironment::Live ? 'live' : 'test', $command);
        });
    }
    public function read(RegistrarRequest $request, #[\SensitiveParameter] RequestContext $context): RegistrarResult
    {
        $result=$context->withinRead($this->binding, fn (int $remaining) => $this->client->getValue()->execute($request,
            $context->origin === RequestOrigin::Interactive ? WireContext::Interactive : WireContext::Background, min(25000, $remaining),
            deferOnReservationLimit: $context->deferOnReservationLimit));
        $context->requireReadSuccess($result->outcome===\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Outcome::Success); return $result;
    }
    public function __debugInfo(): array { return ['registrar' => 'namesilo']; }
    public function __serialize(): array { throw new \LogicException('Registrar clients cannot be serialized.'); }
    public function __clone(): void { throw new \LogicException('Registrar clients cannot be cloned.'); }
}
