<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus, RequestStatus};
/** The staff view of a domain's status: the customer's status words, with the one staff step under them.
 * Status words come from DomainStatus, except held registrations and staff-held transfers: staff read On hold and customers read Under review. The step is staff-only.
 * Where the domain only says a request is in the way, the head word stays the customer's and the step names that request (RequestFacts::status reason).
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
    private const NO_CODE = 'Customer has not added the auth code. Not sent yet.';
    private const NO_SETUP = 'Customer has not finished setup. Not sent yet.';
    private const REGISTERING = 'Registration in progress. Nothing to do yet.';
    private const TRANSFERRING = 'Transfer in progress. Nothing to do yet.';
    private const RENEWING = 'Renewal in progress. Nothing to do yet.';
    /** Steps that wait on the customer or on work already running. Every other step is a job for staff. */
    private const WAITING = [self::UNPAID, self::NO_CODE, self::NO_SETUP, self::REGISTERING, self::TRANSFERRING];
    private const CHECK = 'Check the domain.';
    /** Customers read DomainStatus::LATE, which sends them to support. Staff are support, so they get this step instead. */
    private const LATE = 'Check renewal options and status at the registrar.';
    /** Reasons written for the customer. Staff read their step. */
    private const CUSTOMER_ONLY = [DomainStatus::LATE, DomainStatus::ON_IT];
    /** $shared is the status the customer sees and $state the state it came from (DomainFacts::status). $alerts, $renewal, $waiting and $ordersOff come from StaffDomainInputs.
     * An open alert names the step unless the row already has a specific one (a held request, an import) or has ended.
     * With no alert, an open renewal says what happens next on a domain that is otherwise Active.
     * @param array{key:string,label:string,tone:string,reason:?string} $shared
     * @param list<array{code:string,request:?int}> $alerts
     * @return array{key:string,label:string,tone:string,reason:?string,step:?string,request:?int,act:bool,alert:?string} $request is set when the step names one, $act when staff have a job here, $alert when the step is that open alert */
    public static function for(array $shared, string $state, Domain $d, ?Operation $op, array $alerts, ?Operation $renewal=null, ?Operation $waiting=null, bool $ordersOff=false, ?array $accounts=null, ?array $release=null): array
    {
        [$step, $specific, $request] = self::resolve($state, $d, $op, $accounts, $release);
        $act = $step !== null && !in_array($step, self::WAITING, true);
        $named = null;
        if (!$specific) {
            if ($alerts) { $top = self::topAlert($alerts); [$step, $request] = self::alert($top, $d); $act = true; $named = $top['code']; }
            elseif ($renewal && in_array($state, ['active', 'renewal_off_expiring'], true)) { [$step, $request, $act] = self::renewal($renewal, $accounts, $release); }
        }
        // A held or unconfirmed transfer, and a state this version doesn't know, only say a request is in the way.
        // Staff read the customer's head word, then that request in their step. Amber only when staff must act.
        $unknown = !isset(DomainStatus::STATES[$state]);
        $behind = in_array($state, ['held', 'uncertain'], true) ? $op : ($unknown ? $waiting : null);
        if ($behind) {
            if ($step === self::CHECK) {
                $line = RequestFacts::status($behind, accounts: $accounts);
                [$step, $request, $act] = $line['reason'] === null ? [Labels::action($behind->action).' in progress. Nothing to do yet.', null, false] : [self::sentence($line['reason']).self::ordersOrCheck($behind,$line['reason'],$accounts), $behind->id, true];
            }
            $shared = [...$shared, 'tone' => $act ? $shared['tone'] : 'info', 'reason' => null];
        } elseif ($unknown && $step === self::CHECK) {
            [$step, $request] = [$op ? 'Status missing.'.self::check($op->id) : 'Status missing. Check the domain at the registrar.', $op?->id];
        }
        // A queued registration that Orders off stop is On hold, as its request reads. A closed order or customer setup keeps its own word.
        if ($op && in_array($d->status,self::ACQUIRING,true) && RequestFacts::heldAcquisition($op, $state==='registration_held'||$state==='registration_pending'&&$ordersOff)) {
            $shared=[...$shared,'key'=>'on_hold','label'=>'On hold','tone'=>'warning'];
        }
        return [...self::forStaff($shared), 'step' => $step, 'request' => $request, 'act' => $act, 'alert' => $named];
    }
    /** Late money is checked first by the caller, so it takes the step. */
    public static function late(array $shared): array
    { return [...self::forStaff($shared), 'step' => DomainFacts::alertCause('acquisition_late_money'), 'request' => null, 'act' => true, 'alert' => 'acquisition_late_money']; }
    private static function forStaff(array $shared): array
    { return in_array($shared['reason'], self::CUSTOMER_ONLY, true) ? [...$shared, 'reason' => null] : $shared; }
    /** @return array{0:?string,1:bool,2:?int} step, whether the step is already specific, and the request it names */
    private static function resolve(string $state, Domain $d, ?Operation $op, ?array $accounts, ?array $release=null): array
    {
        if ($state === 'adoption_pending') { return ['Check open invoices.', true, null]; }
        if ($op && in_array($d->status, self::ACQUIRING, true)) {
            try { $ready=RequestFacts::readyMessage($op,$accounts,$release); } catch (\Throwable) { return [DomainFacts::UNREADABLE.self::check($op->id),true,$op->id]; }
            if ($ready!==null) {
                return [$ready.self::ordersOrCheck($op,$ready,$accounts),true,$op->id];
            }
            if (in_array($op->state, ['held', 'uncertain'], true)) { return [self::stopped($op,$accounts,$release), true, $op->id]; }
            if ($state === 'awaiting_payment') { return [self::UNPAID, false, null]; }
            if ($state === 'awaiting_code') { return [self::NO_CODE, false, null]; }
            if ($state === 'setup_required') { return [self::NO_SETUP, false, null]; }
            // Paid and still marked awaiting_payment: the sweep picks it up on its own.
            if ($state !== 'needs_review' && !in_array($op->state, [...self::MOVING, 'awaiting_payment'], true)) {
                return [match ($op->state) {
                    'cancelled' => 'Request cancelled, order still open. Cancel the order to release the name.', 'disposed' => 'Request cancelled.', 'rejected' => RequestStatus::closed($op->state, $op->safe_code) ? 'Request cancelled.' : 'Request failed.',
                    'succeeded' => 'Request done, but the domain is still pending.', default => 'Request status unclear.',
                }.self::check($op->id), false, $op->id];
            }
        }
        return [...match ($state) {
            'registration_pending' => [self::REGISTERING, false],
            'transfer_pending' => [self::TRANSFERRING, false],
            'active', 'renewal_off_expiring' => [null, false],
            'needs_review' => ['Check the domain and its requests.', false],
            'expired', 'grace', 'redemption' => [self::LATE, true],
            'registration_rejected', 'transfer_rejected', 'cancelled' => [null, true],
            default => [self::CHECK, false],
        }, null];
    }
    /** @return array{0:string,1:?int} */
    private static function alert(array $alert, Domain $d): array
    {
        $step = self::sentence(DomainFacts::alertCause($alert['code'], $d));
        if (!$alert['request']) { return [$step, null]; }
        // Some causes already end by sending staff to the request. Name it there, once.
        $open = self::check($alert['request']);
        return [str_contains($step, ' Check the request.') ? str_replace(' Check the request.', $open, $step) : $step.$open, $alert['request']];
    }
    /** @return array{0:string,1:?int,2:bool} step, the request it names, and whether staff have a job here */
    private static function renewal(Operation $op, ?array $accounts, ?array $release=null): array
    {
        return match (true) {
            in_array($op->state, ['held', 'uncertain'], true) => [self::stopped($op, $accounts, $release), $op->id, true],
            // The alert count ($release) never shows these words and reads nothing per request, so it skips the paid invoice read.
            $op->state === 'awaiting_payment' => [($release === null ? RequestFacts::paidWaiting($op, $accounts) : null) ?? Labels::requestStatus($op)['reason'], null, false],
            $op->state === 'queued' && ($ready = RequestFacts::readyMessage($op, $accounts, $release)) !== null => [$ready.self::ordersOrCheck($op, $ready, $accounts), $op->id, true],
            default => [self::RENEWING, null, false],
        };
    }
    private static function stopped(Operation $op, ?array $accounts=null, ?array $release=null): string
    {
        $cause = RequestFacts::reason($op, accounts: $accounts, release: $release);
        // A cost approval already names its one step, the Approve registrar cost button on the domain page.
        if ($cause !== null && str_starts_with($cause, 'Needs approval:') && \Paymenter\Extensions\Servers\Domains\Services\ComplimentaryCosts::need($op) !== null) { return $cause; }
        return self::sentence($cause ?? ($op->state === 'held' ? 'Not sent' : RequestStatus::UNCONFIRMED)).self::check($op->id);
    }
    private static function check(int $id): string { return ' Check request #'.$id.'.'; }
    /** One step: a request that waits only on Orders names the switch, since its page says no more; any other names the request. */
    private static function ordersOrCheck(Operation $op, string $reason, ?array $accounts=null): string
    { return $op->state==='queued'&&OperationQueries::ordersOff($op,$accounts)?RequestFacts::ordersStep($reason):self::check($op->id); }
    /** A request to check leads, as in the domain page's head (DESIGN: the staff job reads Check request #N). Then the first danger alert, else the oldest. */
    private static function topAlert(array $alerts): array
    {
        $requests = array_values(array_filter($alerts, fn(array $a) => ($a['request'] ?? null) !== null && in_array($a['code'], ['operation_review', 'held_obligation'], true)));
        $pool = $requests !== [] ? $requests : array_values($alerts);
        foreach ($pool as $alert) { if ((DomainFacts::ALERTS[$alert['code']][1] ?? null) === 'danger') { return $alert; } }
        return $pool[0];
    }
    public static function sentence(string $text): string
    { return preg_match('/[.!?]\z/', $text) ? $text : $text.'.'; }
}
