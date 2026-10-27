<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** One check job, including all names and chain hops. Does not interrupt native resolver calls. */
final class DnsContext
{
    public const MAX_QUERIES = 10;
    public const DEADLINE_SECONDS = 45;
    private \Closure $clock;
    private float $started;
    private float $last;
    private int $used = 0;
    private bool $failed = false;
    private int $dohFailures = 0;
    private bool $prepaid = false;
    private int $fallbackSlot = 0;
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn(): float => hrtime(true) / 1e9 : \Closure::fromCallable($clock);
        $this->started = $this->last = ($this->clock)();
        if (!is_finite($this->started)) { throw new \InvalidArgumentException('Invalid DNS clock.'); }
    }
    public function live(): bool
    {
        $now = ($this->clock)();
        if (!is_finite($now) || $now < $this->last || $now >= $this->started + self::DEADLINE_SECONDS) { $this->failed = true; }
        $this->last = $now;
        return !$this->failed;
    }
    public function reserve(): bool
    {
        if ($this->prepaid && $this->live()) { $this->prepaid = false; return true; }
        if (!$this->live() || $this->used >= self::MAX_QUERIES) { return false; }
        $this->used++;
        return true;
    }
    public function remaining(): float { return $this->live() ? max(0.0, $this->started + self::DEADLINE_SECONDS - $this->last) : 0.0; }
    public function dohFailed(): void { $this->dohFailures++; }
    public function dohOpen(): bool { return $this->dohFailures >= 2; }
    /** Exactly one native attempt replaces a failed DoH transport in the slot already reserved. */
    public function fallback(callable $read): DnsAnswer
    {
        if ($this->prepaid || $this->used < 1 || $this->fallbackSlot === $this->used) { throw new \LogicException('A reserved DNS slot is required.'); }
        $this->fallbackSlot = $this->used; $this->prepaid = true;
        try { return $read(); } finally { $this->prepaid = false; }
    }
    public function used(): int { return $this->used; }
}
