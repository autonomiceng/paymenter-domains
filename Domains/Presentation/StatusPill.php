<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The one status pill a customer domain shows on the list and the detail header.
 * Precedence: needs action, expired, expiring, pending, active, then the closed states. Labels are one or two words.
 * Expiring means renewal is off and the confirmed expiry is within WINDOW days; auto-renew on is not a warning.
 * Unknown evidence never earns a pill: a watch is Active only when current registry facts give a future expiry. */
final class StatusPill
{
    public const WINDOW = 30;
    /** rank => [label, tone] */
    public const PILLS = [
        'action' => ['Needs action', 'warn'], 'expired' => ['Expired', 'bad'], 'expiring' => ['Expiring', 'warn'],
        'pending' => ['Pending', 'info'], 'active' => ['Active', 'ok'], 'cancelled' => ['Cancelled', 'muted'],
        'paused' => ['Checks paused', 'muted'], 'ended' => ['Watch ended', 'muted'],
    ];
    private const STATES = [
        'domain.review' => 'action', 'domain.awaiting_payment' => 'action', 'watch.ns_changed' => 'action', 'ns_changed' => 'action',
        'domain.expired' => 'expired', 'domain.redemption' => 'expired', 'watch.expired' => 'expired', 'watch.redemption' => 'expired', 'registry_expiry_reached' => 'expired',
        'adopt.pending' => 'pending', 'domain.pending' => 'pending', 'domain.transfer' => 'pending', 'domain.processing' => 'pending', 'watch.ns_pending' => 'pending', 'ns_pending' => 'pending',
        'domain.active' => 'active', 'domain.cancelled' => 'cancelled', 'watch.paused' => 'paused', 'watch.retired' => 'ended',
    ];
    /** OwnedDomain::$status labels from OwnedDomains. */
    private const STATUSES = [
        'Needs attention' => 'action', 'Awaiting payment' => 'action', 'Expired' => 'expired', 'In redemption' => 'expired',
        'Registry expiry reached' => 'expired', 'Being set up' => 'pending', 'Registration in progress' => 'pending',
        'Transfer in progress' => 'pending', 'Processing' => 'pending', 'Active' => 'active', 'Order cancelled' => 'cancelled',
    ];
    /** @return array{label:string,tone:string}|null */
    public static function summary(DomainSummary $s, ?string $today = null): ?array { return self::pick(self::ranks($s, $today)); }
    /** Detail header: the owned status and, when present, the summary states; the higher rank wins. */
    public static function owned(OwnedDomain $d, ?DomainSummary $s = null, ?string $today = null): ?array
    {
        $ranks = [self::STATUSES[$d->status] ?? 'action'];
        return self::pick([...$ranks, ...($s ? self::ranks($s, $today) : []), ...self::expiring($d->expiry, $d->renewal === 'Off', $today)]);
    }
    private static function ranks(DomainSummary $s, ?string $today): array
    {
        $ranks = array_values(array_intersect_key(self::STATES, array_flip($s->stateKeys)));
        if ($s->kind === 'watch' && $s->expiry && !($s->freshness['registry']['stale'] ?? true)) { $ranks[] = 'active'; }
        return [...$ranks, ...self::expiring($s->expiry, $s->renewalOffShown(), $today)];
    }
    private static function expiring(?string $expiry, bool $renewalOff, ?string $today): array
    {
        if (!$expiry || !$renewalOff) { return []; }
        $today ??= \Carbon\CarbonImmutable::now('UTC')->format('Y-m-d');
        $day = substr($expiry, 0, 10);
        return $day > $today && $day <= \Carbon\CarbonImmutable::parse($today, 'UTC')->addDays(self::WINDOW)->format('Y-m-d') ? ['expiring'] : [];
    }
    private static function pick(array $ranks): ?array
    {
        foreach (self::PILLS as $rank => [$label, $tone]) { if (in_array($rank, $ranks, true)) { return ['label' => $label, 'tone' => $tone]; } }
        return null;
    }
}
