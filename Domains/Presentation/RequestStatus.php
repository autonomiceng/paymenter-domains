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
    /** Operation state => [status key, default reason line] */
    public const STATES = [
        'awaiting_payment' => ['waiting_payment', 'Customer has not paid the invoice. Not sent yet.'], 'awaiting_code' => ['action_required', 'Auth code needed'],
        'queued' => ['in_progress', null], 'running' => ['in_progress', null], 'pending' => ['in_progress', null],
        'held' => ['on_hold', 'Not sent'], 'uncertain' => ['under_review', 'Registrar has not confirmed the result'],
        'succeeded' => ['completed', null], 'rejected' => ['failed', null], 'disposed' => ['cancelled', null], 'cancelled' => ['cancelled', null],
    ];
    /** Rejected requests that ended on purpose: closed unpaid, cancelled by staff, or cancelled with account credit. They read Cancelled, not Failed. */
    public const CLOSED_CODES = ['acquisition_cancelled_unpaid', 'renewal_cancelled_unpaid', 'refund_approved', 'management_retired'];
    public static function closed(?string $state, ?string $code): bool
    { return $state === 'rejected' && in_array($code, self::CLOSED_CODES, true); }
    /** @return array{label:string,tone:string,reason:?string} */
    public static function for(?string $state, ?string $cause = null): array
    {
        [$key, $default] = self::STATES[$state] ?? ['under_review', 'Status missing'];
        [$label, $tone] = self::SET[$key];
        $reason = $cause === null ? $default : ($state === 'held' ? 'Not sent: '.$cause : $cause);
        return ['label' => $label, 'tone' => $tone, 'reason' => $reason];
    }
}
