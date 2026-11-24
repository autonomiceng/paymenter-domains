<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
final class DomainSummaryStrings
{
    public static function all(): array
    {
        return [
            'domain.registration.platform' => 'Registered with us',
            'domain.registration.unknown' => 'Registration details unavailable',
            'adopt.pending_notice' => 'We need to verify this registration before handling renewals.',
        ];
    }
}
