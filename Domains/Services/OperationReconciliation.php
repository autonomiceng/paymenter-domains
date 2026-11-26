<?php
// Enom 444f31c reconciliation-registration/provider-disposition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Contracts\TransferPoller;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, Evidence, NameserverEvidence, RequestBudget, RequestContext, RequestOrigin, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{OperationWriter, ReadToken, RetainedAcquisition};
use Paymenter\Extensions\Servers\Domains\Operations\Reconciliation\{AcquisitionObservation, AcquisitionRead, AcquisitionSettlement};
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
        $op = Operation::findOrFail($id);
        if (!$this->eligible($op)) { return $op; }
        $check = new RegistrarCheck($this->clock);
        return $check->finish($this->read($id, $check));
    }
    private function capture(int $id, RegistrarCheck $check): ?AcquisitionRead
    {
        OperationClaims::outside();
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($check): ?AcquisitionRead {
            if (!$this->eligible($op)) { return null; }
            $watch=TransferPolling::applies($op);$now=$this->clock->now()->getTimestamp();
            if (TransferPolling::reading($op, $now)) { return null; }
            $check->capture($op);
            $manual=$watch&&($op->policy['reconciliation']['manual_check']??false);
            if($watch&&!TransferPolling::due($op,$now)){$check->deferred='scheduled';return null;}
            $followup = $watch && !$manual && ($op->policy['reconciliation']['final_check'] ?? null) === 'done';
            $this->unresolved($op);
            // Eloquent's UPDATE can advance updated_at beyond the model's timestamp.
            // Capture persisted values under these locks before reading the provider.
            $op->refresh();
            try {
                $binding = $this->authority($op, $domain, $all, $funding, $server, $account, $followup);
                $attempt = ReconciliationEvidence::attempt($op, $domain, $binding);
                if (HistoryResumption::apply($op, $attempt, $this->clock->now())) { $op->refresh(); }
                if (RegistrarCheck::lostReply($op, $attempt, $this->clock->now())) {
                    // NameSilo's order history does not list the order yet. Wait for it; nobody needs to ask NameSilo.
                    if (RegistrarCheck::historyPending($attempt, $this->clock->now())) { $check->deferred = 'scheduled'; return null; }
                    self::askRegistrar($check, $op, $attempt);
                    // Enom can still read the domain before staff ask for a case; NameSilo waits for its answer.
                    if ($attempt->binding->registrarKey !== 'enom') { return null; }
                }
                if (($op->policy['reconciliation']['polls'] ?? 0) >= self::POLL_LIMIT && !TransferPolling::expected($op) && !$manual && !($watch&&TransferPolling::expired($op,$now))) {
                    // The order history could not confirm a lost reply within the read limit, so only the registrar's answer can settle it.
                    if ($op->action !== 'transfer' && $attempt->replyLost()) { self::askRegistrar($check, $op, $attempt); }
                    return null;
                }
                $requestedNameservers = null;
                if ($op->action === 'transfer' && $op->registrar_key === 'namesilo' && ($op->payload['nameservers'] ?? [])) {
                    try { $requestedNameservers = NameserverEvidence::requested($op->payload['nameservers'], 'transfer_request', new \DateTimeImmutable($op->attempt['dispatch_attempted_at'])); }
                    catch (\Throwable) { /* Invalid submitted data is not a nameserver fact. */ }
                }
                $deadline = $now + self::READ_SECONDS;
                if ($watch && !$manual) { $deadline = min($deadline, TransferPolling::cutoff($op)); }
                $until = new \DateTimeImmutable('@'.$deadline);
                $sealed = RetainedAcquisition::adopt($op, $this->attempts, $this->clock->now());
                if ($sealed && (!$sealed->sealed() || $deadline <= $now)) { return null; }
                if ($watch) { TransferPolling::reserve($op, $now); $op->refresh(); }
                $stamp = self::stamp($op, $domain);
                $contract = self::contract($attempt);
                $light = $watch && TransferPolling::light($op, $now);
                $read = $sealed ? $this->attempts->openRead($sealed->facts->token, $until) : null;
                if ($read !== null && !$read instanceof ReadToken) { $check->discard(); return null; }
                return new AcquisitionRead($attempt, $stamp, $contract, $requestedNameservers, $watch, $light, $followup, $until, $read);
            } catch (\Throwable) {
                if($watch){$p=$op->policy;$p['reconciliation']['staff_alert']=true;$p['reconciliation']['reason']='transfer_authority_changed';if(TransferPolling::expired($op,$now)){$p['reconciliation']['final_check']='done';}$op->update(['policy'=>$p]);}
                return null;
            }
        });
    }

    private function read(int $id, RegistrarCheck $check): Operation
    {
        $capture = $this->capture($id, $check);
        if (!$capture) { return Operation::findOrFail($id); }
        $read = $capture->token;
        try {
            return $this->readCaptured($id, $check, $capture);
        } finally {
            if ($read !== null) {
                FreshTransaction::run(function () use ($id, $read): void {
                    Operation::whereKey($id)->lockForUpdate()->firstOrFail();
                    $this->attempts->closeRead($read);
                });
            }
        }
    }

    private function exchange(AcquisitionRead $read, RegistrarCheck $check): Evidence
    {
        $attempt = $read->attempt;
        $limiter = $read->watch ? new TransferReadBudget($this->clock, Runtime::limiter()) : null;
        $context = $check->context = new RequestContext($attempt->binding, RequestOrigin::Background, $read->until,
            new RequestBudget(25), $this->clock, $limiter ?? Runtime::limiter(), deferOnReservationLimit: $read->watch);
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
        $callCapBlocked = false;
        try {
            $facts = $this->exchange($read, $check);
            // Scan progress is reusable after the deadline. Positive proof still needs an in-time read.
            $now = $this->clock->now();
            $observed = $now < $read->until ? AcquisitionObservation::from($read, $facts, $now) : new AcquisitionObservation($facts->historyScan);
            if ($now < $read->until) { $check->observe($read->attempt, $facts, $observed->proof !== null); }
        } catch (ReadDeferred|LiveReservationLimit) {
            $check->deferred = 'read_limit';
            return $read->watch ? $this->deferred($id, $read->stamp, ($check->context?->exchanges() ?? 0) === 0, $read->followup, $read->token):Operation::findOrFail($id);
        } catch (EnomTestCallLimit $error) {
            $check->deferred = $error->reason === 'send_guard' ? 'send_guard' : 'send_limit';
            // Enom’s daily guard runs after limiter admission, before the physical exchange.
            if ($read->watch){return $this->deferred($id, $read->stamp, ($check->context?->exchanges() ?? 0) === 0, $read->followup, $read->token);}
            $callCapBlocked = true;
        } catch (\Throwable) {} // Safe retained alert; transport/contract failures do not manufacture facts.
        $readStarted=!$read->watch||($check->context?->exchanges() ?? 0) > 0;
        return $observed->covered ? $this->finishObserved($id, $read, $observed, $check, $readStarted)
            : $this->finishUnavailable($id, $read, $observed, $check, $readStarted, $callCapBlocked);
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
            return $this->claims->locked($id,function($op,$domain)use ($read, $readStarted, $historyRead, $attempt, $check, $scan):Operation {
                if (!$this->matchesRead($op, $domain, $read->stamp, $read->token)) { return $op; }
                $policy=TransferPolling::finished($op->policy,$readStarted);
                if ($read->watch && $readStarted) {
                    $result = $read->followup && ($check->context?->completedReads() ?? 0) > 0 ? 'unknown' : 'unavailable';
                    $policy = TransferFollowup::record($op, $policy, $this->clock->now()->getTimestamp(), $result);
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
                $op->update(['policy'=>$policy]);return $op;
            });
        }
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($read, $check): Operation {
            if (!$this->matchesRead($op, $domain, $read->stamp, $read->token)) { return $op; }
            $policy = $op->policy;
            $policy['reconciliation']['staff_alert'] = true;
            $policy['reconciliation']['reason'] = $check->deferred==='send_guard'?'enom_test_reconciliation_send_guard':($op->action==='transfer'?'enom_test_transfer_reconciliation_call_cap':'enom_test_reconciliation_call_cap');
            $op->update(['safe_code' => $policy['reconciliation']['reason'], 'policy' => $policy]);
            return $op;
        });
    }

    private function finishObserved(int $id, AcquisitionRead $read, AcquisitionObservation $observation, RegistrarCheck $check, bool $readStarted): Operation
    {
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($read, $observation, $check, $readStarted): Operation {
            $attempt = $read->attempt;
            $token = $read->token;
            $facts = $observation->facts;
            if (!$this->matchesRead($op, $domain, $read->stamp, $token)) { return $op; }
            try {
                $binding = $this->authority($op, $domain, $all, $funding, $server, $account, $read->followup);
                if (self::contract(ReconciliationEvidence::attempt($op, $domain, $binding)) !== $read->contract) { return $op; }
                $binding->requireMatch($attempt->binding);
            } catch (\Throwable) {
                if(TransferPolling::applies($op)){$p=TransferPolling::finished($op->policy,$readStarted);$p['reconciliation']['staff_alert']=true;$p['reconciliation']['reason']='transfer_authority_changed';$op->update(['policy'=>$p]);}
                return $op;
            }
            if ($token && $facts->domain?->owned === true && $facts->domain->binding->matches($binding)
                && $facts->domain->name === $attempt->query->name && $this->attempts->ownsRead($token)) {
                $this->attempts->recordObservation($token->attempt, ['owned' => true, 'expiry' => $facts->domain->expiry?->value], $this->clock->now());
            }
            $policy = TransferPolling::finished($op->policy,$readStarted); $policy['reconciliation']['polls'] = ($policy['reconciliation']['polls'] ?? 0) + 1; $check->counted = true;
            if ($observation->scan) { $policy['history_scan'] = $observation->scan->saved(); }
            $policy['reconciliation']['staff_alert']=true;
            if(in_array($op->safe_code,['enom_test_reconciliation_call_cap','enom_test_transfer_reconciliation_call_cap','enom_test_reconciliation_send_guard'],true)){$op->safe_code='reconciliation_required';}
            $transfer = $observation->transfer();
            if ($op->action === 'transfer' && TransferObservation::apply($op, $observation, $policy, $readStarted, $this->clock->now()->getTimestamp())) { return $op; }
            if (!$observation->proof && !$observation->chargeAnomaly && $op->action==='transfer' && $transfer===TransferStatus::Pending) {
                $expired=TransferPolling::expired($op,$this->clock->now()->getTimestamp());
                $policy['reconciliation']['reason']=$expired?'transfer_deadline_passed':'transfer_pending';
                $policy['reconciliation']['staff_alert']=$expired;
                $op->update(['policy'=>$policy]);return $op;
            }
            if (!$observation->proof) {
                $policy['reconciliation']['reason'] = $op->action==='transfer' ? ($observation->chargeAnomaly?'transfer_charge_unexpected':($transfer===TransferStatus::Unknown?'transfer_status_unavailable':'transfer_completion_unconfirmed'))
                    : ($policy['reconciliation']['polls'] >= self::POLL_LIMIT ? 'poll_limit_reached' : 'original_attempt_unresolved');
                $op->update(['policy' => $policy]); return $op;
            }
            if ($token && !$this->attempts->ownsRead($token)) { return $op; }
            AcquisitionSettlement::complete($op, $domain, $funding, $read, $observation, $policy, $this->attempts, $this->clock->now());
            return $op;
        });
    }
    /** Internal audited resume seam; staff authorization/route belongs to36. No send authority. */
    public function resume(int $id, string $reason, ?StaffOperationReview $review = null): Operation
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason)) { throw new \InvalidArgumentException('Safe resume reason required.'); }
        return $this->claims->locked($id, function ($op, $domain, $all, $funding, $server, $account, $staff) use ($reason, $review): Operation {
            $l=['domain'=>$domain,'operations'=>$all,'staff'=>$staff]; $review?->check($l);
            if (!$this->eligible($op)) { throw new \DomainException('No unresolved attempt.'); }
            $this->authority($op, $domain, $all, $funding, $server, $account);
            $now = $this->clock->now()->getTimestamp();
            if (TransferPolling::reading($op, $now)) { return $op; }
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
    /** Staff ask the registrar to check this attempt and can record its answer 24 hours after the send. */
    private static function askRegistrar(RegistrarCheck $check, Operation $op, AttemptEvidence $attempt): void
    {
        $check->awaitingAnswer = $attempt->binding->registrarKey === 'enom' ? 'Enom' : 'NameSilo';
        $check->answerAfter = (new \DateTimeImmutable($op->attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp();
    }
    /** Retry zero-wire capacity refusals promptly; preserve pacing after a read and any existing anomaly. */
    private function deferred(int $id,string $stamp,bool $noWire,bool $followup, ?ReadToken $token): Operation
    {
        return $this->claims->locked($id,function($op,$domain)use($stamp,$noWire,$followup,$token):Operation {
            if (!$this->matchesRead($op, $domain, $stamp, $token)) { return $op; }
            $p=TransferPolling::defer($op->policy,$this->clock->now()->getTimestamp(),$noWire);$r=$p['reconciliation']??[];
            if ($followup && !$noWire) {
                $p = TransferFollowup::record($op, $p, $this->clock->now()->getTimestamp(), 'incomplete');
            }
            $ordinary=($r['reason']??null)==='transfer_read_deferred'
                || (($r['reason']??null)==='original_attempt_unresolved'&&($r['polls']??0)===0)
                || (TransferPolling::expected($op)&&($r['transfer_status']??null)==='pending');
            $overdue=TransferPolling::expired($op,$this->clock->now()->getTimestamp());
            if($ordinary||$overdue&&in_array($r['reason']??null,['transfer_deadline_passed','resumed'],true)){
                $p['reconciliation']['staff_alert']=$overdue;$p['reconciliation']['reason']=$overdue?'transfer_deadline_passed':'transfer_read_deferred';
            }
            $op->update(['policy'=>$p]);return $op; // Preserve an existing anomaly or old-registrar action notice.
        });
    }
    private function matchesRead(Operation $op, Domain $domain, string $stamp, ?ReadToken $token): bool
    {
        return $this->eligible($op) && self::stamp($op, $domain) === $stamp
            && (!$token || $this->attempts->ownsRead($token, allowExpired: true));
    }

    private function eligible(Operation $op): bool
    {
        if (!in_array($op->action, ['register', 'transfer'], true)) { return false; }
        if ($op->state === 'running') { return !$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now(); }
        return ($op->state === 'held' && $op->safe_code === 'transfer_needs_action') || $op->state === 'uncertain' || ($op->state === 'pending'
            && ($op->attempt['registration']['financial_completion'] ?? null) === 'awaiting_unique_charge');
    }
    private function unresolved(Operation $op): void
    {
        $policy = $op->policy ?? [];
        if(TransferPolling::applies($op)&&isset($policy['reconciliation'])){
            if($op->state==='running'){$op->update(['state'=>'uncertain','lease_expires_at'=>null,'safe_code'=>'stale_claim']);}
            return;
        }
        $policy['reconciliation'] = [...($policy['reconciliation'] ?? []), 'polls' => $policy['reconciliation']['polls'] ?? 0,
            'staff_alert' => true, 'reason' => $op->state === 'held' && $op->safe_code === 'transfer_needs_action' ? 'transfer_needs_action'
                : (($policy['reconciliation']['polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT ? 'poll_limit_reached' : 'original_attempt_unresolved')];
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
    private static function stamp(Operation $op, Domain $domain): string {
        $facts = static fn ($model) => array_map(static fn ($key) => $model->getAttribute($key), array_keys($model->getAttributes()));
        return hash('sha256', json_encode([$facts($op), $facts($domain)], JSON_THROW_ON_ERROR));
    }
    private static function contract(AttemptEvidence $attempt): string { return json_encode($attempt->historyAcceptance, JSON_THROW_ON_ERROR); }
}
