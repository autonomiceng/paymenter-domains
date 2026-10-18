<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Shared local policy, never a provider price lock. Scheduler must use intervalSeconds. */
final readonly class CatalogueFreshness
{
    public function __construct(public int $intervalSeconds = 1800, public int $failureMarginSeconds = 1800)
    {
        if ($intervalSeconds < 1 || $failureMarginSeconds < 1 || $this->maxAge() > 2678400) {
            throw new \InvalidArgumentException('Invalid catalogue cadence.');
        }
    }
    public function maxAge(): int { return $this->intervalSeconds + $this->failureMarginSeconds; }
}
