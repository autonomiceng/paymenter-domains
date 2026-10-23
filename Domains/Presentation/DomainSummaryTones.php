<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Staff watch finding tones. Customer status pills use DomainStatus through StatusPill. */
final class DomainSummaryTones
{
    public static function for(string $key): string
    {
        return match ($key) {
            'watch.ns_pending' => 'info',
            'watch.expired','watch.ns_changed','watch.stale','watch.partial' => 'warn',
            'watch.redemption' => 'bad',
            default => 'muted',
        };
    }
}
