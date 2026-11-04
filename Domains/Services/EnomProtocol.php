<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory;

/** Deployment route and pacing policy. This grants no account, feature or order authority. */
final class EnomProtocol
{
    public static function accepts(AccountBinding $binding, array $protocol): bool
    {
        if ($binding->registrarKey !== 'enom') { return false; }
        $expected = RuntimeFactory::registrationAcceptance($binding);
        return ($protocol['batch_route'] ?? null) === true && $expected['batch_route'] === true
            && ($protocol['route'] ?? null) === $expected['route']
            && is_int($protocol['interval_seconds'] ?? null)
            && $protocol['interval_seconds'] >= 25 && $protocol['interval_seconds'] <= 86400
            && $protocol['interval_seconds'] === $expected['interval_seconds'];
    }
}
