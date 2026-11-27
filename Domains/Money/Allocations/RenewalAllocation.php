<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Allocations;

/** A native invoice line or signed payment posting, identified by its native row key. */
final readonly class RenewalAllocation
{
    public function __construct(public PostingSource $source, public int $postingId, public int $amountMinor) {}
}
