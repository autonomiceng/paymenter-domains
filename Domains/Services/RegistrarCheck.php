<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence,Evidence,RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
/** One bounded read result, shared by background work, staff feedback and customer notices. */
final class RegistrarCheck
{
    public ?RequestContext $context = null;
    public ?string $deferred = null;
    public ?string $awaitingAnswer = null;
    public ?int $answerAfter = null;
    /** Set when reconciliation already counted this read toward the read limit. */
    public bool $counted = false;
    public function __construct(private readonly Clock $clock) {}
    private ?string $identity = null;
    private ?array $seen = null;
    private int $started = 0;
    private int $order = 0;
    private array $facts = [];
    /** Called under the check's first lock, after its eligibility check and before any other early return, so every result names its attempt.
     * The saved order number ranks checks, resumes and answers even when they start in the same second. */
    public function capture(Operation $op): void
    {
        $this->identity=RegistrarAnswers::identity($op);$this->seen=$op->policy['status_check']??null;$this->started=$this->clock->now()->getTimestamp();
        $this->order=self::nextOrder($op);$op->update(['policy'=>[...($op->policy??[]),'status_check_order'=>$this->order]]);
    }
    private static function nextOrder(Operation $op): int { return max(0,(int)($op->policy['status_check_order']??0))+1; }
    /** A saved result is newer when it was ranked after this check, or, before order numbers existed, started no earlier. */
    private function newer(?array $stored): bool
    { return isset($stored['order'])?(int)$stored['order']>$this->order:(int)($stored['started']??$stored['at']??0)>=$this->started; }
    /** Observations do not grant completion, payment return or another send. */
    public function observe(AttemptEvidence $attempt, Evidence $facts, bool $chargeConfirmed): void
    {
        $domain=$facts->domain;
        if ($domain && $domain->binding->matches($attempt->binding) && $domain->name===$attempt->query->name) {
            $this->facts=['owned'=>$domain->owned,'expiry'=>$domain->expiry?->value,'expiry_precision'=>$domain->expiry?->precision->value,'charge_confirmed'=>$chargeConfirmed];
        }
    }
    public function finish(Operation $result): Operation
    {
        return DB::transaction(function () use ($result): Operation {
            Domain::whereKey($result->domain_id)->lockForUpdate()->firstOrFail();
            $op = Operation::whereKey($result->id)->lockForUpdate()->firstOrFail();
            if ($this->identity===null || !hash_equals($this->identity,RegistrarAnswers::identity($op))) { return $op; }
            // A check, resume or answer saved after this check began is newer. This result must not replace it.
            $stored=$op->policy['status_check']??null;
            if ($stored!==$this->seen && $this->newer($stored)) { return $op; }
            $now = $this->clock->now()->getTimestamp();
            if ($op->state==='succeeded') { $this->awaitingAnswer=null; }
            $outcome = $this->awaitingAnswer !== null ? 'awaiting_answer' : ($this->deferred !== null ? 'deferred' : (($this->context?->completedReads() ?? 0) > 0 ? 'checked' : 'unavailable'));
            // A completed read of a lost reply counts toward the same read limit as any other check, once.
            if ($this->awaitingAnswer!==null && !$this->counted && ($this->context?->completedReads()??0)>0) {
                $policy=$op->policy;
                if ($op->action==='renew') { $policy['renewal_polls']=($policy['renewal_polls']??0)+1; }
                else { $policy['reconciliation']['polls']=($policy['reconciliation']['polls']??0)+1; }
                $op->policy=$policy;
            }
            $next = null;
            $watch = TransferPolling::applies($op);
            $stopped = $op->action === 'renew' ? RenewalCompletion::capturedChargeHeld($op) || ($op->policy['renewal_polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT
                : ($op->policy['reconciliation']['polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT && (!$watch || !TransferPolling::expected($op));
            if ($watch && ($op->policy['reconciliation']['final_check'] ?? null) === 'done') { $stopped = true; }
            $transferNext = $op->policy['reconciliation']['next_at'] ?? null;
            if (Schema::hasTable('domains_runtime_operations') && !$stopped && $op->state !== 'succeeded' && ($outcome === 'deferred' || $outcome === 'checked')) {
                if ($watch) { $next = max($now + 60, (int) $transferNext); }
                else {
                    $next = max($now + 300, (int) DB::table('domains_runtime_operations')->where('operation_id', $op->id)->value('due_at'));
                    DB::table('domains_runtime_operations')->updateOrInsert(['operation_id' => $op->id], ['due_at' => $next]);
                }
            }
            $op->update(['policy' => [...($op->policy ?? []), 'status_check' => ['outcome' => $outcome, 'at' => $now, 'started' => $this->started, 'order' => $this->order, 'next_at' => $next, 'reason' => $this->deferred, 'attempt_identity'=>$this->identity, 'read_attempted'=>($this->context?->exchanges()??0)>0||self::attempted($op), 'facts'=>$this->facts, ...($this->awaitingAnswer !== null ? ['registrar'=>$this->awaitingAnswer,'answer_after'=>$this->answerAfter] : [])]]]);
            return $op;
        });
    }
    /** Called under the existing review locks; a resume resets scheduling, never the sealed attempt. */
    public static function resumed(Operation $op, int $now): void
    {
        if (Schema::hasTable('domains_runtime_operations')) {
            DB::table('domains_runtime_operations')->updateOrInsert(['operation_id' => $op->id], ['due_at' => $now]);
        }
        // An earlier read of this same attempt still counts, so staff can record the registrar's answer after a resume.
        $kept=self::attempted($op)?['attempt_identity'=>$op->policy['status_check']['attempt_identity'],'read_attempted'=>true]:[];
        $order=self::nextOrder($op);
        $op->update(['policy' => [...$op->policy, 'status_check_order' => $order, 'status_check' => ['outcome' => 'scheduled', 'at' => $now, 'order' => $order, 'next_at' => $now, 'reason' => null, ...$kept]]]);
    }
    /** A lost reply that no accepted order history covers. Only the registrar's answer can settle it. */
    public static function lostReply(Operation $op, AttemptEvidence $attempt, \DateTimeImmutable $now): bool
    { return $op->action!=='transfer'&&$attempt->replyLost()&&!$attempt->historyAcceptance?->covers($attempt,$now); }
    /** A NameSilo send with no order number, or no reply at all, before the order history can list the order. */
    public static function historyPending(AttemptEvidence $attempt, \DateTimeImmutable $now): bool
    {
        $history=$attempt->historyAcceptance;
        return $attempt->binding->registrarKey==='namesilo'&&$history!==null
            &&$now->getTimestamp()<$attempt->windowEnd->getTimestamp()+$history->lagSeconds;
    }
    /** Enom still reads the domain on a lost reply; a NameSilo lost reply has nothing to read. */
    public static function unreadable(Operation $op, AttemptEvidence $attempt, \DateTimeImmutable $now): bool
    { return $attempt->binding->registrarKey!=='enom'&&self::lostReply($op,$attempt,$now); }
    public static function attempted(Operation $op): bool
    {
        $check=$op->policy['status_check']??[];
        return ($check['read_attempted']??false)===true&&is_string($check['attempt_identity']??null)&&hash_equals(RegistrarAnswers::identity($op),$check['attempt_identity']);
    }
    /** Plain words used everywhere this result appears. No provider payload enters the policy or UI. */
    public static function words(?array $check, bool $completed = false): array
    {
        $next = isset($check['next_at']) ? self::at((int) $check['next_at']) : null;
        return match ($check['outcome'] ?? null) {
            'awaiting_answer' => self::answerWords($check),
            'checked' => ['title' => 'Registrar checked', 'body' => $completed ? 'The request is complete. Open the domain for its details.'
                : self::STILL.' '.self::checkedNext($check)],
            'deferred' => ['title' => 'Check delayed', 'body' => (($check['reason'] ?? null) === 'scheduled' ? 'The next status check is not due yet.' : (($check['reason'] ?? null) === 'send_guard' ? 'Our sending settings paused this check.' : 'Our check limit was reached.'))
                .($next ? ' The next check is at '.$next.'.' : ' Contact support to restart checks.')],
            'not_checked' => ['title'=>'Registrar check pending','body'=>'Use Check status to check the original attempt.'],
            'scheduled' => ['title' => 'Status checks restarted', 'body' => 'The system will check again on its next turn.'],
            default => ['title' => 'Cannot check registrar', 'body' => 'We could not get an answer from the registrar. Support can check the original order.'],
        };
    }
    private const STILL = 'The result is still unconfirmed.';
    private static function checkedNext(array $check): string
    { return isset($check['next_at']) ? 'The next check is at '.self::at((int) $check['next_at']).'.' : 'Contact support to review the original order.'; }
    private static function answerWords(array $check): array
    {
        $step = self::answerStep($check);
        return ['title'=>self::answerRegistrar($check).' has not answered yet','body'=>$step['title'].' '.$step['body']];
    }
    private static function answerStep(array $check): array
    {
        $registrar = self::answerRegistrar($check);
        // Once the wait is over the button is live, so the line says so instead of naming a past time.
        $after=self::answerReady($check)?' now':' after '.self::answerAfter($check);
        return ['title'=>'Ask '.$registrar.' to check this attempt.','body'=>trim(self::found($check).' You can record '.$registrar.'’s answer'.$after.'.')];
    }
    private static function answerReady(array $check): bool
    { return (int)($check['answer_after']??0)<=app(Clock::class)->now()->getTimestamp(); }
    private static function found(array $check): string
    { return ($check['facts']['owned']??null)===true?'The domain was found in this registrar account. The original charge is still unconfirmed.':''; }
    private static function answerRegistrar(array $check): string { return ($check['registrar'] ?? null) === 'Enom' ? 'Enom' : 'NameSilo'; }
    /** The staff request notice, next step first. Staff History already says the result is unconfirmed, so this doesn't. Null with no check. */
    public static function step(Operation $op): ?array
    {
        $check = self::status($op);
        if ($check === null) { return null; }
        if (($check['outcome'] ?? null) === 'awaiting_answer') {
            $step = self::answerStep($check);
            if (!self::answerReady($check)) { return $step; }
            // Once the answer can be entered the title names that step, so it matches the notice's main button.
            $registrar = self::answerRegistrar($check);
            return ['title'=>'Ask '.$registrar.' to check this attempt, then enter its answer.','body'=>self::found($check),'answer'=>true];
        }
        $words = self::words($check, $op->state === 'succeeded');
        return ['title' => $words['title'].'.', 'body' => ($check['outcome'] ?? null) === 'checked' && $op->state !== 'succeeded' ? self::checkedNext($check) : $words['body']];
    }
    private static function answerAfter(array $check): string
    { return self::at((int)($check['answer_after'] ?? 0)); }
    /** The one date format these lines use, the staff pages' own: Nov 4, 2026, 1:53 PM PST. */
    private static function at(int $at): string
    { return \Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at($at); }
    /** Older lost replies need no worker turn to expose their existing answer step. Read only.
     * $known: batch-loaded reads for customer lists, the server's 'accounts' and its 'server' with settings. Its domain relation is set. */
    public static function status(Operation $op, ?array $known = null): ?array
    {
        $check = $op->policy['status_check'] ?? null;
        if (isset($check['attempt_identity']) && !hash_equals(RegistrarAnswers::identity($op),(string)$check['attempt_identity'])) { $check=null; }
        // An Enom lost reply is read before staff ask Enom for a case.
        if ($op->action==='register'&&$op->registrar_key==='enom'&&in_array($op->state,['running','pending','uncertain'],true)
            &&RegistrarAnswers::eligible($op,app(Clock::class)->now())&&!self::attempted($op)) { return ['outcome'=>'not_checked']; }
        if ($op->action !== 'renew' || !in_array($op->state, ['running', 'pending', 'uncertain'], true)
            || isset($op->policy['enom_answer']) || isset($op->attempt['reconciliation'])) { return $check; }
        try {
            $now = app(Clock::class)->now();
            if ($op->state === 'running' && $op->lease_expires_at !== null && $op->lease_expires_at > $now) { return $check; }
            $account = $known === null ? Account::where('server_id', $op->server_id)->sole() : $known['accounts']->sole();
            $domain = $op->domain;
            if ($op->account_fingerprint !== $account->account_fingerprint || $op->account_fingerprint !== $domain->account_fingerprint
                || $op->authority_generation !== $domain->authority_generation) { return $check; }
            $attempt = ReconciliationEvidence::attempt($op, $domain, AccountBindings::binding($account), $known['server'] ?? null);
            if (self::lostReply($op, $attempt, $now)) {
                if ($op->registrar_key==='enom'&&!self::attempted($op)) { return ['outcome'=>'not_checked']; }
                return [...($check??[]),'outcome'=>'awaiting_answer', 'registrar'=>$attempt->binding->registrarKey === 'enom' ? 'Enom' : 'NameSilo',
                    'answer_after'=>(new \DateTimeImmutable($op->attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp()];
            }
        } catch (\Throwable) {}
        return $check;
    }
    /** Customers wait for our registrar check; recording the answer is a staff task. */
    public static function forCustomer(Operation $op, ?array $known = null): array
    {
        $check = self::status($op, $known);
        // Before the first read the customer sees the same pending line; Check status is a staff button.
        if (($check['outcome'] ?? null) === 'not_checked' && is_string($op->attempt['dispatch_attempted_at'] ?? null)) {
            $check = ['outcome'=>'awaiting_answer','answer_after'=>(new \DateTimeImmutable($op->attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp()];
        }
        // Once the usual wait is over, the line never names that past day as a deadline still to come.
        if (($check['outcome'] ?? null) === 'awaiting_answer' && self::answerReady($check)) { return self::lateForCustomer($op); }
        if (($check['outcome'] ?? null) !== 'awaiting_answer') { return self::words($check, $op->state === 'succeeded'); }
        $kind = match ($op->action) { 'transfer' => 'transfer', 'renew' => 'renewal', default => 'registration' };
        $confirming = 'We’re confirming this '.$kind.' with the registrar.';
        // Customers read dates only (DESIGN.md P-TYP-9): the day the wait ends, in Pacific time like the rest of the site.
        $after = 'Contact support if it’s still pending after '.(new \DateTimeImmutable('@'.(int)($check['answer_after']??0)))->setTimezone(new \DateTimeZone('America/Los_Angeles'))->format('M j').'.';
        // brief: the same facts beside a step the customer still has, without saying there is nothing to do.
        return ['title'=>ucfirst($kind).' pending','body'=>$confirming.' You don’t need to do anything. '.$after,'brief'=>$confirming.' '.$after,'tone'=>'info'];
    }
    public static function for(Operation $op): array { return self::words(self::status($op), $op->state === 'succeeded'); }
    private static function lateForCustomer(Operation $op): array
    {
        $kind = match ($op->action) { 'transfer' => 'transfer', 'renew' => 'renewal', default => 'registration' };
        $said = 'We’re confirming this '.$kind.' with the registrar. It’s taking longer than usual. Contact support and we’ll check it.';
        return ['title'=>ucfirst($kind).' pending','body'=>$said,'brief'=>$said,'tone'=>'info'];
    }
}
