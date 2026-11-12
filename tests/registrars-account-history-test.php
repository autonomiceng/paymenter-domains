<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support {
    // Staff expiry checks use PHP's UTC date, which Carbon's test clock cannot freeze.
    function gmdate(string $format, ?int $timestamp = null): string
    { return \gmdate($format,$timestamp??app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp()); }
}
namespace {
    $registrarsPart='account';require __DIR__.'/Support/registrar-pages.php';
}
