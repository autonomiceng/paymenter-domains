<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Paymenter\Extensions\Servers\Domains\Data\Money;

/** Frozen facts the feature proved under its locks before the seal. */
final readonly class SealTerms
{
    public function __construct(public int $operationRevision, public string $requestDigest, public Money $authorized,
        public string $mode, public \DateTimeImmutable $dispatchUntil)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $requestDigest) || !preg_match('/^[a-z_]{1,16}$/D', $mode)) {
            throw new \InvalidArgumentException('Invalid seal terms.');
        }
    }
}
