<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

// This test process controls both budget time and the injected registrar clock.
$GLOBALS['checkout_fake_tick'] = 0;
function hrtime(bool $asNumber = false): int|array
{
    $tick = $GLOBALS['checkout_fake_tick'];
    return $asNumber ? $tick : [intdiv($tick, 1000000000), $tick % 1000000000];
}
function usleep(int $microseconds): void { $GLOBALS['checkout_fake_tick'] += $microseconds * 1000; }
