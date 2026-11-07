<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Public registry restriction. A lock end is not proof that every other transfer requirement is met. */
final class TransferRestriction extends \DomainException
{
    public function __construct(string $reason, public readonly ?\DateTimeImmutable $retryAt = null)
    {
        parent::__construct($reason);
    }
}
