<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Immutable server-only snapshot. Operations persist their own encrypted copy; never reprice invoices. */
final readonly class PriceSnapshot implements \JsonSerializable
{
    public function __construct(public int $id, public int $revision, public int $serverId,
        public string $registrar, public string $fingerprint, public string $tld, public string $action,
        public int $term, public string $currency, public string $wholesale, public string $retail,
        public string $source, public \DateTimeImmutable $observedAt, public \DateTimeImmutable $freshUntil,
        public ?string $staffAlert) {}

    public function jsonSerialize(): array { return ['retail' => $this->retail, 'currency' => $this->currency, 'revision' => $this->revision]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('Persist price evidence through encrypted storage.'); }
}
