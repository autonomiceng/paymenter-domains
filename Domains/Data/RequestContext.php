<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;

final class RequestContext
{
    public function __construct(public readonly AccountBinding $binding, public readonly RequestOrigin $origin,
        public readonly \DateTimeImmutable $deadline, public readonly RequestBudget $budget, private readonly Clock $clock = new SystemClock, private readonly ?\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter $limiter = null, private readonly bool $stopOnReadFailure = false) {}

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
        return $this->withinClaimedWire($driver,$wire);
    }

    private bool $reserved=false;
    private ?string $token=null;
    private ?\DateTimeImmutable $leaseUntil=null;
    /** Reserve the first physical exchange before financial sealing; unused reservations are released. */
    public function withReservedWire(\Closure $work): mixed
    {
        if($this->reserved){throw new \LogicException('Nested wire reservation.');}
        $this->leaseUntil=$this->clock->now()->modify('+30 seconds');$this->token=$this->limiter?->acquire($this->binding,$this->origin);$this->reserved=true;
        try{return $work();}finally{if($this->reserved&&$this->token!==null){$this->limiter->release($this->binding,$this->origin,$this->token);}$this->reserved=false;$this->token=null;$this->leaseUntil=null;}
    }
    public function requireReservedCapacity(): void
    {if(!$this->reserved||$this->leaseUntil<$this->clock->now()->modify('+25 seconds')){throw new \Paymenter\Extensions\Servers\Domains\Services\ReadDeferred('Reservation headroom exhausted.');}}
    /** Physical write exchanges already consumed their admitted per-operation budget. */
    public function withinClaimedWire(AccountBinding $driver, \Closure $wire): mixed
    {
        $driver->requireMatch($this->binding);
        $reserved=$this->reserved;$token=$reserved?$this->token:$this->limiter?->acquire($driver,$this->origin);if($reserved){$this->reserved=false;}
        try {
            $until=$reserved?min($this->deadline,$this->leaseUntil):$this->deadline;
            $remaining = (int) floor(((float)$until->format('U.u') - (float)$this->clock->now()->format('U.u')) * 1000);
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
