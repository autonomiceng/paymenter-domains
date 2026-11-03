<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{CurlTransport, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationClaims};

/** Revalidate each physical read; CurlTransport retains daily admission and the deployment send guard. */
final class OfferTransport implements Transport
{
    public function __construct(private readonly AccountBindings $bindings, private readonly AccountBinding $binding,
        #[\SensitiveParameter] private readonly string $revision, private readonly Transport $wire = new CurlTransport,
        private readonly ?\Closure $current = null) {}

    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        OperationClaims::outside();
        if ($this->current) { ($this->current)(); }
        if ($environment !== $this->binding->environment) { throw new \DomainException('Offer environment changed.'); }
        $credentials = $this->bindings->offerCredentials($this->binding, $this->revision);
        if (!hash_equals($credentials['uid'], $uid) || !hash_equals($credentials['token'], $token)) {
            throw new \DomainException('Offer credentials changed.');
        }
        if ($this->current) { ($this->current)(); }
        return $this->wire->send($environment, $command, $parameters, $uid, $token, $timeoutMs);
    }
    public function __debugInfo(): array { return ['registrar' => 'enom']; }
    public function __serialize(): never { throw new \LogicException('Offer transports cannot be persisted.'); }
    public function __clone(): void { throw new \LogicException('Offer transports cannot be cloned.'); }
}
