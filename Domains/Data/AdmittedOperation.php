<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Contract descriptor, not an admission engine. No real transport consumes this foundation type. */
final readonly class AdmittedOperation
{
    public Money $authorizedCost;
    public function __construct(public AccountBinding $binding, public Query $query, public string $operationKey,
        public string $claimKey, public int $authorityGeneration, public string $fundingEvidenceKey,
        public string $desiredStateDigest, ?Money $authorizedCost, public ?DateEvidence $beforeExpiry = null)
    {
        foreach ([$operationKey, $claimKey, $fundingEvidenceKey] as $key) {
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $key)) { throw new \InvalidArgumentException('Invalid admitted operation reference.'); }
        }
        if ($authorizedCost === null || ($query->action === Action::Renew && $beforeExpiry === null)) {
            throw new \InvalidArgumentException('Spending requires known authorized cost and renewal expiry precondition.');
        }
        $this->authorizedCost = $authorizedCost;
        if ($authorityGeneration < 1 || !preg_match('/^[a-f0-9]{64}$/D', $desiredStateDigest)) {
            throw new \InvalidArgumentException('Invalid admitted generation or desired-state digest.');
        }
    }
}
