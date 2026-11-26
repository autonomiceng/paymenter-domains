<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** The business instant every booted fixture starts at, before migrations write their first row. */
const NATIVE_FIXTURE_INSTANT = '2026-10-18T12:00:00Z';

/**
 * Freeze the framework clock, which Laravel timestamps, cache expiry and the default Domains clock all read.
 * A test that moves time sets its own instant explicitly; nothing in a booted fixture reads the wall clock.
 */
function nativeFixtureClock(): void
{
    $instant = new DateTimeImmutable(NATIVE_FIXTURE_INSTANT);
    Carbon\Carbon::setTestNow($instant);
    Carbon\CarbonImmutable::setTestNow($instant);
}
