<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
/** Expected holding anchor selected by the caller; never funding or dispatch authority. */
final readonly class RenewalRequest
{
    public function __construct(public int $domainId, public int $generation, public int $revision,
        public string $expiry, public ?string $providerId, public int $years = 1, public ?string $obligationKey = null)
    {
        new DateEvidence($expiry, ExpiryPrecision::Date, 'saved_anchor');
        if ($domainId < 1 || $generation < 1 || $revision < 1 || $years < 1 || $years > 10
            || ($obligationKey !== null && !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $obligationKey))
            || ($providerId !== null && !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $providerId))) {
            throw new \InvalidArgumentException('Invalid renewal anchor.');
        }
    }
}
