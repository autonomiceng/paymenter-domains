<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

/** Wire count is provider policy, not a scheduler's concrete-driver branch. */
interface PricedFeed extends Feed
{
    public function priceReads(): int;
}
