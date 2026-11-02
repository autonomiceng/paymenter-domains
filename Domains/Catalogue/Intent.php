<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Typed defaults plus nullable per-offer overrides and explicit staff action stops. */
final readonly class Intent
{
    public function __construct(public bool $defaultSell = true, public int $defaultLongest = 1,
        public bool $defaultTransfers = false, public ?string $maxAnnualCost = null,
        public ?bool $sell = null, public ?int $longest = null, public ?bool $transfers = null,
        public array $stops = [])
    {
        foreach ([$defaultLongest, $longest ?? $defaultLongest] as $years) {
            if ($years < 1 || $years > 10) { throw new \InvalidArgumentException('Invalid intended term.'); }
        }
        if ($maxAnnualCost !== null) { PricingRule::units($maxAnnualCost); }
        if (array_diff($stops, ['register', 'renew', 'transfer']) !== []) {
            throw new \InvalidArgumentException('Invalid staff action stop.');
        }
    }
}
