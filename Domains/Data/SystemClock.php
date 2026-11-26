<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
use Carbon\CarbonImmutable;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
/** Wall time from the framework clock, so a fixed framework test instant also fixes Domains time. */
final class SystemClock implements Clock
{
    private ?\DateTimeImmutable $origin = null;
    private int $tick = 0;
    public function now(): \DateTimeImmutable
    {
        return $this->origin === null || self::framework() && CarbonImmutable::hasTestNow() ? self::wall(date_default_timezone_get())
            : $this->origin->modify('+'.intdiv(hrtime(true) - $this->tick, 1000).' microseconds')
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
    /** Keep one execution monotonic, then return to wall time between daemon jobs. */
    public function withinExecution(\Closure $work): mixed
    {
        if ($this->origin !== null) { throw new \LogicException('Nested execution clock scope.'); }
        $this->origin = self::wall('UTC'); $this->tick = hrtime(true);
        try { return $work(); } finally { $this->origin = null; }
    }
    private static function framework(): bool { return class_exists(CarbonImmutable::class); }
    private static function wall(string $zone): \DateTimeImmutable
    {
        return self::framework() ? CarbonImmutable::now($zone)->toDateTimeImmutable() : new \DateTimeImmutable('now', new \DateTimeZone($zone));
    }
}
