<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

/** Offer includes cost/facts/override changes; defaults include pricing/card fees; selection is per TLD. */
final readonly class Revision
{
    public function __construct(public int $offer, public int $defaults, public int $selection, public int $retailRule = Retail::VERSION)
    {
        if (min($offer, $defaults, $selection, $retailRule) < 0) { throw new \InvalidArgumentException('Invalid offer revision.'); }
    }
    public function matches(self $other): bool { return $this == $other; }
    public function requireMatch(self $expected): void
    {
        if (!$this->matches($expected)) { throw new \DomainException('Offer revision changed.'); }
    }
}
