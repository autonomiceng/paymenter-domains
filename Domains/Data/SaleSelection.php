<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Untrusted search/cart token; possession grants no admission or spending authority. */
final readonly class SaleSelection implements \JsonSerializable
{
    public function __construct(public string $tld, public int $serverId, public string $registrar,
        public string $fingerprint, public int $policyRevision, public string $action, public int $term,
        public string $currency, public int $priceId, public int $priceRevision) {}
    public function jsonSerialize(): array { return ['tld' => $this->tld, 'action' => $this->action, 'term' => $this->term]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('Persist selection through an explicit server-side cart adapter.'); }
}
