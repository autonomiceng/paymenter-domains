<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Environment, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationClaims, TestCatalogue};

/** Revalidate the accepted credential revision before each offer read. */
final class OfferTransport implements Transport
{
    private readonly Transport $wire;

    public function __construct(private readonly AccountBindings $bindings, private readonly AccountBinding $binding,
        #[\SensitiveParameter] private readonly string $revision, ?Transport $wire = null,
        private readonly ?\Closure $current = null)
    { $this->wire = $wire ?? ReadAdapter::transport(); }

    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        OperationClaims::outside();
        if ($this->current) { ($this->current)(); }
        if ($environment->value !== $this->binding->environment->value) { throw new \DomainException('Offer environment changed.'); }
        if (!TestCatalogue::enabled($this->binding)) { throw new \DomainException('Offer reads are unavailable for this deployment.'); }
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
