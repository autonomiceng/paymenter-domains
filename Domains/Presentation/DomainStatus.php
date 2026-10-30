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
     * watch findings, and derived states: renewal_off_expiring (auto-renew off, expiry within 30 days),
     * active_no_expiry (active without a confirmed expiry) and setup_required (held until the customer finishes setup). */
    public const STATES = [
        'active' => ['active', null], 'registration_pending' => ['pending_registration', null], 'transfer_pending' => ['pending_transfer', null],
        'awaiting_payment' => ['action_required', 'Invoice not paid'], 'awaiting_code' => ['action_required', 'Auth code needed'],
        'renewal_off_expiring' => ['action_required', 'Auto-renew off'], 'watch.ns_changed' => ['action_required', 'Nameservers changed'],
        'setup_required' => ['action_required', 'Finish setup'], 'watch.not_registered' => ['action_required', 'Not registered'],
        'needs_review' => ['under_review', null], 'adoption_pending' => ['under_review', 'Import not confirmed'],
        'held' => ['under_review', null], 'uncertain' => ['under_review', null], 'active_no_expiry' => ['under_review', 'Expiry missing'],
        'expired' => ['expired', null], 'grace' => ['expired', 'In grace period'], 'watch.expired' => ['expired', 'Renew at your registrar'],
        'redemption' => ['redemption', null], 'watch.redemption' => ['redemption', null],
        'registration_rejected' => ['failed', 'Registration did not finish'], 'transfer_rejected' => ['failed', 'Transfer did not finish'],
        'cancelled' => ['cancelled', null],
    ];
    /** @return array{label:string,tone:string,reason:?string} */
    public static function for(?string $state, ?string $reason = null): array
    {
        [$label, $tone] = self::SET[self::key($state)];
        return ['label' => $label, 'tone' => $tone, 'reason' => $reason ?? (self::STATES[$state] ?? [null, 'Status missing'])[1]];
    }
    /** The SET key a state belongs to; unknown states count as under_review. */
    public static function key(?string $state): string { return (self::STATES[$state] ?? ['under_review'])[0]; }
}
