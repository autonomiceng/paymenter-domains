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
    public function claimRead(AccountBinding $driver): void
    {
        $driver->requireMatch($this->binding);
        if ($this->clock->now() >= $this->deadline) { throw new \RuntimeException('Registrar request deadline reached.'); }
        $this->budget->consume();
    }
}
