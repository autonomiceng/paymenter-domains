<?php
// Enom 444f31c reconciliation-registration/provider-disposition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomBillZones, EnomTestCallLimit,
    HistoryResumption, LiveReservationLimit, OperationClaims, ReadDeferred, ReconciliationEvidence, RegistrarCheck,
    Runtime, StaffOperationReview, StaffReviewRefused, TransferFollowup, TransferPolling, TransferReadBudget};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Contracts\TransferPoller;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, Evidence, RequestBudget, RequestContext, RequestOrigin, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionLifecycle, AcquisitionPhase, AcquisitionReadPermit, AcquisitionRetryProof, OperationWriter, ReadToken};
use Paymenter\Extensions\Servers\Domains\Registrars\{Enom, NameSilo};
use Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementCorrections;
use Paymenter\Extensions\Servers\Domains\Transfer\Execution\TransferObservation;
/** Observation never replays a write. No rejection/refund can be inferred from an empty read. */
final class OperationReconciliation
{
    public const POLL_LIMIT=5;
    public const READ_SECONDS=250;
    private readonly OperationWriter $attempts;
    public function __construct(private readonly OperationClaims $claims, private readonly AccountBindings $bindings, private readonly Clock $clock)
    { $this->attempts = new OperationWriter($clock); }
    public function run(int $id): Operation
    {
        OperationClaims::outside();
        $check = new RegistrarCheck($this->clock);
        return $this->read($id, $check);
    }
    private function capture(int $id, RegistrarCheck $check): ?AcquisitionRead
    {
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($check): ?AcquisitionRead {
            $at = $this->clock->now();
            $state = AcquisitionLifecycle::settle($op, $domain, $at);
            if (!$this->eligible($op, $at) || $state->facts?->reading($at)
                || $state->facts && !$state->permitsRead()) { return null; }
            $check->capture($op, $at);
            $read = $this->captureLocked($op, $domain, $all, $funding, $server, $account, $state, $check, $at);
            if (!$read) { $check->publishAcquisitionDeferral($op, $at); }
            return $read;
        });
    }

    private function captureLocked(Operation $op, Domain $domain, $all, $funding, $server, $account,
        AcquisitionLifecycle $state, RegistrarCheck $check, \DateTimeImmutable $at): ?AcquisitionRead
    {
        $watch = TransferPolling::applies($op);
        $now = $at->getTimestamp();
        $manual = $watch && ($op->policy['reconciliation']['manual_check'] ?? false);
        if ($watch && !TransferPolling::due($op, $now)) { $check->deferred = 'scheduled'; return null; }
        $followup = $watch && !$manual && ($op->policy['reconciliation']['final_check'] ?? null) === 'done';
        $this->unresolved($op);
        $op->refresh();
        try {
            $binding = $this->authority($op, $domain, $all, $funding, $server, $account, $followup);
            $attempt = AcquisitionRead::originalEvidence($op, $domain, $binding);
            if (!$attempt || !$state->permitsRead()) {
                $this->unresolved($op, RegistrarCheck::ADOPTION_REFUSED);
                $check->adoptionRefused = true;
                return null;
            }
            if (HistoryResumption::apply($op, $attempt, $at)) { $op->refresh(); }
            if (RegistrarCheck::lostReply($op, $attempt, $at)) {
                if (RegistrarCheck::historyPending($attempt, $at)) { $check->deferred = 'scheduled'; return null; }
                self::askRegistrar($check, $op, $attempt);
                if ($attempt->binding->registrarKey !== 'enom') { return null; }
            }
            if (($op->policy['reconciliation']['polls'] ?? 0) >= self::POLL_LIMIT && !TransferPolling::expected($op)
                && !$manual && !($watch && TransferPolling::expired($op, $now))) {
                if ($op->action !== 'transfer' && $attempt->replyLost()) { self::askRegistrar($check, $op, $attempt); }
                return null;
            }
            $deadline = $now + self::READ_SECONDS;
            if ($watch && !$manual) {
                $deadline = min($deadline, TransferPolling::cutoff($op) ?? throw new \DomainException('Missing sealed transfer cutoff.'));
            }
            if ($deadline <= $now) { return null; }
            $until = new \DateTimeImmutable('@'.$deadline);
            $read = OperationWriter::at($at)->openRead($state->facts->token, $until);
            if (!$read instanceof ReadToken) { $check->discard(); return null; }
            if ($watch) { TransferPolling::reserve($op, $now); $op->refresh(); }
            return new AcquisitionRead($attempt, AcquisitionRead::stamp($op, $domain), self::contract($attempt),
                AcquisitionRead::requestedNameservers($op), $watch, $watch && TransferPolling::light($op, $now),
                $followup, $until, $read);
        } catch (\Throwable) {
            if ($watch) {
                $p = $op->policy;
                $p['reconciliation']['staff_alert'] = true;
                $p['reconciliation']['reason'] = 'transfer_authority_changed';
                if (TransferPolling::expired($op, $now)) { $p['reconciliation']['final_check'] = 'done'; }
                $op->update(['policy' => $p]);
            }
            return null;
        }
    }

    private function read(int $id, RegistrarCheck $check): Operation
    {
        $capture = $this->capture($id, $check);
        if (!$capture) { return Operation::findOrFail($id); }
        try {
            return $this->readCaptured($id, $check, $capture);
        } catch (\Throwable $error) {
            $this->claims->locked($id, function ($op, $domain) use ($capture): void {
                $now = $this->clock->now();
                $state = AcquisitionLifecycle::settle($op, $domain, $now);
                if (AcquisitionReadPermit::issue($state, $capture->token, $now)) {
                    OperationWriter::at($now)->closeRead($capture->token);
                }
            });
            throw $error;
        }
    }

    private function exchange(AcquisitionRead $read, RegistrarCheck $check, array &$seen): Evidence
    {
        $attempt = $read->attempt;
        $limiter = $read->watch ? new TransferReadBudget($this->clock, Runtime::limiter()) : null;
        $context = $check->context = new RequestContext($attempt->binding, RequestOrigin::Background, $read->until,
            new RequestBudget(25), $this->clock, $limiter ?? Runtime::limiter(), deferOnReservationLimit: $read->watch);
        if ($attempt->query->action->value === 'register') { AcquisitionObservation::collect($context, $seen); }
        $work = function () use ($read, $attempt, $context) {
            $reader = $this->bindings->reader($attempt->binding->serverId);
            if ($read->followup) {
                if ($reader instanceof Enom\EnomTestReconciliationReader) { return $reader->reconcile($attempt, $context, overdue: true); }
                if (!$reader instanceof Enom\Driver && !$reader instanceof NameSilo\Driver) {
                    throw new \DomainException('Transfer status reader unavailable.');
                }
                return $reader->pollTransfer($attempt, $context, overdue: true);
            }
            return $read->light && $reader instanceof TransferPoller ? $reader->pollTransfer($attempt, $context) : $reader->reconcile($attempt, $context);
        };
        $result = $limiter ? $limiter->run($attempt->binding, $work) : $work();
        $result->requireAttempt($attempt);
        $facts = $result->result()->evidence();
        if ($sample = $facts->billZoneSample) { EnomBillZones::record($attempt->binding, $sample); }
        return $facts;
    }

    private function readCaptured(int $id, RegistrarCheck $check, AcquisitionRead $read): Operation
    {
        $observed = new AcquisitionObservation();
        $callCapBlocked = false; $seen = [];
        try {
            $facts = $this->exchange($read, $check, $seen);
            // Scan progress is reusable after the deadline. Positive proof still needs an in-time read.
            $now = $this->clock->now();
            $observed = $now < $read->until ? AcquisitionObservation::from($read, $facts, $now)
                : AcquisitionObservation::partial($read, $seen, $facts->historyScan);
            if ($now < $read->until) { $check->observe($read->attempt, $facts, $observed->proof !== null); }
        } catch (ReadDeferred|LiveReservationLimit) {
            $check->deferred = 'read_limit';
            if ($read->watch) {
                return $this->deferred($id, $read, $check);
            }
            return $this->finishRead($id, $read, AcquisitionObservation::partial($read, $seen), $check);
        } catch (EnomTestCallLimit $error) {
            $check->deferred = $error->reason === 'send_guard' ? 'send_guard' : 'send_limit';
            // Enom’s daily guard runs after limiter admission, before the physical exchange.
            if ($read->watch){return $this->deferred($id, $read, $check);}
            $callCapBlocked = true;
        } catch (\Throwable) {} // Safe retained alert; transport/contract failures do not manufacture facts.
        if ($observed->facts === null && $seen) { $observed = AcquisitionObservation::partial($read, $seen, $observed->scan); }
        $readStarted=!$read->watch||($check->context?->exchanges() ?? 0) > 0;
        try {
            return $observed->covered ? $this->finishObserved($id, $read, $observed, $check, $readStarted)
                : $this->finishUnavailable($id, $read, $observed, $check, $readStarted, $callCapBlocked);
        } catch (\Throwable $error) {
            $observed->retainAfterInterruption($read, $this->attempts);
            throw $error;
        }
    }

    private function finishUnavailable(int $id, AcquisitionRead $read, AcquisitionObservation $observed, RegistrarCheck $check,
        bool $readStarted, bool $callCapBlocked): Operation
    {
        $attempt = $read->attempt;
        $scan = $observed->scan;
        // An order history read of a lost reply counts toward the read limit even when it cannot confirm the order.
        $historyRead=!$callCapBlocked&&$attempt->query->action->value!=='transfer'&&$attempt->replyLost()
            &&($attempt->historyAcceptance?->covers($attempt,$this->clock->now())??false)&&($check->context?->exchanges()??0)>0;
        if (!$callCapBlocked) {
            return $this->finishRead($id, $read, $observed, $check, function ($op, $domain, $all, $funding, $server, $account, $permit, $writer)
                use ($read, $readStarted, $historyRead, $attempt, $check, $scan, $observed): void {
                $observed->retainCharge($op, $read, $writer);
                $policy=TransferPolling::finished($op->policy,$readStarted);
                if ($read->watch && $readStarted) {
                    $result = $read->followup && ($check->context?->completedReads() ?? 0) > 0 ? 'unknown' : 'unavailable';
                    $policy = TransferFollowup::record($op, $policy, $permit->now->getTimestamp(), $result);
                }
                if($scan){$policy['history_scan']=$scan->saved();}
                // Every completed history read counts; a scan that resolved more orders runs its next check sooner.
                if($historyRead){
                    $policy['reconciliation']['polls']=($policy['reconciliation']['polls']??0)+1;$check->counted=true;$check->continued=(bool)$scan?->progressed;
                    $limit=$policy['reconciliation']['polls']>=self::POLL_LIMIT;
                    $policy['reconciliation']['reason']=$limit?'poll_limit_reached':'original_attempt_unresolved';
                    if($limit){self::askRegistrar($check,$op,$attempt);}
                }
                if($read->watch){$policy['reconciliation']['staff_alert']=true;$policy['reconciliation']['reason']=($policy['reconciliation']['final_check']??null)==='done'?'transfer_deadline_passed':'transfer_status_unavailable';}
                elseif(in_array($op->safe_code,['enom_test_reconciliation_call_cap','enom_test_transfer_reconciliation_call_cap','enom_test_reconciliation_send_guard'],true)){$policy['reconciliation']['reason']='reconciliation_required';$op->safe_code='reconciliation_required';}
                if($op->action==='transfer'){$policy['reconciliation']['read_failed']=true;}
                $op->update(['policy'=>$policy]);
            });
        }
        return $this->finishRead($id, $read, $observed, $check, function ($op, $domain, $all, $funding, $server, $account, $permit, $writer)
            use ($read, $check, $observed): void {
            $observed->retainCharge($op, $read, $writer);
            $policy = $op->policy;
            $policy['reconciliation']['staff_alert'] = true;
            $policy['reconciliation']['reason'] = $check->deferred==='send_guard'?'enom_test_reconciliation_send_guard':($op->action==='transfer'?'enom_test_transfer_reconciliation_call_cap':'enom_test_reconciliation_call_cap');
            $op->update(['safe_code' => $policy['reconciliation']['reason'], 'policy' => $policy]);
        });
    }

    private function finishObserved(int $id, AcquisitionRead $read, AcquisitionObservation $observation, RegistrarCheck $check, bool $readStarted): Operation
    {
        return $this->finishRead($id, $read, $observation, $check, function ($op, $domain, $all, $funding, $server, $account, $permit, $writer)
            use ($read, $observation, $check, $readStarted): void {
            $attempt = $read->attempt;
            try {
                $binding = $this->authority($op, $domain, $all, $funding, $server, $account, $read->followup);
                $contract = self::contract(ReconciliationEvidence::attempt($op, $domain, $binding));
                $binding->requireMatch($attempt->binding);
            } catch (\Throwable) {
                if ($op->action === 'register') { $observation->retainCharge($op, $read, $writer); }
                if(TransferPolling::applies($op)){$p=TransferPolling::finished($op->policy,$readStarted);$p['reconciliation']['staff_alert']=true;$p['reconciliation']['reason']='transfer_authority_changed';$op->update(['policy'=>$p]);}
                return;
            }
            if ((!$observation->proof && $contract === $read->contract) || ($contract !== $read->contract && $op->action === 'register')) {
                $observation->retainCharge($op, $read, $writer);
            }
            if ($contract !== $read->contract) { return; }
            $policy = TransferPolling::finished($op->policy,$readStarted); $policy['reconciliation']['polls'] = ($policy['reconciliation']['polls'] ?? 0) + 1; $check->counted = true;
            if ($observation->scan) { $policy['history_scan'] = $observation->scan->saved(); }
            $policy['reconciliation']['staff_alert']=true;
            if(in_array($op->safe_code,['enom_test_reconciliation_call_cap','enom_test_transfer_reconciliation_call_cap','enom_test_reconciliation_send_guard'],true)){$op->safe_code='reconciliation_required';}
            $transfer = $observation->transfer();
            if ($op->action === 'transfer' && TransferObservation::apply($op, $observation, $policy, $readStarted, $permit->now->getTimestamp())) { return; }
            if (!$observation->proof && !$observation->chargeAnomaly && $op->action==='transfer' && $transfer===TransferStatus::Pending) {
                $expired=TransferPolling::expired($op,$permit->now->getTimestamp());
                $policy['reconciliation']['reason']=$expired?'transfer_deadline_passed':'transfer_pending';
                $policy['reconciliation']['staff_alert']=$expired;
                $op->update(['policy'=>$policy]);return;
            }
            if (!$observation->proof) {
                $policy['reconciliation']['reason'] = $op->action==='transfer' ? ($observation->chargeAnomaly?'transfer_charge_unexpected':($transfer===TransferStatus::Unknown?'transfer_status_unavailable':'transfer_completion_unconfirmed'))
                    : ($policy['reconciliation']['polls'] >= self::POLL_LIMIT ? 'poll_limit_reached' : 'original_attempt_unresolved');
                $op->update(['policy' => $policy]); return;
            }
            AcquisitionSettlement::complete($op, $domain, $funding, $read, $observation, $policy, $writer, $permit);
        });
    }
    /** State, scheduling and check status share one live permit and one transaction. */
    private function finishRead(int $id, AcquisitionRead $read, AcquisitionObservation $observed, RegistrarCheck $check,
        ?\Closure $publish = null): Operation
    {
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account)
            use ($read, $observed, $check, $publish): Operation {
            $now = $this->clock->now();
            $state = AcquisitionLifecycle::settle($op, $domain, $now);
            $writer = OperationWriter::at($now);
            if ($state->phase === AcquisitionPhase::Terminal) { return $op; }
            $observed->retainOwnership($op, $domain, $account, $read, $writer);
            $permit = AcquisitionReadPermit::issue($state, $read->token, $now);
            if (!$permit || !$this->eligible($op, $now) || AcquisitionRead::stamp($op, $domain) !== $read->stamp) {
                if ($op->action === 'register') { $observed->retainCharge($op, $read, $writer); }
                if ($permit) { $writer->closeRead($permit->token); }
                return $op;
            }
            if ($publish) {
                $publish($op, $domain, $all, $funding, $server, $account, $permit, $writer);
            } else {
                $observed->retainCharge($op, $read, $writer);
            }
            if ($op->state !== 'succeeded' && !$writer->closeRead($permit->token)) {
                throw new \DomainException('Acquisition read authority changed.');
            }
            $op->reloadAcquisitionAttempt();
            $check->publishAcquisition($op, $permit);
            return $op;
        });
    }

    public function resume(int $id, string $reason, ?StaffOperationReview $review = null): Operation
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason)) { throw new \InvalidArgumentException('Safe resume reason required.'); }
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account, $staff) use ($reason, $review): Operation {
            $l=['domain'=>$domain,'operations'=>$all,'staff'=>$staff]; $review?->check($l);
            $at = $this->clock->now();
            $state = AcquisitionLifecycle::settle($op, $domain, $at);
            if (!$this->eligible($op, $at) && ($state->retired || $state->fenced)) { return $op; }
            if (!$this->eligible($op, $at)) { throw new \DomainException('No unresolved attempt.'); }
            $this->authority($op, $domain, $all, $funding, $server, $account);
            $now = $at->getTimestamp();
            if ($state->facts?->reading($at)) { return $op; }
            $policy = TransferFollowup::retain($op); $history = $policy['reconciliation_resumptions'] ?? [];
            if(TransferPolling::applies($op)&&($policy['reconciliation']['manual_check']??false)){return $op;}
            if ($review !== null && TransferPolling::followupScheduled($op, $now)) {
                throw new StaffReviewRefused('Automatic transfer checks are still scheduled.');
            }
            if (count($history) >= 20) { throw new \DomainException('Resume audit is full.'); }
            $resumption=['reason' => $reason, 'at' => $this->clock->now()->format(DATE_ATOM), 'prior_polls' => $policy['reconciliation']['polls'] ?? 0];
            if(TransferPolling::applies($op)){$policy['reconciliation']['manual_check']=true;$policy['reconciliation']['manual_resumption']=$resumption;$policy['reconciliation']['next_at']=0;}
            else{$policy['reconciliation_resumptions']=[...$history,$resumption];}
            $policy['reconciliation']['polls'] = 0; $policy['reconciliation']['reason'] = 'resumed';
            $op->update(['policy' => $policy]); RegistrarCheck::resumed($op, $this->clock->now()->getTimestamp()); $review?->record($l); return $op;
        }, $review?->actorId);
    }
    private static function askRegistrar(RegistrarCheck $check, Operation $op, AttemptEvidence $attempt): void
    {
        $check->awaitingAnswer = $attempt->binding->registrarKey === 'enom' ? 'Enom' : 'NameSilo';
        $check->answerAfter = (new \DateTimeImmutable($op->attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp();
    }
    /** Preserve transfer pacing once a provider exchange started. */
    private function deferred(int $id, AcquisitionRead $read, RegistrarCheck $check): Operation
    {
        $noWire = ($check->context?->exchanges() ?? 0) === 0;
        return $this->finishRead($id, $read, new AcquisitionObservation(), $check, function ($op, $domain, $all, $funding, $server, $account, $permit)
            use ($read, $noWire): void {
            $now = $permit->now->getTimestamp();
            $p=TransferPolling::defer($op->policy,$now,$noWire);$r=$p['reconciliation']??[];
            if ($read->followup && !$noWire) {
                $p = TransferFollowup::record($op, $p, $now, 'incomplete');
            }
            $ordinary=($r['reason']??null)==='transfer_read_deferred'
                || (($r['reason']??null)==='original_attempt_unresolved'&&($r['polls']??0)===0)
                || (TransferPolling::expected($op)&&($r['transfer_status']??null)==='pending');
            $overdue=TransferPolling::expired($op,$now);
            if($ordinary||$overdue&&in_array($r['reason']??null,['transfer_deadline_passed','resumed'],true)){
                $p['reconciliation']['staff_alert']=$overdue;$p['reconciliation']['reason']=$overdue?'transfer_deadline_passed':'transfer_read_deferred';
            }
            $op->update(['policy'=>$p]); // Preserve an existing anomaly or old-registrar action notice.
        });
    }
    private function eligible(Operation $op, \DateTimeImmutable $now): bool
    {
        if (!in_array($op->action, ['register', 'transfer'], true)) { return false; }
        if (AcquisitionRetryProof::expiredClaim($op, $now)) { return false; }
        if ($op->state === 'running') { return !$op->lease_expires_at || $op->lease_expires_at <= $now; }
        return ($op->state === 'held' && $op->safe_code === 'transfer_needs_action') || $op->state === 'uncertain' || ($op->state === 'pending'
            && ($op->attempt['registration']['financial_completion'] ?? null) === 'awaiting_unique_charge');
    }
    private function unresolved(Operation $op, ?string $reason = null): void
    {
        $policy = $op->policy ?? [];
        if($reason === null && TransferPolling::applies($op)&&isset($policy['reconciliation'])){
            if($op->state==='running'){$op->update(['state'=>'uncertain','lease_expires_at'=>null,'safe_code'=>'stale_claim']);}
            return;
        }
        $policy['reconciliation'] = [...($policy['reconciliation'] ?? []), 'polls' => $policy['reconciliation']['polls'] ?? 0,
            'staff_alert' => true, 'reason' => $reason ?? ($op->state === 'held' && $op->safe_code === 'transfer_needs_action' ? 'transfer_needs_action'
                : (($policy['reconciliation']['polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT ? 'poll_limit_reached' : 'original_attempt_unresolved'))];
        $op->update(['policy' => $policy, 'state' => $op->state === 'running' ? 'uncertain' : $op->state,
            'lease_expires_at' => null, 'safe_code' => $op->state === 'running' ? 'stale_claim' : ($op->safe_code ?: 'reconciliation_required')]);
    }
    private function authority($op, $domain, $all, $funding, $server, $account, bool $followup = false)
    {
        $this->bindings->requireReadableLocked($server, $account); $binding = AccountBindings::binding($account);
        if ($domain->status !== ($op->action === 'transfer' ? 'transfer_pending' : 'registration_pending') || !$domain->billing_managed || $domain->reservation_name !== $domain->domain
            || $domain->provider_expires_at !== null || $domain->provider_domain_id !== null || $domain->provider_order_id !== null
            || $op->confirmed_expires_at !== null || $op->authority_generation !== $domain->authority_generation
            || $op->account_fingerprint !== $binding->fingerprint || $domain->account_fingerprint !== $binding->fingerprint
            || $op->registrar_key !== $binding->registrarKey || $domain->registrar_key !== $binding->registrarKey
            || (int) $op->server_id !== $binding->serverId || (int) $domain->server_id !== $binding->serverId
            || $all->contains(static fn ($other) => $other->id !== $op->id && !OperationTerminalProof::safe($other, $domain)
                && !ManagementCorrections::resolvedFor($other, $op, $domain))) {
            throw new \DomainException('Reconciliation authority changed.');
        }
        $paid = $funding->requireInitial($op, $domain);
        if ($followup) { TransferFollowup::requireClaim($op, $domain, $paid); }
        return $binding;
    }
    private static function contract(AttemptEvidence $attempt): string { return json_encode($attempt->historyAcceptance, JSON_THROW_ON_ERROR); }
}
