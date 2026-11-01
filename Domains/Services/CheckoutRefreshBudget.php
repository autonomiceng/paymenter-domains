<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** One monotonic deadline shared by pacing, coalescing and every read in a cart batch. */
final class CheckoutRefreshBudget
{
    private readonly int $until;
    public function __construct() { $this->until = hrtime(true) + 15000000000; }
    public function remainingMilliseconds(): int
    {
        $remaining = (int) floor(($this->until - hrtime(true)) / 1000000);
        if ($remaining < 1) { throw new CheckoutRefreshTimeout; }
        return $remaining;
    }
    public function wait(int $microseconds): void
    {
        if ($microseconds > $this->remainingMilliseconds() * 1000) { throw new CheckoutRefreshTimeout; }
        usleep($microseconds);
        $this->remainingMilliseconds();
    }
}
