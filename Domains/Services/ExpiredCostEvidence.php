<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Saved catalogue context only; never contains provider response text. */
final class ExpiredCostEvidence extends \DomainException
{
    public function __construct(public readonly int $serverId, public readonly string $tld,
        public readonly string $action, public readonly int $term, public readonly \DateTimeImmutable $freshUntil)
    {
        parent::__construct('Saved cost evidence expired.');
    }
}
