<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The status set for a registrar request. Tones are Filament colours. A held request's reason starts "Not sent:"
 * and keeps the real cause; a rejected request shows the registrar's reason. */
final class RequestStatus
{
    /** key => [label, tone] */
    public const SET = [
        'action_required' => ['Action required', 'warning'], 'in_progress' => ['In progress', 'info'], 'under_review' => ['Under review', 'warning'],
        'completed' => ['Completed', 'success'], 'failed' => ['Failed', 'danger'], 'cancelled' => ['Cancelled', 'gray'],
    ];
    /** Operation state => [status key, default reason line] */
    public const STATES = [
        'awaiting_payment' => ['action_required', 'Invoice not paid'], 'awaiting_code' => ['action_required', 'Auth code needed'],
        'queued' => ['in_progress', null], 'running' => ['in_progress', null], 'pending' => ['in_progress', null],
        'held' => ['under_review', 'Not sent'], 'uncertain' => ['under_review', 'Registrar has not confirmed the result'],
        'succeeded' => ['completed', null], 'rejected' => ['failed', null], 'disposed' => ['cancelled', null],
    ];
    /** @return array{label:string,tone:string,reason:?string} */
    public static function for(?string $state, ?string $cause = null): array
    {
        [$key, $default] = self::STATES[$state] ?? ['under_review', 'Status missing'];
        [$label, $tone] = self::SET[$key];
        $reason = $cause === null ? $default : ($state === 'held' ? 'Not sent: '.$cause : $cause);
        return ['label' => $label, 'tone' => $tone, 'reason' => $reason];
    }
}
