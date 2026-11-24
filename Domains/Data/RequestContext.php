<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;

final class RequestContext
{
    public function __construct(public readonly AccountBinding $binding, public readonly RequestOrigin $origin,
        public readonly \DateTimeImmutable $deadline, public readonly RequestBudget $budget, private readonly Clock $clock = new SystemClock, private readonly ?\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter $limiter = null, private readonly bool $stopOnReadFailure = false, public readonly bool $readNameservers = false, private readonly ?\Closure $current = null,
        public readonly bool $deferOnReservationLimit = false, private readonly ?string $observationName = null) {}

    /** Parsed primary replies for record discovery in this account-bound observation only. */
    private array $observationReplies = [];
    /** @param array<string,object> $replies */
    public function retainObservationReplies(string $name, #[\SensitiveParameter] array $replies): void
    {
        if ($name === $this->observationName) { $this->observationReplies = $replies; }
    }
    public function observationReply(string $name, string $command): ?object
    {
        return $name === $this->observationName ? ($this->observationReplies[$command] ?? null) : null;
    }

    /** Drivers must call before each wire request, not just once per logical operation. */
    public function claimRead(#[\SensitiveParameter] AccountBinding $driver): int
    {
        if($this->current){($this->current)();}
        $driver->requireMatch($this->binding);
        $now = $this->clock->now();
        $remaining = intdiv(((int) $this->deadline->format('U') - (int) $now->format('U')) * 1000000
            + (int) $this->deadline->format('u') - (int) $now->format('u'), 1000);
        if ($remaining < 1) { throw new RequestDeadlineReached('Registrar request deadline reached.'); }
        $this->budget->consume();
        return $remaining;
    }

    private int $completedReads = 0;
    public function completedReads(): int { return $this->completedReads; }
    private int $exchanges = 0;
    /** Physical exchanges entered, failed ones included. Reader construction, limiter and send refusals never count. */
    public function exchanges(): int { return $this->exchanges; }
    private ?DomainSnapshot $seenDomain = null;
    private ?\Closure $observer = null;
    /** Called with each owned expiry and charge candidate as its read returns (RenewalProof saves renewal evidence). */
    public function observe(\Closure $observer): void { $this->observer = $observer; }
    /** Renewal proof sink: every registrar read reports here what it saw, before any later read in the check can fail. */
    public function sawDomain(DomainSnapshot $domain): void
    {
        if ($domain->owned !== true || $domain->expiry === null) { return; }
        if ($this->observer) { ($this->observer)($domain); }
        if ($this->seenDomain === null || substr($domain->expiry->value, 0, 10) > substr($this->seenDomain->expiry->value, 0, 10)) { $this->seenDomain = $domain; }
    }
    public function sawCandidate(OrderEvidence $candidate): void
    { if ($this->observer) { ($this->observer)($candidate); } }

    public function highestExpiry(): ?DomainSnapshot { return $this->seenDomain; }
    private int $failedExchanges = 0;
    /** Exchanges that got no registrar reply (transport failure or timeout). A registrar error reply is not one. */
    public function failedExchanges(): int { return $this->failedExchanges; }
    public function exchangeFailed(): void { $this->failedExchanges++; }
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
            if ($remaining < 1) { throw new RequestDeadlineReached('Registrar request deadline reached.'); }
            if($this->current){($this->current)();}
            // A refusal raised inside the wire before sending is not an exchange; any other failure may have reached the registrar.
            $this->exchanges++;
            try { return $wire($remaining); }
            catch (\Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit|\Paymenter\Extensions\Servers\Domains\Services\LiveReservationLimit|\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred $refused) { $this->exchanges--; throw $refused; }
        }
        finally { if ($token !== null) { $this->limiter->release($driver, $this->origin, $token); } }
    }

    /** Observation opts into conservative failure holds even when adapters return failure values. */
    public function requireReadSuccess(bool $successful, #[\SensitiveParameter] ?\RuntimeException $failure=null): void
    { if ($successful) { $this->completedReads++; } if ($this->stopOnReadFailure&&!$successful) { throw $failure??new \DomainException('Registrar read failed.'); } }

    /** Observation/freshness checks must use the same accepted clock as request deadlines. */
    public function now(): \DateTimeImmutable { return $this->clock->now(); }
}
