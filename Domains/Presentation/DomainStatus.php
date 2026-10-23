<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The one status set for a domain on client and staff pages, /services, the Dashboard and invoice lines.
 * Tones are Filament colours (success, info, warning, danger, gray). The reason line sits under the pill;
 * callers pass the real cause when the state alone cannot name it (held, uncertain, needs_review). */
final class DomainStatus
{
    /** key => [label, tone] */
    public const SET = [
        'active' => ['Active', 'success'], 'pending_registration' => ['Pending registration', 'info'], 'pending_transfer' => ['Pending transfer', 'info'],
        'action_required' => ['Action required', 'warning'], 'under_review' => ['Under review', 'warning'], 'expired' => ['Expired', 'danger'],
        'redemption' => ['Redemption', 'danger'], 'failed' => ['Failed', 'danger'], 'cancelled' => ['Cancelled', 'gray'],
    ];
    /** Internal state => [status key, default reason line]. Domain status column values, request states seen on a domain,
     * watch findings, and two derived states: renewal_off_expiring (auto-renew off, expiry within 30 days)
     * and active_no_expiry (active without a confirmed expiry). */
    public const STATES = [
        'active' => ['active', null], 'registration_pending' => ['pending_registration', null], 'transfer_pending' => ['pending_transfer', null],
        'awaiting_payment' => ['action_required', 'Invoice not paid'], 'awaiting_code' => ['action_required', 'Auth code needed'],
        'renewal_off_expiring' => ['action_required', 'Auto-renew off'], 'watch.ns_changed' => ['action_required', 'Nameservers changed'],
        'needs_review' => ['under_review', null], 'adoption_pending' => ['under_review', 'Import not confirmed'],
        'held' => ['under_review', null], 'uncertain' => ['under_review', null], 'active_no_expiry' => ['under_review', 'Expiry missing'],
        'expired' => ['expired', null], 'grace' => ['expired', 'In grace period'], 'watch.expired' => ['expired', 'Renew at your registrar'],
        'redemption' => ['redemption', null], 'watch.redemption' => ['redemption', null],
        'registration_rejected' => ['failed', 'Registration rejected'], 'transfer_rejected' => ['failed', 'Transfer rejected'],
        'cancelled' => ['cancelled', null],
    ];
    /** @return array{label:string,tone:string,reason:?string} */
    public static function for(?string $state, ?string $reason = null): array
    {
        [$key, $default] = self::STATES[$state] ?? ['under_review', 'Status missing'];
        [$label, $tone] = self::SET[$key];
        return ['label' => $label, 'tone' => $tone, 'reason' => $reason ?? $default];
    }
}
