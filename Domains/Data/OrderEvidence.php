<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** A candidate is not proof of unique operation correlation; provider references may be absent. */
final readonly class OrderEvidence
{
    public function __construct(public AccountBinding $binding, public Query $query,
        public ?Money $charge = null, public ?string $providerReference = null,
        public ?DateEvidence $processedAt = null, public ?DateEvidence $afterExpiry = null)
    {
        if ($providerReference !== null && !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $providerReference)) {
            throw new \InvalidArgumentException('Invalid opaque order reference.');
        }
    }
}
