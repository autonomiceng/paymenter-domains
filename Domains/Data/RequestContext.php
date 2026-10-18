<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;

final readonly class RequestContext
{
    public function __construct(public AccountBinding $binding, public RequestOrigin $origin,
        public \DateTimeImmutable $deadline, public RequestBudget $budget, private Clock $clock = new SystemClock, private ?\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter $limiter = null, private bool $stopOnReadFailure = false) {}

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

    /** Both real read clients use this scope once per physical request. */
    public function withinRead(AccountBinding $driver, \Closure $wire): mixed
    {
        $this->claimRead($driver);
        $token = $this->limiter?->acquire($driver, $this->origin);
        try {
            $remaining = (int) floor(((float)$this->deadline->format('U.u') - (float)$this->clock->now()->format('U.u')) * 1000);
            if ($remaining < 1) { throw new \RuntimeException('Registrar request deadline reached.'); }
            return $wire($remaining);
        }
        finally { if ($token !== null) { $this->limiter->release($driver, $this->origin, $token); } }
    }

    /** Observation opts into conservative failure holds even when adapters return failure values. */
    public function requireReadSuccess(bool $successful): void
    { if ($this->stopOnReadFailure&&!$successful) { throw new \DomainException('Registrar read failed.'); } }

    /** Observation/freshness checks must use the same accepted clock as request deadlines. */
    public function now(): \DateTimeImmutable { return $this->clock->now(); }
}
