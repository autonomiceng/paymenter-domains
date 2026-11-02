<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

/** Pure evaluator. Dormant until the checked catalogue cutover. */
final class Evaluator
{
    public function evaluate(Snapshot $offer, Query $query, Context $context, \DateTimeImmutable $now): Decision
    {
        return new Decision($offer->feed->tld(), $query->action, $query->term, false, null, $offer->revision);
    }
}
