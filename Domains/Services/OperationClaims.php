<?php
// Claim/seal semantics adapted from Enom 444f31c Services/DomainOperations.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, SealPreflight, WriteGate};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AdmittedOperation, BalanceRetry, DispatchReadiness, DriverResult,
    Money, Query, RequestContext, SendFailure, SendState};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, ProfileContactOperation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAdmission, AcquisitionAttempt, AcquisitionAuthority, AcquisitionDispatch, AcquisitionLane, AcquisitionResult,
    AcquisitionRetryProof, AcquisitionWindow, AttemptToken, OperationAttempts, OperationSchema, OperationWriter, Transition, WireOutcome};

/** All financial checks run twice: claim and final seal. No provider callback runs under these locks. */
final class OperationClaims
{
    private readonly OperationWriter $attempts;
    private readonly AcquisitionAuthority $authority;
    private readonly AcquisitionDispatch $dispatch;
    public function __construct(AccountBindings $bindings, private readonly Clock $clock, ?\Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities $offers = null)
    {
        $this->attempts = new OperationWriter($clock);
        $this->authority = new AcquisitionAuthority($bindings, $clock, $offers);
        $this->dispatch = new AcquisitionDispatch($this->authority, $this->attempts, $clock);
    }

    /** Balance readmission checks the due continuation and retained proof under the financial locks. */
    public function readmitBalance(int $id): bool
    {
        return $this->locked($id,function($op,$domain,$all,$funding,$server,$account):bool {
            $continuation = AcquisitionResult::continuation($op, true);
            if ($op->state !== 'held' || $continuation?->lane !== AcquisitionLane::BalanceWait
                || !$account->processing_enabled) { return false; }
            $facts=$funding->initialFacts($domain)+['account'=>$account,'operations'=>$all];
            $reason=AcquisitionRetryProof::balanceAdmissionReason($op,$facts);
            if($reason===null){try{$this->authority->requireProcessingLocked($server,$account);}catch(\Throwable){$reason='balance_retry_authority_changed';}}
            $now = $this->clock->now();
            if (!$continuation->due($now) || AcquisitionAuthority::fundsCutoff($op, $continuation, $now) !== AcquisitionAuthority::FUNDS_PROCEED) {
                return false;
            }
            if($reason!==null){
                $review=in_array($reason,BalanceRetries::REVIEW_CODES,true);
                if($review&&AcquisitionRetryProof::virgin($op)){
                    // No original claim exists to replace. Recheck repaired native facts on backoff, without provider reads.
                    AcquisitionResult::schedule($op, AcquisitionLane::BalanceWait, $now->getTimestamp() + (BalanceRetry::from($op)?->delay() ?? 900));
                    return false;
                }
                // Frozen purchase proof cannot be replaced by a changed invoice, owner or desired state.
                $policy=$op->policy;
                if($review&&BalanceRetry::from($op)===null){$policy['balance_retry']=BalanceRetry::held($op,$now,null)->record;}
                $op->update(['safe_code'=>$reason,'policy'=>$policy]);
                // Recheck local proof on backoff. Unresolved send evidence never enters this retry path.
                AcquisitionResult::schedule($op, $review ? AcquisitionLane::BalanceWait : null,
                    $review ? $now->getTimestamp() + BalanceRetry::from($op)->delay() : null);
                if(\Illuminate\Support\Facades\Schema::hasTable('domains_obligation_alerts')){ObligationAlerts::record($domain,'operation_review',$now->getTimestamp(),(int)$op->id);}return false;
            }
            if (ExecutionWindow::pastTransferCutoff($op, $now)) {
                $op->update(['safe_code' => 'transfer_setup_review_required']);
                AcquisitionResult::schedule($op, null, null);
                return false;
            }
            $retry=BalanceRetry::held($op,$now,null)->admitted($op,$now);
            $policy = [...$op->policy, 'balance_retry' => $retry->record];
            AcquisitionResult::admit($op, AcquisitionAdmission::Balance, $continuation->revision,
                $op->acquisitionAttemptFacts()?->token, $now,
                static fn () => $op->update(['state' => 'queued', 'safe_code' => null, 'policy' => $policy]));
            return true;
        });
    }

    public function readmitOrdersOff(int $id): bool
    {
        return $this->locked($id,function($op,$domain,$all,$funding,$server,$account):bool {
            if(!$account->processing_enabled
                ||!AcquisitionRetryProof::ordersOffReturns($op,fn()=>$funding->initialFacts($domain)+['account'=>$account,'operations'=>$all])){return false;}
            $continuation = AcquisitionResult::continuation($op, true);
            $contactBlocked = ContactStepRetries::candidate($op) && ContactProfileHold::releaseBlocked($op) !== null;
            $now = $this->clock->now();
            if (AcquisitionAuthority::fundsCutoff($op, $continuation, $now) !== AcquisitionAuthority::FUNDS_PROCEED) { return false; }
            $window = $continuation->window;
            if ($window && !$window->fits($now)) {
                $this->expireWindow($op, $window, $now);
                return false;
            }
            if ($contactBlocked) { return false; }
            $op->update(['state'=>'queued','safe_code'=>null]);
            // Details the customer still owes are their hold, not a quiet wait in the queue.
            try{ContactSetup::requireReady($op);}catch(\DomainException){DB::afterCommit(static fn()=>ContactRecovery::hold((int)$op->id));}
            return true;
        });
    }

    public function claim(int $id, ?\DateTimeImmutable $startedAt = null): ?array
    {
        self::outside();
        $route = Operation::findOrFail($id);
        $now = $this->clock->now();
        $recover = $route->state !== 'queued'
            && (AcquisitionRetryProof::localRecovery($route, $now) || ContactProfileHold::forReadmission($route, $now) !== null);
        if ($route->state === 'running' && !$recover) {
            $this->retireExpiredWorker($route);
            return null;
        }
        if (!$recover && !in_array($route->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) { return null; }
        return $this->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($startedAt, $recover): ?array {
            $op->setRelation('domain', $domain);
            if ($op->state !== 'queued') {
                $routeAt = $this->clock->now();
                $contact = ContactProfileHold::forReadmission($op, $routeAt, true);
                if ($contact || AcquisitionRetryProof::localRecovery($op, $routeAt)) {
                    return $this->recoverUnsent($op, $domain, $all, $funding, $server, $account, $contact);
                }
                if ($op->state === 'running') {
                    $this->authority->expire($op, $routeAt);
                    return null; // Other expired claims continue to reconciliation.
                }
            } elseif ($recover) {
                return null;
            }
            if (!in_array($op->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) { return null; }
            $continuation = AcquisitionResult::continuation($op, true);
            if (in_array($continuation->lane, [AcquisitionLane::BalanceWait, AcquisitionLane::FundsWait,
                AcquisitionLane::PriceWait, AcquisitionLane::ContactWait], true)) { return null; }
            if ($op->action === 'transfer' && !TransferInputs::validCode($op->payload['epp'] ?? null)) { return null; }
            $revision = $continuation->revision;
            $window = $continuation->window;
            $prior = AcquisitionAttempt::of((int) $op->id, $op->action, true);
            $first = AcquisitionResult::unclaimed($op) && AcquisitionRetryProof::virgin($op);
            $compatible = $first || ($prior && $window && AcquisitionResult::continues($op, $window, $prior->facts));
            $failure = null;
            $contactReady = true;
            if ($compatible) {
                try { [$binding, $paid] = $this->authority->validate($op, $domain, $all, $funding, $server, $account); }
                catch (\Throwable $failure) {}
                if ($failure === null) {
                    try { ContactSetup::requireReady($op); }
                    catch (\DomainException) { $contactReady = false; }
                }
            }
            // First-claim bounds and the funds cutoff use the clock after the locked authority reads.
            $now = $this->clock->now();
            if (AcquisitionAuthority::fundsCutoff($op, $continuation, $now) !== AcquisitionAuthority::FUNDS_PROCEED
                || $continuation->lane !== null && !$continuation->due($now)) { return null; }
            // Discovery may not have visited this order before dispatch starts.
            ObservationWork::discardUnsent($domain,$now,$all);
            if (!$compatible) {
                $op->update(['state' => 'uncertain', 'safe_code' => 'prior_attempt', 'lease_expires_at' => null]);
                AcquisitionResult::schedule($op, null, null);
                return null;
            }
            if (ExecutionWindow::pastTransferCutoff($op, $now)) {
                $op->update(['state' => 'held', 'safe_code' => 'transfer_setup_review_required']);
                AcquisitionResult::schedule($op, null, null);
                return null;
            }
            if ($failure instanceof RuntimeIneligible) {
                $reason = $failure->reason === RuntimeReason::ProcessingOff ? 'processing' : 'claim_authority_invalid';
                $op->update(['state' => 'held', 'safe_code' => $reason]);
                self::recordHold($op, $now);
                return null;
            }
            if ($failure !== null) {
                $op->update(['state' => 'held', 'safe_code' => 'claim_authority_invalid']);
                AcquisitionResult::schedule($op, null, null);
                return null;
            }
            if (!$contactReady) {
                // The contact service owns its transaction and rechecks payment/identity after commit.
                DB::afterCommit(static fn () => ContactRecovery::hold((int) $op->id));
                return null;
            }
            // The worker still has its entry budget.
            if ($startedAt !== null && ($startedAt > $now || $startedAt < $now->modify('-'.ExecuteOperation::EXECUTION_SECONDS.' seconds'))) {
                $op->update(['state' => 'held', 'safe_code' => 'claim_authority_invalid']);
                AcquisitionResult::schedule($op, null, null);
                return null;
            }
            $digest = self::digest($op, $domain);
            if ($first) {
                AcquisitionResult::admit($op, AcquisitionAdmission::FirstClaim, $revision, null, $now,
                    fn () => $this->startWorker($op, null, $digest, $paid, $now));
            } else {
                if (!$window->fits($now)) {
                    $this->expireWindow($op, $window, $now);
                    return null;
                }
                if ($prior->hasOriginalClaim() && ($op->request_fingerprint !== $digest || ($op->funding['claim'] ?? null) !== $paid)) {
                    $op->update(['state' => 'held', 'safe_code' => 'claim_authority_invalid']);
                    AcquisitionResult::schedule($op, null, null);
                    return null;
                }
                $this->startWorker($op, $prior, $digest, $paid, $now, ContactProfileHold::savedForRecovery($op, true) !== null, $window);
            }
            return [$op, $domain, $binding];
        });
    }

    private function recoverUnsent(Operation $op, Domain $domain, Collection $all, InvoiceFunding $funding,
        Server $server, Account $account, ?ProfileContactOperation $contact): ?array
    {
        $prior = AcquisitionAttempt::of((int) $op->id, $op->action, true);
        $continuation = AcquisitionResult::continuation($op, true);
        if (!$prior || AcquisitionAttempt::conflicts($op) || $prior->sealed()) { return null; }

        $failure = null;
        try {
            AcquisitionDispatch::requireNoSendEvidence($op);
            [$binding, $paid] = $this->authority->validate($op, $domain, $all, $funding, $server, $account);
            ContactSetup::requireReady($op);
            $digest = self::digest($op, $domain);
            if ($op->request_fingerprint !== $digest || ($op->funding['claim'] ?? null) !== $paid) {
                throw new \DomainException('Recovered acquisition authority changed.');
            }
        } catch (\DomainException $failure) {}
        $now = $this->clock->now();
        if (!OperationAttempts::replaceable($prior->facts, $now, lock: true)
            || AcquisitionAuthority::fundsCutoff($op, $continuation, $now, $prior->facts) !== AcquisitionAuthority::FUNDS_PROCEED) {
            return null;
        }
        if (ExecutionWindow::pastTransferCutoff($op, $now)) {
            $this->holdRecovered($op, $prior, 'transfer_setup_review_required', $now);
            return null;
        }
        if ($failure !== null) {
            if (AcquisitionRetryProof::expiredClaim($op, $now)) {
                $reason = $failure instanceof RuntimeIneligible && $failure->reason === RuntimeReason::ProcessingOff
                    ? 'processing' : 'claim_authority_invalid';
                $this->holdRecovered($op, $prior, $reason, $now);
            }
            return null;
        }

        $window = $continuation->window;
        if ($window === null) {
            $this->holdRecovered($op, $prior, 'claim_authority_invalid', $now);
            return null;
        }
        $fits = $window->fits($now);
        $start = fn () => $this->startWorker($op, $prior, $digest, $paid, $now, $contact !== null, $fits ? $window : null);
        if ($fits) {
            $start();
        } else {
            $admission = $contact === null ? AcquisitionAdmission::WorkerRecovery : AcquisitionAdmission::SavedContactRecovery;
            if (!AcquisitionResult::admit($op, $admission, $continuation->revision, $prior->facts->token, $now, $start,
                expectedContact: $contact === null ? null : (int) $contact->id)) {
                $this->holdRecovered($op, $prior, $window->blockedReason($now), $now);
                return null;
            }
        }
        return [$op, $domain, $binding];
    }

    private function holdRecovered(Operation $op, AcquisitionAttempt $prior, string $reason, \DateTimeImmutable $now): void
    {
        if (AcquisitionAuthority::retirePreSeal($op, $prior->facts, $reason, $now)) {
            self::recordHold($op, $now);
        }
    }

    private function expireWindow(Operation $op, AcquisitionWindow $window, \DateTimeImmutable $now): void
    {
        $reason = $window->blockedReason($now);
        $op->update(['state' => 'held', 'safe_code' => $reason]);
        if ($reason === 'write_rate_limited' && BalanceRetries::active($op)) {
            AcquisitionResult::schedule($op, AcquisitionLane::BalanceWait,
                $now->getTimestamp() + (BalanceRetry::from($op)?->delay() ?? 900), preserveShortfall: true);
            return;
        }
        if ($reason === 'write_rate_limited') {
            BalanceRetries::rateHeld($op, $now);
            if (BalanceRetries::pending($op)) { return; }
        }
        self::recordHold($op, $now);
    }

    /** Record the outcome once the operation and attempt reflect the new hold. Orders changes only its display. */
    public static function recordHold(Operation $op, \DateTimeImmutable $now, ?Money $cost = null, bool $shortfall = false): void
    {
        if ($op->safe_code === 'processing') { return; }
        if ($op->safe_code === 'registrar_price_increased' && PriceHoldRetries::schedule($op, $now)
            || ContactStepRetries::schedule($op, $now)
            || BalanceRetries::held($op, $now, $cost, $shortfall)) { return; }
        if ($shortfall) {
            $start = AcquisitionResult::continuation($op, true)?->shortfallAt ?? $now;
            AcquisitionResult::schedule($op, AcquisitionLane::FundsWait,
                $start->modify('+'.FundsWaits::WAIT_DAYS.' days')->getTimestamp(), $now);
            return;
        }
        AcquisitionResult::schedule($op, null, null);
    }

    private function retireExpiredWorker(Operation $route): void
    {
        DB::transaction(function () use ($route): void {
            $domain = Domain::whereKey($route->domain_id)->lockForUpdate()->firstOrFail();
            if ($domain->adoptionPending()) { return; }
            $op = Operation::where('domain_id', $domain->id)->orderBy('id')->lockForUpdate()->get()->firstWhere('id', $route->id);
            if ($op?->state === 'running') { $this->authority->expire($op); }
        });
    }

    private function startWorker(Operation $op, ?AcquisitionAttempt $prior, string $digest, array $paid,
        \DateTimeImmutable $now, bool $recheckContact = false, ?AcquisitionWindow $window = null): AttemptToken
    {
        $system = BalanceRetry::from($op);
        $policy = $op->policy;
        $lease = $now->modify('+'.ExecuteOperation::LEASE_SECONDS.' seconds');
        $start = $now;
        $origin = ['digest' => $digest, 'funding' => $paid];
        if ($prior?->hasOriginalClaim()) {
            $start = $prior->facts->claimedAt ?? throw new \DomainException('Original acquisition start unavailable.');
            $origin = ['origin_attempt_id' => $prior->originId()];
        }
        if ($recheckContact) { $origin['contact_recheck'] = true; }
        if ($window !== null) { $origin['admission_id'] = $window->authorityId; }
        $token = $this->attempts->claim((int) $op->id, AcquisitionAttempt::key($op->action), $lease, $origin, $start);
        if (!$token instanceof AttemptToken) { throw new \DomainException('Acquisition claim changed.'); }
        $op->reloadAcquisitionAttempt();
        if ($system && $system->record['claim_key'] === null) { $policy['balance_retry'] = $system->claimed($op)->record; }
        $op->acquisitionWorker = $token;
        $op->update(['state' => 'running', 'safe_code' => null, 'policy' => $policy]);
        return $token;
    }

    /** Post-send bookkeeping needs only domain and operation locks. An open pre-seal wait also takes financial locks. */
    public function finish(Operation $claimed, ?DriverResult $result = null, ?string $holdReason = null, ?Money $holdCost = null, bool $locallyUnsent = false, ?SendFailure $failure = null): Operation
    {
        self::outside();
        $checkCutoff = AcquisitionRetryProof::unsealed($claimed) && AcquisitionResult::continuation($claimed)?->shortfallAt !== null;
        $complete = function (Operation $op, Domain $domain) use ($claimed, $result, $holdReason, $holdCost, $locallyUnsent, $failure, $checkCutoff): Operation {
            $sealed = !AcquisitionRetryProof::unsealed($op);
            $late = $sealed && $op->state === 'uncertain' && $op->safe_code === 'stale_claim'
                && AcquisitionAttempt::sameWorker($op, $claimed);
            $worker = AcquisitionAttempt::lockedClaim($op, $claimed);
            if ($worker === null && !$late) { return $op; }
            $continuation = $checkCutoff && !$sealed ? AcquisitionResult::continuation($op, true) : null;
            $facts = $sealed ? OperationAttempts::attempt($claimed->acquisitionWorker, true) : null;
            $now = $this->clock->now();
            if ($continuation && AcquisitionAuthority::fundsCutoff($op, $continuation, $now, $worker) !== AcquisitionAuthority::FUNDS_PROCEED) {
                return $op;
            }
            if (!$sealed && AcquisitionRetryProof::expiredClaim($op, $now)) { return $op; }
            $expired = $late || !$op->lease_expires_at || $op->lease_expires_at <= $now;
            $unsent = $facts !== null && !array_key_exists('execution', $op->attempt) && $locallyUnsent
                && ($op->registrar_key === 'namesilo' || $op->registrar_key === 'enom' && $facts->grantConsumedAt === null)
                && $result?->jsonSerialize() === ['disposition'=>'rejected','send_state'=>'definitely_unsent','reason'=>'unsent','discrepancies'=>[]];
            if ($sealed && !array_key_exists('execution', $op->attempt)) {
                $reply = self::replyFacts($result, $failure, $unsent);
                $outcome = $unsent ? WireOutcome::localRefusal('transport', $reply)
                    : ($result && $result->sendState !== SendState::DefinitelyUnsent
                        ? WireOutcome::reply($result->sendState, $result->disposition->value, $reply)
                        : WireOutcome::unknown($reply));
                if ($this->attempts->recordReply($claimed->acquisitionWorker, $outcome) !== Transition::Applied) { return $op; }
            } elseif (!$sealed && $this->attempts->releasePreSeal($claimed->acquisitionWorker) !== Transition::Applied) {
                return $op;
            }
            $op->reloadAcquisitionAttempt();
            // Ack/rejection still require action-specific correlation/completion in 21/22.
            $completion = !$expired && $sealed && $result
                ? $this->recordDecision($op, $domain, $claimed->acquisitionWorker, $result) : null;
            $state = $unsent || (!$sealed && !$expired) ? 'held' : 'uncertain';
            $reason = match (true) {
                $unsent => 'acquisition_definitely_unsent',
                $expired => 'stale_claim',
                $sealed => 'reconciliation_required',
                default => $holdReason ?? 'pre_send_interrupted',
            };
            $next = ['state' => $state, 'safe_code' => $reason, 'lease_expires_at' => null];
            $op->update(array_replace($next, $completion ?? []));
            if ($op->state === 'held') {
                self::recordHold($op, $now, $holdCost, !$sealed && $reason === 'registrar_balance_insufficient');
            } elseif (!in_array($op->state, ['succeeded', 'rejected', 'disposed'], true)) {
                AcquisitionResult::schedule($op, null, null);
            }
            return $op;
        };
        if ($checkCutoff) { return $this->locked((int) $claimed->id, $complete); }
        return DB::transaction(function () use ($claimed, $complete): Operation {
            $domain = Domain::whereKey($claimed->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $claimed->domain_id)->orderBy('id')->lockForUpdate()->get();
            $op = $all->firstWhere('id', $claimed->id);
            return $op ? $complete($op, $domain) : $claimed->fresh();
        });
    }

    private function recordDecision(Operation $op, Domain $domain, AttemptToken $token, DriverResult $result): ?array
    {
        $decision = RegistrationCompletion::record($op, $domain, $result);
        if (!$decision) { return null; }
        $facts = AcquisitionAttempt::retain($op->attempt, $decision['attempt']) ?? [];
        if ($this->attempts->recordAcquisitionDecision($token, $decision['state'], $facts) !== Transition::Applied) {
            throw new \DomainException('Acquisition reply was already decided.');
        }
        unset($decision['attempt']);
        $op->reloadAcquisitionAttempt();
        return $decision;
    }

    private static function replyFacts(?DriverResult $result, ?SendFailure $failure, bool $unsent): array
    {
        $facts = ['execution' => $result?->jsonSerialize()];
        if ($failure && !$result && !$unsent) { $facts['send_failure'] = $failure->facts(); }
        if ($result && !$unsent) {
            // Only bounded neutral facts cross the evidence boundary.
            $evidence = json_decode(json_encode($result->evidence(), JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
            $facts += ['evidence' => $evidence, 'charge' => $evidence['charge'], 'order_candidates' => $evidence['candidates']];
        }
        return $facts;
    }

    /** Retry only an exact live, unsealed local-capacity refusal; retain the original claim identity. */
    public function defer(Operation $claimed): Operation
    {
        if(BalanceRetries::active($claimed)){return $this->finish($claimed,holdReason:'write_rate_limited');}
        return $this->locked((int)$claimed->id,function($op, $domain)use($claimed):Operation {
            if(!AcquisitionAttempt::sameClaim($op,$claimed)||!$op->lease_expires_at||$op->lease_expires_at<=$this->clock->now()||!AcquisitionRetryProof::unsealed($op)){return $op;}
            $deferrals = count($op->attempt['capacity_deferrals'] ?? []);
            $op->setRelation('domain', $domain);
            $savedContact = ContactProfileHold::savedForRecovery($op, true) !== null;
            $release = $savedContact && $deferrals >= 6
                ? $this->attempts->releasePreSeal($claimed->acquisitionWorker)
                : $this->attempts->deferClaim($claimed->acquisitionWorker);
            if ($release !== Transition::Applied) { return $op; }
            $op->reloadAcquisitionAttempt();
            $now = $this->clock->now();
            $window = AcquisitionResult::window($op, true);
            $fits = $window?->fits($now) === true;
            $op->update(['state' => $fits && $deferrals < 5 ? 'queued' : 'held',
                'safe_code' => $window === null ? 'claim_authority_invalid' : ($fits ? 'write_rate_limited' : $window->blockedReason($now))]);
            if ($op->state === 'held') {
                if ($window && !$fits && BalanceRetries::active($op)) {
                    $this->expireWindow($op, $window, $now);
                } else {
                    if ($op->safe_code === 'write_rate_limited' && !$savedContact) { BalanceRetries::rateHeld($op, $now); }
                    if (!BalanceRetries::pending($op)) { self::recordHold($op, $now); }
                }
            }
            return $op;
        });
    }
    /** A TEST counter refusal proves this worker did not cross the provider boundary. */
    public function holdTestCallCap(Operation $claimed): Operation
    {
        return $this->locked((int)$claimed->id,function($op)use($claimed):Operation {
            $worker = AcquisitionAttempt::lockedClaim($op, $claimed);
            if ($worker === null || AcquisitionAttempt::key($op->action) === null) { return $op; }
            $attempt=$op->attempt??[];$sealed=($attempt['seal']??null)==='sealed';
            try { AcquisitionDispatch::requireNoSendEvidence($op); } catch (\DomainException) { return $op; }
            if($sealed) {
                if(($attempt['send_state']??null)!=='possibly_sent'||!isset($attempt['dispatch_attempted_at'],$attempt['authorized_cost'],$attempt[$op->action==='transfer'?'transfer_admission':'registration_admission'])) { return $op; }
                if(!EnomTestCalls::purchaseUnconsumed($op->account_fingerprint,(int)$op->id)) { return $op; }
            } elseif(!AcquisitionRetryProof::unsealed($op)||($attempt['send_state']??null)!=='definitely_unsent') { return $op; }
            $continuation = AcquisitionResult::continuation($op, true);
            $now = $this->clock->now();
            if (!$sealed && AcquisitionAuthority::fundsCutoff($op, $continuation, $now, $worker) !== AcquisitionAuthority::FUNDS_PROCEED) { return $op; }
            return $this->refuseTestCapacity($op, $worker->token, $now, $continuation->lane === AcquisitionLane::BalancePurchase);
        });
    }

    private function refuseTestCapacity(Operation $op, AttemptToken $token, \DateTimeImmutable $now, bool $balance): Operation
    {
        $attempt = $op->attempt;
        $sealed = $attempt['seal'] === 'sealed';
        if (!$balance && DB::table(OperationSchema::EVIDENCE)->where(['operation_id' => $op->id,
            'kind' => 'test_capacity', 'outcome' => 'fresh'])->count() >= 100) { return $op; }
        $proof = ['phase' => $sealed ? 'purchase' : 'preflight', 'refused_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'dispatch_attempted_at' => $sealed ? $attempt['dispatch_attempted_at'] : null, 'authorized_cost' => $sealed ? $attempt['authorized_cost'] : null];
        if ($this->attempts->refuseTestCapacity($token, $proof, $balance) !== Transition::Applied) { return $op; }
        $op->reloadAcquisitionAttempt();
        $reason = $op->action === 'transfer' ? 'enom_test_transfer_call_cap' : 'enom_test_call_cap';
        $op->update(['state' => 'held', 'safe_code' => $balance ? 'write_rate_limited' : $reason]);
        if ($balance) { BalanceRetries::held($op, $now); }
        else { AcquisitionResult::schedule($op, null, null); }
        return $op;
    }
    /** Disabled feature gates create no claim, seal or provider traffic. */
    public function holdUnclaimed(int $id, string $reason): Operation
    {
        self::outside(); $route = Operation::findOrFail($id);
        $checkCutoff = AcquisitionResult::continuation($route)?->shortfallAt !== null;
        $hold = function (Operation $op, Domain $held) use ($reason, $checkCutoff): Operation {
            if ($held->adoptionPending()) { return $op; }
            if ($op->action === 'transfer' && $op->state === 'awaiting_code' && !TransferInputs::validCode($op->payload['epp'] ?? null)) { return $op; }
            $continuation = $checkCutoff ? AcquisitionResult::continuation($op, true) : null;
            $prior = $checkCutoff && in_array($op->state, ['running', 'uncertain'], true)
                ? AcquisitionAttempt::of((int) $op->id, $op->action, true) : null;
            $now = $this->clock->now();
            $expired = AcquisitionRetryProof::expiredClaim($op, $now);
            if ($continuation && AcquisitionAuthority::fundsCutoff($op, $continuation, $now, $expired ? $prior?->facts : null)
                !== AcquisitionAuthority::FUNDS_PROCEED) { return $op; }
            if ($expired || AcquisitionRetryProof::interruptedHold($op)) {
                $prior ??= AcquisitionAttempt::of((int) $op->id, $op->action, true);
                if ($prior && !AcquisitionAttempt::conflicts($op)) {
                    $this->holdRecovered($op, $prior, ExecutionWindow::pastTransferCutoff($op, $now) ? 'transfer_setup_review_required' : $reason, $now);
                }
            } elseif ($op->state === 'running') {
                $this->authority->expire($op, $now);
            } elseif (in_array($op->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) {
                $window = ($continuation ?? AcquisitionResult::continuation($op, true))?->window;
                $prior = $op->acquisitionAttemptFacts();
                if (AcquisitionResult::unclaimed($op) && AcquisitionRetryProof::virgin($op)
                    || $window && $prior && AcquisitionResult::continues($op, $window, $prior)) {
                    if (in_array($reason, AcquisitionRetryProof::TEST_CAPACITY_CODES, true)) { $reason = 'pre_send_interrupted'; }
                    if ($reason !== 'processing' && $window && !$window->fits($now)) {
                        $this->expireWindow($op, $window, $now);
                    } else {
                        $op->update(['state' => 'held', 'safe_code' => $reason]);
                        self::recordHold($op, $now);
                    }
                }
            }
            return $op;
        };
        if ($checkCutoff) { return $this->locked($id, $hold); }
        return DB::transaction(function () use ($route, $hold): Operation {
            $held = Domain::whereKey($route->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $route->domain_id)->orderBy('id')->lockForUpdate()->get();
            return $hold($all->firstWhere('id', $route->id), $held);
        });
    }

    public function requireProcessing(AccountBinding $binding): void { $this->authority->requireProcessing($binding); }

    /** Successful preflight can end a shortfall even when a later local gate stops the send. */
    public function confirmFunds(Operation $claimed, DispatchReadiness $ready): bool
    {
        if (AcquisitionResult::continuation($claimed)?->shortfallAt === null) { return true; }
        return $this->locked((int) $claimed->id, function ($op, $domain, $all, $funding, $server, $account) use ($claimed, $ready): bool {
            $attempt = AcquisitionAttempt::lockedClaim($op, $claimed);
            if ($attempt === null || !AcquisitionRetryProof::unsealed($op)) { return false; }
            $continuation = AcquisitionResult::continuation($op, true);
            $binding = AccountBindings::binding($account);
            $now = $this->clock->now();
            if (AcquisitionAuthority::fundsCutoff($op, $continuation, $now, $attempt) !== AcquisitionAuthority::FUNDS_PROCEED
                || !$op->lease_expires_at || $op->lease_expires_at <= $now) { return false; }
            $ready->requireCurrent($binding, self::query($op, $domain), self::payloadDigest($op), $now);
            AcquisitionResult::clearShortfall($op, $continuation);
            return true;
        });
    }

    public function locked(int $id, \Closure $callback, ?int $staffId = null): mixed
    { return $this->authority->locked($id, $callback, $staffId); }

    public function seal(Operation $claimed, DispatchReadiness $ready, \DateTimeImmutable $entry, ?SealPreflight $preflight = null,
        ?WriteGate $writer = null, ?RequestContext $wire = null): ?Operation
    { return $this->dispatch->seal($claimed, $ready, $entry, $preflight, $writer, $wire); }

    public function profileAuthority(Operation $claimed): array
    { return $this->dispatch->profileAuthority($claimed); }

    public function withProfileAuthority(Operation $claimed, \Closure $work): mixed
    { return $this->dispatch->withProfileAuthority($claimed, $work); }

    public function verifySend(Operation $sealed, AdmittedOperation $admitted, RequestContext $context, DispatchReadiness $ready,
        ?SealPreflight $preflight, WriteAcceptance $acceptance): array
    { return $this->dispatch->verifySend($sealed, $admitted, $context, $ready, $preflight, $acceptance); }

    public const TEST_CALL_HOLDS = AcquisitionRetryProof::TEST_CALL_HOLDS;
    public static function ordersOffHold(Operation $op): bool { return AcquisitionRetryProof::ordersOffHold($op); }
    public static function ordersOffReturns(Operation $op, ?\Closure $facts = null): bool { return AcquisitionRetryProof::ordersOffReturns($op, $facts); }
    public static function balanceAdmissionReason(Operation $op, ?array $facts = null): ?string { return AcquisitionRetryProof::balanceAdmissionReason($op, $facts); }
    public static function balanceRefusalRetryable(Operation $op): bool { return AcquisitionRetryProof::balanceRefusalRetryable($op); }
    public static function accountGuardRetryable(Operation $op): bool { return AcquisitionRetryProof::accountGuardRetryable($op); }
    public static function testCallRefusalRetryable(Operation $op): bool { return AcquisitionRetryProof::testCallRefusalRetryable($op); }
    public static function testCallRefusalTerminal(Operation $op): bool { return AcquisitionRetryProof::testCallRefusalTerminal($op); }
    public static function testCallRefusalUnsent(Operation $op): bool { return AcquisitionRetryProof::testCallRefusalUnsent($op); }
    public static function localDeferrals(array $attempt): bool { return AcquisitionRetryProof::localDeferrals($attempt); }
    public static function payloadDigest(Operation $op): string { return AcquisitionAuthority::payloadDigest($op); }
    public static function query(Operation $op, Domain $domain): Query { return AcquisitionAuthority::query($op, $domain); }
    public static function digest(Operation $op, Domain $domain): string { return AcquisitionAuthority::digest($op, $domain); }
    public static function outside(): void { AcquisitionAuthority::outside(); }
}
