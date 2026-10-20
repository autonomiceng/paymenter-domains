<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** D3 customer tones. Public expiry is a warning; known lifecycle expiry is bad. */
final class DomainSummaryTones
{
    public static function for(string $key): string
    {
        return match ($key) {
            'domain.registration.platform','domain.dns.platform','domain.points.website','domain.points.email',
            'domain.points.both','domain.points.platform_empty','domain.active' => 'ok',
            'domain.registration.external','domain.dns.external','domain.points.mixed','domain.points.external_empty',
            'adopt.pending','adopt.provenance','adopt.activity','domain.transfer','domain.pending','domain.processing',
            'watch.ns_pending','ns_pending' => 'info',
            'domain.awaiting_payment','domain.review','watch.expired','registry_expiry_reached','watch.ns_changed',
            'ns_changed','watch.stale','watch.partial','domain.points.partial' => 'warn',
            'domain.expired','domain.redemption','watch.redemption' => 'bad',
            'domain.registration.unknown','domain.dns.unknown','domain.points.unknown','domain.points.help',
            'domain.cancelled','adopt.renewal_off','watch.paused','watch.retired','watch.not_checked',
            'watch.unknown','watch.no_coverage','watch.not_registered','dns_unavailable','expiry_unavailable',
            'adopt.pending_notice','watch.ns_changed_notice' => 'muted',
            default => 'muted',
        };
    }
}
