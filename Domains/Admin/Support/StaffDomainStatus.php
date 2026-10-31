<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
/** The staff view of a domain's status: the customer's status words, with the one staff step under them.
 * Status words come from DomainStatus, so customers and staff read the same thing. Only the step is staff-only.
 * Request history and every open alert stay on the domain and request pages.
 * This is the only copy of the rule: the list, the heading and the status filter all read it through DomainFacts::status(). */
final class StaffDomainStatus
{
    /** Domain statuses that wait on the latest acquisition request. */
    public const ACQUIRING = ['registration_pending', 'transfer_pending', 'needs_review'];
    /** Request states that move on their own. */
    public const MOVING = ['queued', 'running', 'pending'];
    /** Same words as the request page (RequestStatus). */
    private const UNPAID = 'Customer has not paid the invoice. Not sent yet.';
    /** $shared is the status the customer sees and $state the state it came from (DomainFacts::status). $alerts and $renewal come from StaffDomainInputs.
     * An open alert names the step unless the row already has a specific one (a held request, an import) or has ended.
     * With no alert, an open renewal says what happens next on a domain that is otherwise Active.
     * @param array{key:string,label:string,tone:string,reason:?string} $shared
     * @param list<array{code:string,request:?int}> $alerts
     * @return array{key:string,label:string,tone:string,reason:?string,step:?string,request:?int} $request is set when the step names one */
    public static function for(array $shared, string $state, Domain $d, ?Operation $op, array $alerts, ?Operation $renewal=null): array
    {
        [$step, $specific, $request] = self::resolve($state, $d, $op);
        if (!$specific) {
            if ($alerts) { [$step, $request] = self::alert(self::topAlert($alerts)); }
            elseif ($renewal && in_array($state, ['active', 'renewal_off_expiring'], true)) { [$step, $request] = self::renewal($renewal); }
        }
        return [...$shared, 'step' => $step, 'request' => $request];
    }
    /** Late money is checked first by the caller, so it takes the step. */
    public static function late(array $shared): array
    { return [...$shared, 'step' => DomainFacts::alertCause('acquisition_late_money'), 'request' => null]; }
    /** @return array{0:?string,1:bool,2:?int} step, whether the step is already specific, and the request it names */
    private static function resolve(string $state, Domain $d, ?Operation $op): array
    {
        if ($state === 'adoption_pending') { return ['Check open invoices.', true, null]; }
        if ($op && in_array($d->status, self::ACQUIRING, true)) {
            if (($ready=RequestFacts::readyMessage($op))!==null) { return [$ready.self::open($op->id),true,$op->id]; }
            if (in_array($op->state, ['held', 'uncertain'], true)) { return [self::stopped($op), true, $op->id]; }
            if ($state === 'awaiting_payment') { return [self::UNPAID, false, null]; }
            if ($state === 'awaiting_code') { return ['Customer has not added the auth code. Not sent yet.', false, null]; }
            if ($state === 'setup_required') { return ['Customer has not finished setup. Not sent yet.', false, null]; }
            // Paid and still marked awaiting_payment: the sweep picks it up on its own.
            if ($state !== 'needs_review' && !in_array($op->state, [...self::MOVING, 'awaiting_payment'], true)) {
                return [match ($op->state) {
                    'cancelled' => 'Request cancelled, order still open. Cancel the order to release the name.', 'disposed' => 'Request cancelled.', 'rejected' => RequestStatus::closed($op->state, $op->safe_code) ? 'Request cancelled.' : 'Request failed.',
                    'succeeded' => 'Request done, but the domain is still pending.', default => 'Request status unclear.',
                }.self::open($op->id), false, $op->id];
            }
        }
        return [...match ($state) {
            'registration_pending' => ['Registration in progress. Nothing to do yet.', false],
            'transfer_pending' => ['Transfer in progress. Nothing to do yet.', false],
            'active', 'renewal_off_expiring' => [null, false],
            'needs_review' => ['Check the domain and its requests.', false],
            'expired', 'grace', 'redemption', 'registration_rejected', 'transfer_rejected', 'cancelled' => [null, true],
            default => ['Check the domain.', false],
        }, null];
    }
    /** @return array{0:string,1:?int} */
    private static function alert(array $alert): array
    {
        $step = self::sentence(DomainFacts::alertCause($alert['code']));
        if (!$alert['request']) { return [$step, null]; }
        // Some causes already end by sending staff to the request. Name it there, once.
        $open = self::open($alert['request']);
        return [str_contains($step, ' Open the request.') ? str_replace(' Open the request.', $open, $step) : $step.$open, $alert['request']];
    }
    /** @return array{0:string,1:?int} */
    private static function renewal(Operation $op): array
    {
        return match (true) {
            in_array($op->state, ['held', 'uncertain'], true) => [self::stopped($op), $op->id],
            $op->state === 'awaiting_payment' => [Labels::requestStatus($op)['reason'], null],
            default => ['Renewal in progress. Nothing to do yet.', null],
        };
    }
    private static function stopped(Operation $op): string
    {
        $cause = Labels::requestStatus($op)['reason'];
        return self::sentence($cause ?? ($op->state === 'held' ? 'Not sent' : 'Registrar has not confirmed the result')).self::open($op->id);
    }
    private static function open(int $id): string { return ' Open request #'.$id.'.'; }
    /** The first danger alert, else the oldest. */
    private static function topAlert(array $alerts): array
    {
        foreach ($alerts as $alert) { if ((DomainFacts::ALERTS[$alert['code']][1] ?? null) === 'danger') { return $alert; } }
        return reset($alerts);
    }
    private static function sentence(string $text): string
    { return preg_match('/[.!?]\z/', $text) ? $text : $text.'.'; }
}
