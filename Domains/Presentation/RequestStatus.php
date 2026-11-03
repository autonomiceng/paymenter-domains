<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The status set for a registrar request. Tones are Filament colours. A held request's reason starts "Not sent:"
 * and keeps the real cause; a rejected request shows the registrar's reason. Only the customer can pay an invoice,
 * so an unpaid request waits rather than asking staff to act. */
final class RequestStatus
{
    /** key => [label, tone]. A word a domain also shows is DomainStatus's word: one map for the domain head and its requests.
     * Only In progress and Completed belong to requests alone. */
    public const SET = [
        'awaiting_payment' => DomainStatus::SET['awaiting_payment'], 'action_required' => DomainStatus::SET['action_required'], 'in_progress' => ['In progress', 'info'], 'under_review' => DomainStatus::SET['under_review'],
        'completed' => ['Completed', 'success'], 'failed' => DomainStatus::SET['failed'], 'cancelled' => DomainStatus::SET['cancelled'],
    ];
    /** The registrar said no. Customers and staff read this same sentence, each with their own next step. */
    public const REFUSED = 'The registrar did not take this change.';
    /** The result of a change that may have reached the registrar is not known yet. */
    public const UNCONFIRMED = 'Registrar has not confirmed the result';
    /** ManagementEvidence::outcome reads these. Customers and staff read the same sentence. */
    public const UNSENT = 'Not sent. Nothing changed at the registrar.';
    public const NOT_MADE = 'We couldn’t make this change.';
    /** Operation state => [status key, default reason line]. A state a domain also has takes its key from DomainStatus::STATES,
     * so a held request reads Under review, as its domain does, for every request kind. */
    public const STATES = [
        'awaiting_payment' => [DomainStatus::STATES['awaiting_payment'][0], 'Customer has not paid the invoice. Not sent yet.'], 'awaiting_code' => [DomainStatus::STATES['awaiting_code'][0], 'Auth code needed'],
        'queued' => ['in_progress', null], 'running' => ['in_progress', null], 'pending' => ['in_progress', null],
        'held' => [DomainStatus::STATES['held'][0], 'Not sent'], 'uncertain' => [DomainStatus::STATES['uncertain'][0], self::UNCONFIRMED],
        'succeeded' => ['completed', null], 'rejected' => ['failed', null], 'disposed' => ['cancelled', null], 'cancelled' => [DomainStatus::STATES['cancelled'][0], null],
    ];
    /** Held until the customer finishes setup. Its domain reads setup_required (DomainStates::managed). */
    public const SETUP_CODES = ['contact_details_required', 'nameservers_required'];
    /** Requests that read another state's word, and when: word state => [request state, safe codes (null: any), only while Orders are off].
     * Held for setup reads Action required, as its domain does. A queued request that Orders off stop is held for staff. A rejection
     * closed on purpose reads Cancelled. The request word (Labels::requestStatus) and the Status filter (OperationQueries::status) both read this. */
    public const READS_AS = [
        'setup_required' => ['held', self::SETUP_CODES, false],
        'held' => ['queued', null, true],
        'disposed' => ['rejected', self::CLOSED_CODES, false],
    ];
    /** The state whose word a request reads. $ordersOff: Orders are off for the request's own account (OperationQueries::ordersOff). */
    public static function readsAs(?string $state, ?string $code, bool $ordersOff = false): ?string
    {
        foreach (self::READS_AS as $as => [$from, $codes, $off]) {
            if ($state === $from && ($codes === null || in_array($code, $codes, true)) && (!$off || $ordersOff)) { return $as; }
        }
        return $state;
    }
    /** The SET key for a state, from this map or, for a state only a domain has, from DomainStatus. Unknown states read Under review. */
    public static function key(?string $state): string
    { return (self::STATES[$state] ?? DomainStatus::STATES[$state] ?? ['under_review'])[0]; }
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
        [$key, $default] = self::STATES[$state] ?? (isset(DomainStatus::STATES[$state]) ? [DomainStatus::STATES[$state][0], null] : ['under_review', 'Status missing']);
        [$label, $tone] = self::SET[$key];
        $reason = $cause === null ? $default : ($state === 'held' ? 'Not sent: '.$cause : $cause);
        return ['label' => $label, 'tone' => $tone, 'reason' => $reason];
    }
}
