<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Typed defaults plus nullable per-offer overrides and explicit staff action stops. */
final readonly class Intent
{
    /** One explicit per-TLD start, shared by the registrar dialog and the TLD sales action.
     * Account defaults, term limits and pricing stay as they were. Independent evidence still gates sales. */
    public function allowSales(array $actions): self
    {
        if ($actions === [] || array_diff($actions, ['register', 'transfer']) !== []) {
            throw new \InvalidArgumentException('Acquisition actions required.');
        }
        return new self($this->defaultSell, $this->defaultLongest, $this->defaultTransfers, $this->maxAnnualCost,
            true, $this->longest, in_array('transfer', $actions, true) ? true : $this->transfers,
            array_values(array_diff($this->stops, ['renew', ...$actions])));
    }

    /** Translate the existing staff form without storing a second policy. */
    public static function fromRule(array $rule): self
    {
        $years = $rule['years'] ?? null; $rawCost = $rule['max_cost'] ?? null;
        $cost = $rawCost === null || $rawCost === '' ? null : PricingRule::wholesale((string) $rawCost);
        if (!in_array($rule['mode'] ?? null, ['all', 'except', 'only'], true)
            || !is_int($years) || $years < 1 || $years > 10 || !is_bool($rule['transfers'] ?? null)
            || (($rule['max_cost'] ?? '') !== '' && $cost === null)) {
            throw new \InvalidArgumentException('Invalid sell rule.');
        }
        return new self($rule['mode'] !== 'only', $years, $rule['transfers'], $cost);
    }

    public function __construct(public bool $defaultSell = true, public int $defaultLongest = 1,
        public bool $defaultTransfers = false, public ?string $maxAnnualCost = null,
        public ?bool $sell = null, public ?int $longest = null, public ?bool $transfers = null,
        public array $stops = [], public bool $salesPaused = false)
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
