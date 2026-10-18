<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;

final readonly class RequestContext
{
    public function __construct(public AccountBinding $binding, public RequestOrigin $origin,
        public \DateTimeImmutable $deadline, public RequestBudget $budget, private Clock $clock = new SystemClock) {}

    /** Drivers must call before each wire request, not just once per logical operation. */
    public function claimRead(#[\SensitiveParameter] AccountBinding $driver): int
    {
        $driver->requireMatch($this->binding);
        $now = $this->clock->now();
        $remaining = intdiv(((int) $this->deadline->format('U') - (int) $now->format('U')) * 1000000
            + (int) $this->deadline->format('u') - (int) $now->format('u'), 1000);
        if ($remaining < 1) { throw new \RuntimeException('Registrar request deadline reached.'); }
        $this->budget->consume();
        return $remaining;
    }

    /** Observation/freshness checks must use the same accepted clock as request deadlines. */
    public function now(): \DateTimeImmutable { return $this->clock->now(); }
}
