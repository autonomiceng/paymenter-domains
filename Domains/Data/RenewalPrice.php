<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Billing retail for the frozen holding account, not fresh cost or dispatch authority. */
final readonly class RenewalPrice implements \JsonSerializable
{
    public function __construct(public int $id, public int $revision, public string $retail,
        public string $currency, public bool $retained, public ?string $staffAlert) {}
    public function jsonSerialize(): array { return ['retail' => $this->retail, 'currency' => $this->currency, 'retained' => $this->retained]; }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('Persist renewal evidence through encrypted storage.'); }
}
