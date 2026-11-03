<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The status set for a registrar request. Tones are Filament colours. A held request's reason starts "Not sent:"
 * and keeps the real cause; a rejected request shows the registrar's reason. Only the customer can pay an invoice,
 * so an unpaid request waits rather than asking staff to act. */
final class RequestStatus
{
    /** key => [label, tone] */
    public const SET = [
        'waiting_payment' => ['Awaiting payment', 'info'], 'action_required' => ['Action required', 'warning'], 'in_progress' => ['In progress', 'info'], 'on_hold' => ['On hold', 'warning'], 'under_review' => ['Under review', 'warning'],
        'completed' => ['Completed', 'success'], 'failed' => ['Failed', 'danger'], 'cancelled' => ['Cancelled', 'gray'],
    ];
    /** The registrar said no. Customers and staff read this same sentence, each with their own next step. */
    public const REFUSED = 'The registrar did not take this change.';
    /** The result of a change that may have reached the registrar is not known yet. */
    public const UNCONFIRMED = 'Registrar has not confirmed the result';
    /** ManagementEvidence::outcome reads these. Customers and staff read the same sentence. */
    public const UNSENT = 'Not sent. Nothing changed at the registrar.';
    public const NOT_MADE = 'We couldn’t make this change.';
    /** Operation state => [status key, default reason line] */
    public const STATES = [
        'awaiting_payment' => ['waiting_payment', 'Customer has not paid the invoice. Not sent yet.'], 'awaiting_code' => ['action_required', 'Auth code needed'],
        'queued' => ['in_progress', null], 'running' => ['in_progress', null], 'pending' => ['in_progress', null],
        'held' => ['on_hold', 'Not sent'], 'uncertain' => ['under_review', self::UNCONFIRMED],
        'succeeded' => ['completed', null], 'rejected' => ['failed', null], 'disposed' => ['cancelled', null], 'cancelled' => ['cancelled', null],
    ];
    /** A request in any other state is still open. */
    public const FINISHED = ['succeeded', 'rejected', 'disposed', 'cancelled'];
    /** Rejected requests that ended on purpose: closed unpaid, cancelled by staff, or cancelled with account credit. They read Cancelled, not Failed. */
    public const CLOSED_CODES = ['acquisition_cancelled_unpaid', 'renewal_cancelled_unpaid', 'refund_approved', 'management_retired', 'name_unavailable', 'premium_name', 'order_not_supported'];
    public static function closed(?string $state, ?string $code): bool
    { return $state === 'rejected' && in_array($code, self::CLOSED_CODES, true); }
    /** Money that reached an early renewal after it closed. Customers and staff read the same words. */
    public static function lateCredit(string $amount): string
    { return 'Paid after this invoice closed. We added '.$amount.' to account credit. You can apply it on an invoice.'; }
    /** One sentence for an unpaid early renewal, on both sides. */
    public static function earlyRenewal(string $due): string
    { return 'Early renewal not paid. Nothing is due until '.gmdate('M j, Y', strtotime($due.' 00:00:00 UTC')).'.'; }
    /** @return array{label:string,tone:string,reason:?string} */
    public static function for(?string $state, ?string $cause = null): array
    {
        [$key, $default] = self::STATES[$state] ?? ['under_review', 'Status missing'];
        [$label, $tone] = self::SET[$key];
        $reason = $cause === null ? $default : ($state === 'held' ? 'Not sent: '.$cause : $cause);
        return ['label' => $label, 'tone' => $tone, 'reason' => $reason];
    }
}
