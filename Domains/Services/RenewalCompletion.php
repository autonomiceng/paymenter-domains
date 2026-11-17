<?php
// Enom d1392a8 renewal completion; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Bounded observation of the original sealed renewal. Never retries, refunds or invents expiry. */
final readonly class RenewalCompletion
{
    public function __construct(private AccountBindings $bindings, private Clock $clock) {}
    public function run(int $id, bool $staff = false): Operation
    {
        OperationClaims::outside(); $op = Operation::findOrFail($id);
        if (!$this->eligible($op) || self::capturedChargeHeld($op)) { return $op; }
        $check = new RegistrarCheck($this->clock);
        return $check->finish(EntryDeadline::run($this->clock, fn ($deadline) => $this->runEntered($id, $deadline, $check, $staff), RenewalAdmission::READ_SECONDS));
    }
    private function runEntered(int $id, \DateTimeImmutable $deadline, RegistrarCheck $check, bool $staff): Operation
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        $token = bin2hex(random_bytes(16));
        $capture = $this->decide($seed, $id, function (array $l) use ($id, $check, $staff, $token, $deadline) {
            $op = $l['operations']->firstWhere('id', $id);
            if (!$op || !$this->eligible($op) || self::capturedChargeHeld($op)) { return null; }
            // One registrar check at a time per attempt: another check (staff, cron or retry) waits while this one runs.
            if (self::checkRunning($op, $this->clock->now())) { if ($staff) { throw new StaffReviewRefused(MoneyDecisions::BUSY); } return null; }
            $check->capture($op);
            $financial=CapturedChargeReview::hasProof($op);
            $policy = $op->policy; $policy['renewal_execution'] = ['staff_alert' => true, 'reason' => ($op->policy['renewal_polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT ? 'poll_limit_reached' : 'original_attempt_unresolved', 'refundable_later' => false];
            if(!$financial){$op->update(['state' => $op->state === 'running' ? 'uncertain' : $op->state, 'lease_expires_at' => null, 'policy' => $policy]);}
            try {
                $this->bindings->requireReadableLocked($l['server'], $l['account']); RenewalFunding::requireCurrent($op, $l, true);
                $attempt = ReconciliationEvidence::attempt($op, $l['domain'], AccountBindings::binding($l['account']));
                HistoryResumption::apply($op, $attempt, $this->clock->now());
                if (RegistrarCheck::lostReply($op, $attempt, $this->clock->now())) {
                    $check->awaitingAnswer = $attempt->binding->registrarKey === 'enom' ? 'Enom' : 'NameSilo';
                    $check->answerAfter = (new \DateTimeImmutable($op->attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp();
                    // No read can find a captured charge that history no longer covers: the audited read is used up as unavailable.
                    if ($financial) { CapturedChargeReview::retain($op, CapturedChargeReview::reserve($op, $this->clock), null, $this->clock); return null; }
                    // Enom can still read the domain before staff ask for a case; NameSilo waits for its answer.
                    if ($attempt->binding->registrarKey !== 'enom') { return null; }
                }
                // Checks stop at the read limit.
                if (($op->policy['renewal_polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT) { return null; }
                $read=$financial?CapturedChargeReview::reserve($op,$this->clock):null;
                // The claim outlives the read deadline. An expired claim is only taken over by the next check; it still refuses
                // Not processed (checkClaimed) until a check saves its poll and proof and clears it.
                $op->update(['policy' => [...$op->policy, 'renewal_check' => ['identity' => RegistrarAnswers::identity($op), 'token' => $token,
                    'until' => $deadline->getTimestamp() + self::CLAIM_GRACE_SECONDS]]]);
                return [$attempt, HoldingLocks::take($l['domain'])['stamp'],$read,RegistrarAnswers::identity($op)];
            } catch (\PDOException|RenewalBusy $e) { throw $e; } catch (\Throwable) { return null; }
        }, $staff);
        if (!$capture) { return Operation::findOrFail($id); }
        [$attempt, $stamp,$financialRead,$identity] = $capture; $proof = null; $covered = false; $autoRenewConfirmed = false; $autoRenewUnconfirmed = $attempt->binding->registrarKey === 'namesilo'; $scan = null; $sample = null; $seen = null;
        try {
            $ctx = $check->context = new RequestContext($attempt->binding, RequestOrigin::Background, $deadline, new RequestBudget(25), $this->clock, Runtime::limiter());
            $result = $this->bindings->reader($attempt->binding->serverId)->reconcile($attempt, $ctx); $result->requireAttempt($attempt);
            $facts = $result->result()->evidence(); $sample = $facts->billZoneSample;
            // Scan progress is reusable after the deadline; financial conclusions still require an in-time read.
            $scan = $facts->historyScan;
            if ($this->clock->now() < $ctx->deadline) {
                $covered = $facts->domain?->owned === true && $facts->domain->expiry !== null
                    && ($facts->historyComplete || ($attempt->executionFacts && $attempt->providerReference !== null && count($facts->candidates) > 0));
                $autoRenewUnconfirmed = $attempt->binding->registrarKey === 'namesilo'
                    && (!$facts->domain || !$facts->domain->binding->matches($attempt->binding)
                        || $facts->domain->name !== $attempt->query->name || $facts->domain->owned !== true
                        || $facts->domain->providerAutoRenew !== false);
                $autoRenewConfirmed = $attempt->binding->registrarKey === 'namesilo' && !$autoRenewUnconfirmed;
                $proof = $autoRenewUnconfirmed ? null : ReconciliationEvidence::completion($attempt, $facts, $this->clock->now());
                $check->observe($attempt,$facts,$proof!==null);
            }
        } catch (ReadDeferred) { $check->deferred = 'read_limit';
        } catch (EnomTestCallLimit $error) { $check->deferred = $error->reason === 'send_guard' ? 'send_guard' : 'send_limit';
        } catch (\Throwable) {}
        // Proof any reply showed (a charge or a later expiry) is saved even from a late, deferred or failed check (RegistrarAnswers::sawProof).
        $seen = isset($ctx) ? $ctx->seen() : null;
        // Every check that reached the registrar counts toward the read limit, whatever the reply looked like.
        $ran = $check->deferred === null && isset($ctx) && $ctx->exchanges() > 0;
        // A bill zone sample is saved only after this turn's locked step: server settings are part of the holding stamp it compares.
        try {
        $quiet = !$financialRead && !$covered && !$autoRenewUnconfirmed && !$autoRenewConfirmed && !$ran && !$seen;
        return $this->decide($seed, $id, function (array $l) use ($id, $stamp, $proof, $attempt, $covered, $autoRenewUnconfirmed, $autoRenewConfirmed,$financialRead,$check,$scan,$ran,$seen,$identity,$token,$quiet) {
            $op = $l['operations']->firstWhere('id', $id); $d = $l['domain'];
            // This check's claim ends in the same locked step that counts its poll and saves its proof.
            if ($op && ($op->policy['renewal_check']['token'] ?? null) === $token) {
                $policy = $op->policy; unset($policy['renewal_check']); $op->update(['policy' => $policy]);
            }
            if ($quiet) { return $op; }
            // A concurrent check or staff resume changes the stamp, so this completion is stale. A charge or later expiry this
            // read saw for the same attempt and binding is still kept, so a later lagging read cannot permit Not processed.
            if ($op && $seen && $l['stamp'] !== $stamp && $this->eligible($op) && RegistrarAnswers::identity($op) === $identity
                && AccountBindings::binding($l['account'])->matches($attempt->binding)) {
                $kept = RegistrarAnswers::sawProof($op->policy ?? [], $op, $attempt, $seen, $this->clock->now());
                if (($kept['renewal_proof'] ?? null) !== ($op->policy['renewal_proof'] ?? null)) { $op->update(['policy' => $kept]); }
            }
            if (!$op || !$this->eligible($op) || $l['stamp'] !== $stamp || ($proof && $proof['provider_domain_id'] !== $d->provider_domain_id)) { return $op; }
            try { $this->bindings->requireReadableLocked($l['server'], $l['account']); RenewalFunding::requireCurrent($op, $l, true); }
            catch (\PDOException|RenewalBusy $e) { throw $e; } catch (\Throwable) { return $op; }
            if($financialRead){CapturedChargeReview::retain($op,$financialRead,$proof,$this->clock);return $op;}
            // Every completed check counts, a history scan that resolved more orders too. Admission and call-cap deferrals never count.
            $check->counted = $covered || $ran;
            $policy = $op->policy; $policy['renewal_polls'] = ($policy['renewal_polls'] ?? 0) + ($check->counted ? 1 : 0);
            // Saved scan progress; a scan that resolved more orders runs its next check sooner.
            if ($scan) { $policy['history_scan'] = $scan->saved(); $check->continued = !$covered && $scan->progressed; }
            if ($seen) { $policy = RegistrarAnswers::sawProof($policy, $op, $attempt, $seen, $this->clock->now()); }
            if (!$proof) {
                $policy['renewal_execution']['reason'] = $autoRenewUnconfirmed ? 'provider_auto_renew_unconfirmed'
                    : ($policy['renewal_polls'] >= OperationReconciliation::POLL_LIMIT ? 'poll_limit_reached' : 'original_attempt_unresolved');
                $op->update(['safe_code' => $autoRenewUnconfirmed ? 'provider_auto_renew_unconfirmed'
                    : ($autoRenewConfirmed && $op->safe_code === 'provider_auto_renew_unconfirmed' ? null : $op->safe_code), 'policy' => $policy]); return $op;
            }
            $cost = $proof['charge']; $cap = $op->policy['renewal']['cost_cap'];
            if(isset($op->policy['account_complimentary'])){$cap['amount']=ComplimentaryCosts::cap($op,$cap['amount']);}
            $over = WriteMoney::compare($cost['amount'], $cap['amount']) > 0;
            $changed = WriteMoney::compare($cost['amount'], $attempt->expectedCharge->amount) !== 0;
            $below = $op->funding_basis!=='complimentary' && WriteMoney::compare($cost['amount'], $op->amount) > 0; $corrections = $proof['observed_corrections'] !== [];
            // The TEST annual-rate rule cannot silently accept a larger incurred charge, even under a higher staff cap.
            if ($attempt->binding->registrarKey === 'enom' && $attempt->query->years > 1
                && WriteMoney::compare($cost['amount'], $attempt->expectedCharge->amount) > 0) {
                $reason = $over ? 'actual_cost_overrun' : 'actual_charge_changed';
                $policy['renewal_execution'] = ['staff_alert' => true, 'reason' => $reason, 'refundable_later' => false];
                $op->update(['state' => 'uncertain', 'safe_code' => $reason, 'policy' => $policy,
                    'attempt' => [...$op->attempt, 'reconciliation' => $proof], 'cost' => ['actual' => $cost], 'lease_expires_at' => null]);
                $d->update(['renewal_policy' => [...$d->renewal_policy, 'automatic_spend_paused' => true]]);
                return $op;
            }
            $policy['renewal_execution'] = ['staff_alert' => $over || $changed || $below || $corrections,
                'reason' => $over ? 'actual_cost_overrun' : ($changed ? 'actual_charge_changed' : ($below ? 'below_cost' : 'confirmed')), 'refundable_later' => false];
            $op->update(['state' => 'succeeded', 'safe_code' => $changed ? 'actual_charge_changed' : null, 'policy' => $policy,
                'attempt' => [...$op->attempt, 'reconciliation' => $proof], 'cost' => ['actual' => $cost], 'provider_order_id' => $proof['order_reference'],
                'confirmed_expires_at' => $proof['expiry'], 'completed_at' => $this->clock->now(), 'lease_expires_at' => null]);
            $priorExpiry=$d->provider_expires_at;
            $d->update(['status' => 'active', 'provider_expires_at' => $proof['expiry'], 'provider_order_id' => $proof['order_reference'],
                'confirmed_revision' => $d->confirmed_revision + 1, 'last_synced_at' => $this->clock->now(),
                'renewal_policy' => [...$d->renewal_policy, 'automatic_spend_paused' => $over || ($d->renewal_policy['automatic_spend_paused'] ?? false),
                    'billing_anchor' => ['expiry' => $proof['expiry'], 'operation_key' => $op->operation_key,
                        ...($l['identity_source']['kind'] === 'adoption' ? ['kind' => 'completed_operation', 'confirmed_revision' => $d->confirmed_revision + 1] : [])]]]);
            if(ObservationProjection::renewed($d,$priorExpiry)){HoldingObservationWork::renewed($d,$this->clock->now());}
            ComplimentaryInvoices::complete($op);
            return $op;
        }, $staff) ?? Operation::findOrFail($id);
        } finally {
            if ($sample) { EnomBillZones::record($attempt->binding, $sample); }
        }
    }
    /** Contention queues automatic work; interactive checks refuse so staff never see an unsaved read as finished. */
    private function decide($seed, int $id, \Closure $step, bool $staff): mixed
    {
        $decision = MoneyDecisions::run(new MoneyRequest($seed, $staff ? null : RenewalExecution::KIND, $id), $step);
        if ($decision->error) { throw $decision->error; }
        if ($staff && $decision->outcome === MoneyOutcome::Deferred) { throw new StaffReviewRefused(MoneyDecisions::BUSY); }
        return $decision->value;
    }
    /** Explicit bounded read-only recovery; original paid uncertainty and attempt remain unchanged. */
    public function resume(int $id, StaffOperationReview $review): Operation
    {
        OperationClaims::outside(); $seed=Operation::findOrFail($id)->domain()->firstOrFail();
        return FreshTransaction::run(function()use($seed,$id,$review){
            $l=HoldingLocks::take($seed,$review->actorId);$review->check($l);$op=$l['operations']->firstWhere('id',$id);
            if(!$op||!$this->eligible($op)){throw new \DomainException('No unresolved sealed renewal.');}
            $this->bindings->requireReadableLocked($l['server'],$l['account']);RenewalFunding::requireCurrent($op,$l,true);
            ReconciliationEvidence::attempt($op,$l['domain'],AccountBindings::binding($l['account']));
            $policy=$op->policy;$history=$policy['renewal_resumptions']??[];
            if(!is_array($history)||!array_is_list($history)||count($history)>=20){throw new \DomainException('Renewal read resume limit reached.');}
            $policy['renewal_resumptions']=[...$history,['prior_polls'=>$policy['renewal_polls']??0,'at'=>$this->clock->now()->format(DATE_ATOM)]];
            $policy['renewal_polls']=0;$policy['renewal_execution']=['staff_alert'=>true,'reason'=>'resumed','refundable_later'=>false];
            $op->update(['policy'=>$policy]);RegistrarCheck::resumed($op,$this->clock->now()->getTimestamp());$review->record($l);return $op;
        });
    }
    /** Captured financial proof needs review; an audited resume permits one fresh bounded observation. */
    public static function capturedChargeHeld(Operation $op): bool
    {
        return in_array($op->safe_code, ['actual_charge_changed', 'actual_cost_overrun'], true)
            && is_array($op->attempt['reconciliation'] ?? null)
            && ($op->policy['renewal_execution']['reason'] ?? null) !== 'resumed';
    }
    public const CLAIM_GRACE_SECONDS = 300;
    /** A live check claim for the current attempt. */
    private static function checkRunning(Operation $op, \DateTimeImmutable $now): bool
    { return self::checkClaimed($op) && (int) ($op->policy['renewal_check']['until'] ?? 0) > $now->getTimestamp(); }
    /** Any check claim for the current attempt, live or expired: the check may have seen proof it has not saved. */
    public static function checkClaimed(Operation $op): bool
    {
        $claim = $op->policy['renewal_check'] ?? null;
        return is_array($claim) && is_string($claim['identity'] ?? null) && hash_equals(RegistrarAnswers::identity($op), $claim['identity']);
    }
    private function eligible(Operation $op): bool
    {
        return $op->action === 'renew' && (($op->state === 'running' && (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now()))
            || in_array($op->state, ['pending', 'uncertain'], true)) && ($op->attempt['seal'] ?? null) === 'sealed';
    }
}
