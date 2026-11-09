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
        'awaiting_payment' => ['Awaiting payment', 'info'], 'action_required' => ['Action required', 'warning'], 'under_review' => ['Under review', 'warning'], 'expired' => ['Expired', 'danger'],
        'redemption' => ['Redemption', 'danger'], 'failed' => ['Failed', 'danger'], 'cancelled' => ['Cancelled', 'gray'],
    ];
    /** Shared support fallback for a managed domain past expiry. */
    public const LATE = 'Contact support to check whether it can still be renewed';
    /** A stopped registration needs nothing from the customer. Staff get their step instead. */
    public const ON_IT = 'We are on it, nothing for you to do';
    /** A stopped registration keeps the Pending registration word for everyone, so the tab and its rows agree (approved mock).
     * The hold shows in the reason line: staff read their step, customers read ON_IT. Staff must act, so it is amber. state => tone */
    public const STOPPED = ['registration_held' => 'warning', 'registration_unconfirmed' => 'warning'];
    /** Internal state => [status key, default reason line]. Domain status column values, request states seen on a domain,
     * watch findings, and derived states: renewal_off_expiring (auto-renew off, expiry within 30 days),
     * active_no_expiry (active without a confirmed expiry) and setup_required (held until the customer finishes setup). */
    public const STATES = [
        'active' => ['active', null], 'registration_pending' => ['pending_registration', null], 'transfer_pending' => ['pending_transfer', null],
        'registration_held' => ['pending_registration', self::ON_IT], 'registration_unconfirmed' => ['pending_registration', self::ON_IT],
        'awaiting_payment' => ['awaiting_payment', 'Invoice not paid'], 'awaiting_code' => ['action_required', 'Auth code needed'],
        'renewal_off_expiring' => ['action_required', 'Auto-renew off'], 'watch.ns_changed' => ['action_required', 'Nameservers changed'],
        'setup_required' => ['action_required', 'Finish setup'], 'watch.not_registered' => ['action_required', 'Not registered'],
        'needs_review' => ['under_review', null], 'adoption_pending' => ['under_review', 'Import not confirmed'],
        'held' => ['under_review', null], 'uncertain' => ['under_review', null], 'active_no_expiry' => ['under_review', 'Expiry missing'],
        'expired' => ['expired', self::LATE], 'grace' => ['expired', self::LATE], 'watch.expired' => ['expired', 'Renew at your registrar'],
        'redemption' => ['redemption', self::LATE], 'watch.redemption' => ['redemption', 'Restore at your registrar'],
        'registration_rejected' => ['failed', 'Registration did not finish'], 'transfer_rejected' => ['failed', 'Transfer did not finish'],
        'cancelled' => ['cancelled', null],
    ];
    /** @return array{label:string,tone:string,reason:?string} */
    public static function for(?string $state, ?string $reason = null): array
    {
        [$label, $tone] = self::SET[self::key($state)];
        return ['label' => $label, 'tone' => self::STOPPED[$state] ?? $tone, 'reason' => $reason ?? (self::STATES[$state] ?? [null, self::ON_IT])[1]];
    }
    /** The SET key a state belongs to; unknown states count as under_review. */
    public static function key(?string $state): string { return (self::STATES[$state] ?? ['under_review'])[0]; }
    /** Statuses that give the customer a step (pay, act, contact support). A page showing one never says there is nothing for them to do. */
    public const CUSTOMER_STEP = ['awaiting_payment', 'action_required', 'expired', 'redemption', 'failed'];
    /** True when this status label gives the customer a step. */
    public static function asksCustomer(?string $label): bool
    {
        foreach (self::CUSTOMER_STEP as $key) { if (self::SET[$key][0] === $label) { return true; } }
        return false;
    }
}
