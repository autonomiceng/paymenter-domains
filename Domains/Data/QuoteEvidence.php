<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

final readonly class QuoteEvidence
{
    public function __construct(public Query $query, public ?Money $cost, public CostUnit $unit,
        public string $source, public \DateTimeImmutable $observedAt, public ?\DateTimeImmutable $validUntil = null)
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $source) || ($validUntil !== null && $validUntil < $observedAt)) {
            throw new \InvalidArgumentException('Invalid quote provenance or validity.');
        }
        // An annual quote is not an undocumented exact multi-year total.
        if ($unit === CostUnit::Annual && $query->years !== 1) { throw new \InvalidArgumentException('Annual cost evidence requires an annual query.'); }
    }
}
