<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** The framework instant a native fixture boots at, so migrated rows and snapshots carry the same times in every process. */
const NATIVE_FIXTURE_INSTANT = '2026-10-18T12:00:00Z';

/** Fix the framework clock, which Laravel timestamps and cache expiry read, for the boot. */
function nativeFixtureClock(): void
{
    Carbon\CarbonImmutable::setTestNow(new DateTimeImmutable(NATIVE_FIXTURE_INSTANT));
}

/**
 * After the boot, the framework clock reads the test's injected time: the instant of a bound FakeClock, so a test that
 * moves its FakeClock moves Laravel timestamps with it. A test that binds the system clock gets wall time, which its
 * raw time() reads also see. A test may still set its own framework instant.
 */
function nativeFixtureClockFollow(): void
{
    Carbon\CarbonImmutable::setTestNow(static function (DateTimeInterface $wall): DateTimeInterface {
        $app = Illuminate\Container\Container::getInstance();
        $clock = $app->bound(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class) ? $app->make(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class) : null;
        // The instant is read, not now(): a test that counts its clock's reads sees only product reads.
        return $clock instanceof Paymenter\Extensions\Servers\Domains\Tests\FakeClock ? $clock->instant : $wall;
    });
}
