<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Explicit malformed row in an otherwise accepted catalogue; unknown cost, never confirmed withdrawal. */
final readonly class CatalogueUnavailable
{
    public function __construct(public AccountBinding $binding, public string $tld, public \DateTimeImmutable $observedAt)
    {
        if ($binding->registrarKey !== 'namesilo') { throw new \InvalidArgumentException('Unsupported quarantine source.'); }
        CanonicalName::parse('example.'.$tld, [$tld]);
    }
    public function __debugInfo(): array { return ['tld' => $this->tld, 'status' => 'price_unavailable']; }
    public function __serialize(): never { throw new \LogicException('Catalogue evidence is server-only.'); }
}
