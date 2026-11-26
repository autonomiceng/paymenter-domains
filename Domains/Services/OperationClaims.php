<?php
// Claim/seal semantics adapted from Enom 444f31c Services/DomainOperations.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, SealPreflight, WriteGate};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AdmittedOperation, BalanceRetry, DispatchReadiness, DriverResult,
    Money, Query, RequestContext, SendFailure, SendState};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AcquisitionAuthority, AcquisitionDispatch,
    AcquisitionRetryProof, AttemptToken, OperationWriter, Transition, WireOutcome};

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

    /** The only automatic readmission: current money/authority, strict negative proof, and a due queue row under one lock scope. */
    public function readmitBalance(int $id): bool
    {
        return $this->locked($id,function($op,$domain,$all,$funding,$server,$account):bool {
            if(FundsWaits::elapsed($op,$this->clock->now())||!BalanceRetries::pending($op)||!$account->processing_enabled){return false;}
            $q=DB::table('domains_runtime_operations')->where('operation_id',$op->id);
            $row=(clone $q)->lockForUpdate()->first();$now=$this->clock->now();
            if($row&&(int)$row->due_at>$now->getTimestamp()){return false;}
            $facts=$funding->initialFacts($domain)+['account'=>$account,'operations'=>$all];
            $reason=AcquisitionRetryProof::balanceAdmissionReason($op,$facts);
            if($reason===null){try{$this->authority->requireProcessingLocked($server,$account);}catch(\Throwable){$reason='balance_retry_authority_changed';}}
            if($reason!==null){
                $review=in_array($reason,BalanceRetries::REVIEW_CODES,true);
                if($review&&AcquisitionRetryProof::virgin($op)){
                    // No original claim exists to replace. Recheck repaired native facts on backoff, without provider reads.
                    $q->updateOrInsert(['operation_id'=>$op->id],['due_at'=>$now->getTimestamp()+(BalanceRetry::from($op)?->delay()??900)]);return false;
                }
                // Frozen purchase proof cannot be replaced by a changed invoice, owner or desired state.
                $policy=$op->policy;
                if($review&&BalanceRetry::from($op)===null){$policy['balance_retry']=BalanceRetry::held($op,$now,null)->record;}
                $op->update(['safe_code'=>$reason,'policy'=>$policy]);
                // Recheck local proof on backoff. Unresolved send evidence never enters this retry path.
                if($review){$q->updateOrInsert(['operation_id'=>$op->id],['due_at'=>$now->getTimestamp()+BalanceRetry::from($op)->delay()]);}
                else{$q->delete();}
                if(\Illuminate\Support\Facades\Schema::hasTable('domains_obligation_alerts')){ObligationAlerts::record($domain,'operation_review',$now->getTimestamp(),(int)$op->id);}return false;
            }
            if(!$row){BalanceRetries::held($op,$now);return false;}
            $retry=BalanceRetry::held($op,$now,null)->admitted($op,$now);
            $q->update(['due_at'=>$now->getTimestamp()+$retry->delay()]);
            $op->update(['state'=>'queued','safe_code'=>null,'policy'=>[...$op->policy,'balance_retry'=>$retry->record]]);
            return true;
        });
    }

    public function readmitOrdersOff(int $id): bool
    {
        return $this->locked($id,function($op,$domain,$all,$funding,$server,$account):bool {
            if(!$account->processing_enabled||!AcquisitionRetryProof::ordersOffReturns($op,fn()=>$funding->initialFacts($domain)+['account'=>$account,'operations'=>$all])){return false;}
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
        if ($route->state === 'running') {
            DB::transaction(function () use ($route): void {
                $held = Domain::whereKey($route->domain_id)->lockForUpdate()->firstOrFail();
                if ($held->adoptionPending()) { return; }
                $all = Operation::where('domain_id', $route->domain_id)->orderBy('id')->lockForUpdate()->get();
                $current = $all->firstWhere('id', $route->id);
                if ($current?->state === 'running' && (!$current->lease_expires_at || $current->lease_expires_at <= $this->clock->now())) {
                    $current->update(['state' => 'uncertain', 'safe_code' => 'stale_claim', 'lease_expires_at' => null]);
                }
            });
            return null;
        }
        if (!in_array($route->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) { return null; }
        return $this->locked($id, function ($op, $domain, $all, $funding, $server, $account) use ($startedAt): ?array {
            if ($op->state === 'running') {
                if (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now()) {
                    $op->update(['state' => 'uncertain', 'safe_code' => 'stale_claim', 'lease_expires_at' => null]);
                }
                return null; // Expiry is reconciliation work, even without a seal; never reclaim.
            }
            if (!in_array($op->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) { return null; }
            // Discovery may not have visited this order before dispatch starts.
            ObservationWork::discardUnsent($domain,$this->clock->now(),$all);
            if ($op->action === 'transfer' && !TransferInputs::validCode($op->payload['epp'] ?? null)) { return null; }
            if(FundsWaits::elapsed($op,$this->clock->now())){return null;}
            $capacityRetry=AcquisitionRetryProof::capacityRetry($op);$priceRetry=(AcquisitionRetryProof::testCallRetry($op)&&ExecutionWindow::priceReleased($op))||ComplimentaryCosts::readmitted($op);
            $testCallRetry=AcquisitionRetryProof::testCallRetry($op)&&!$priceRetry&&!BalanceRetry::from($op)?->window;$balanceRetry=AcquisitionRetryProof::balanceRefusalRetryable($op);
            $contactRetry=AcquisitionRetryProof::contactRetry($op);
            // A cap refusal starts fresh authority; validated balance, price and contact step retries retain the original unsent claim.
            $claimRetry=($capacityRetry||$balanceRetry||$priceRetry||$contactRetry)&&!$testCallRetry&&isset($op->attempt['claim_key']);
            if (!AcquisitionRetryProof::virgin($op)&&!$capacityRetry&&!$testCallRetry&&!$balanceRetry&&!$priceRetry&&!$contactRetry) {
                $op->update(['state' => 'uncertain', 'safe_code' => 'prior_attempt', 'lease_expires_at' => null]); return null;
            }
            try { [$binding, $paid] = $this->authority->validate($op, $domain, $all, $funding, $server, $account); }
            catch (RuntimeIneligible $error) {
                $op->update(['state'=>'held','safe_code'=>$error->reason===RuntimeReason::ProcessingOff?'processing':'claim_authority_invalid']);return null;
            } catch (\Throwable) { $op->update(['state' => 'held', 'safe_code' => 'claim_authority_invalid']); return null; }
            try { ContactSetup::requireReady($op); }
            catch (\DomainException) {
                // The contact service owns its transaction and rechecks payment/identity after commit.
                DB::afterCommit(static fn () => ContactRecovery::hold((int) $op->id));
                return null;
            }
            // Anchor the TEST preflight lease window at worker entry, before the first provider read.
            // Model datetime columns are persisted in the active application timezone. Keep the
            // stored claim in that zone; comparisons below still compare absolute instants.
            $now = $this->clock->now();$claimStartedAt=($startedAt??$now)->setTimezone($now->getTimezone());
            if($claimStartedAt>$now||$claimStartedAt<$now->modify('-'.ExecuteOperation::EXECUTION_SECONDS.' seconds')) { $op->update(['state'=>'held','safe_code'=>'claim_authority_invalid']);return null; }
            $digest = self::digest($op, $domain);
            try{$window=ExecutionWindow::start($op,$windowReason);}
            catch(\Throwable){$op->update(['state'=>'held','safe_code'=>'claim_authority_invalid']);return null;}
            if(($claimRetry||$balanceRetry)&&(!$window||$window>$now||$now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds')>$window->setTimezone(new \DateTimeZone('UTC'))->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds'))){
                // Orders off waits only for Orders, and validate() just proved they are on. A window that lapsed while they were off
                // again opens anew here, as the tick's release would, without spending a try. Like that release, a transfer
                // past its deadline is not reopened and holds instead.
                $renewed=$contactRetry&&$windowReason==='processing'&&$window&&$window<=$now&&ContactProfileHold::releaseBlocked($op)===null
                    &&($op->action!=='transfer'||TransferInputs::beforeDeadline($op,$now))
                    ?ContactStepRetries::released($op,'processing',$now):null;
                if($renewed===null){$op->update(['state'=>'held','safe_code'=>$window&&$op->started_at&&$window>$op->started_at?$windowReason:'write_rate_limited']);BalanceRetries::rateHeld($op,$now);return null;}
                $op->policy=$renewed;$window=ExecutionWindow::start($op,$windowReason);
            }
            if(($claimRetry||$balanceRetry)&&isset($op->attempt['claim_key'])&&($op->request_fingerprint!==$digest||($op->funding['claim']??null)!==$paid)){$op->update(['state'=>'held','safe_code'=>'claim_authority_invalid']);return null;}
            if (!$this->startWorker($op, $claimRetry, $claimStartedAt, $digest, $paid, $now)) { return null; }
            return [$op, $domain, $binding];
        });
    }

    private function startWorker(Operation $op, bool $retry, \DateTimeImmutable $start, string $digest,
        array $paid, \DateTimeImmutable $now): bool
    {
        $system = BalanceRetry::from($op);
        $lease = $now->modify('+'.ExecuteOperation::LEASE_SECONDS.' seconds');
        $prior = AcquisitionAttempt::of((int) $op->id, $op->action, true);
        $origin = ['started_at' => $start->format(DATE_ATOM), 'digest' => $digest, 'funding' => $paid];
        if ($retry && $prior) {
            $origin = ['origin_attempt_id' => $prior->originId()];
        } elseif (!$prior && $op->attempt) {
            // Historic unsealed retries passed the same admission checks above. Import their audit
            // with the first new worker rather than starting another legacy claim.
            $origin['history'] = array_intersect_key($op->attempt, ['test_call_refusals' => true, 'capacity_deferrals' => true]);
            if ($retry) {
                $origin['identity'] = $op->attempt['claim_key'];
                $origin['started_at'] = $op->started_at->format(DATE_ATOM);
            }
            self::retireLegacyClaim($op);
        }
        $token = $this->attempts->claim((int) $op->id, AcquisitionAttempt::key($op->action), $lease, $origin);
        if (!$token instanceof AttemptToken) { throw new \DomainException('Acquisition claim changed.'); }
        $op->reloadAcquisitionAttempt();
        $policy = $op->policy;
        if ($system && $system->record['claim_key'] === null) { $policy['balance_retry'] = $system->claimed($op)->record; }
        $op->acquisitionWorker = $token;
        $op->update(['state' => 'running', 'safe_code' => null, 'policy' => $policy]);
        return true;
    }

    /** Called only for an unsealed legacy retry after current funding, identity and negative evidence were checked. */
    private static function retireLegacyClaim(Operation $op): void
    {
        $funding = array_diff_key($op->funding ?? [], ['claim' => true]);
        $op->fill(['funding' => $funding ?: null]);
        DB::table('domains_operations')->where('id', $op->id)->update(['attempt' => null,
            'started_at' => null, 'request_fingerprint' => null, 'lease_expires_at' => null, 'funding' => ($op->getAttributes()['funding'] ?? null)]);
        $op->refresh();
    }

    /** Post-read/write bookkeeping needs only domain -> ordered operations, never financial authority again. */
    public function finish(Operation $claimed, ?DriverResult $result = null, ?string $holdReason = null, ?Money $holdCost = null, bool $locallyUnsent = false, ?SendFailure $failure = null): Operation
    {
        self::outside();
        return DB::transaction(function () use ($claimed, $result, $holdReason, $holdCost, $locallyUnsent, $failure): Operation {
            $domain = Domain::whereKey($claimed->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $claimed->domain_id)->orderBy('id')->lockForUpdate()->get();
            $op = $all->firstWhere('id', $claimed->id);
            if (!$op) { return $claimed->fresh(); }
            $sealed = !AcquisitionRetryProof::unsealed($op);
            $late = AcquisitionAttempt::sameWorker($op, $claimed) && $sealed
                && $op->state === 'uncertain' && $op->safe_code === 'stale_claim';
            if (!AcquisitionAttempt::sameClaim($op, $claimed) && !$late) { return $op; }
            $expired = $late || !$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now();
            $unsent=$sealed&&!array_key_exists('execution',$op->attempt)&&$locallyUnsent&&$op->registrar_key==='namesilo'&&in_array($op->action,['register','transfer'],true)
                &&$result?->jsonSerialize()===['disposition'=>'rejected','send_state'=>'definitely_unsent','reason'=>'unsent','discrepancies'=>[]];
            if ($sealed && !array_key_exists('execution', $op->attempt)) {
                $reply = self::replyFacts($result, $failure, $unsent);
                $outcome = $unsent ? WireOutcome::localRefusal('transport', $reply)
                    : ($result && $result->sendState !== SendState::DefinitelyUnsent
                        ? WireOutcome::reply($result->sendState, $result->disposition->value, $reply)
                        : WireOutcome::unknown($reply));
                if ($this->attempts->recordReply($claimed->acquisitionWorker, $outcome) !== Transition::Applied) { return $op; }
            } elseif (!$sealed) {
                $this->attempts->releasePreSeal($claimed->acquisitionWorker);
            }
            $op->reloadAcquisitionAttempt();
            // Ack/rejection still require action-specific correlation/completion in 21/22. No refund/release here.
            $completion = !$expired && $sealed && $result ? $this->recordDecision($op, $domain, $claimed->acquisitionWorker, $result) : null;
            $op->update(array_replace(['state' => $unsent?'held':(($sealed || $expired) ? 'uncertain' : 'held'),
                'safe_code' => $unsent?'acquisition_definitely_unsent':($expired ? 'stale_claim' : ($sealed ? 'reconciliation_required' : ($holdReason ?? 'pre_send_interrupted'))), 'lease_expires_at' => null], $completion ?? []));
            if(BalanceRetries::candidate($op)){BalanceRetries::held($op,$this->clock->now(),$holdCost);}
            return $op;
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
        if(BalanceRetry::from($claimed)?->window){return $this->finish($claimed,holdReason:'write_rate_limited');}
        return $this->locked((int)$claimed->id,function($op)use($claimed):Operation {
            if(!AcquisitionAttempt::sameClaim($op,$claimed)||!$op->lease_expires_at||$op->lease_expires_at<=$this->clock->now()||!AcquisitionRetryProof::unsealed($op)){return $op;}
            $deferrals = count($op->attempt['capacity_deferrals'] ?? []);
            if ($this->attempts->deferClaim($claimed->acquisitionWorker) !== Transition::Applied) { return $op; }
            $op->reloadAcquisitionAttempt();
            $op->update(['state' => $deferrals < 5 ? 'queued' : 'held', 'safe_code' => 'write_rate_limited']);
            if($op->state==='held'){BalanceRetries::rateHeld($op,$this->clock->now());}return $op;
        });
    }
    /** A TEST counter refusal proves this worker did not cross the provider boundary. */
    public function holdTestCallCap(Operation $claimed): Operation
    {
        return $this->locked((int)$claimed->id,function($op)use($claimed):Operation {
            if(!AcquisitionAttempt::sameClaim($op,$claimed)||!in_array($op->action,['register','transfer'],true)||$op->state!=='running') { return $op; }
            $attempt=$op->attempt??[];$sealed=($attempt['seal']??null)==='sealed';
            try { self::requireNoSendEvidence($op); } catch (\Throwable) { return $op; }
            if($sealed) {
                if(($attempt['send_state']??null)!=='possibly_sent'||!isset($attempt['dispatch_attempted_at'],$attempt['authorized_cost'],$attempt[$op->action==='transfer'?'transfer_admission':'registration_admission'])) { return $op; }
                if(!EnomTestCalls::purchaseUnconsumed($op->account_fingerprint,(int)$op->id)) { return $op; }
            } elseif(!AcquisitionRetryProof::unsealed($op)||($attempt['send_state']??null)!=='definitely_unsent') { return $op; }
            return $this->refuseTestCapacity($op, $claimed->acquisitionWorker, BalanceRetry::from($op));
        });
    }

    private function refuseTestCapacity(Operation $op, AttemptToken $token, ?BalanceRetry $system): Operation
    {
        $attempt = $op->attempt;
        $sealed = $attempt['seal'] === 'sealed';
        $now = $this->clock->now();
        $history = $attempt['test_call_refusals'] ?? [];
        $balance = $system?->window !== null;
        if (!$balance && (!AcquisitionRetryProof::testCallHistory($history) || count($history) >= 100)) { return $op; }
        $proof = ['phase' => $sealed ? 'purchase' : 'preflight', 'refused_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'dispatch_attempted_at' => $sealed ? $attempt['dispatch_attempted_at'] : null, 'authorized_cost' => $sealed ? $attempt['authorized_cost'] : null];
        if ($this->attempts->refuseTestCapacity($token, $proof, $balance) !== Transition::Applied) { return $op; }
        $op->reloadAcquisitionAttempt();
        $reason = $op->action === 'transfer' ? 'enom_test_transfer_call_cap' : 'enom_test_call_cap';
        $op->update(['state' => 'held', 'safe_code' => $balance ? 'write_rate_limited' : $reason]);
        if ($balance) { BalanceRetries::held($op, $now); }
        return $op;
    }
    /** Disabled feature gates create no claim, seal or provider traffic. */
    public function holdUnclaimed(int $id, string $reason): Operation
    {
        self::outside(); $route = Operation::findOrFail($id);
        return DB::transaction(function () use ($route, $reason): Operation {
            $held = Domain::whereKey($route->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $route->domain_id)->orderBy('id')->lockForUpdate()->get();
            $op = $all->firstWhere('id', $route->id);
            if ($held->adoptionPending()) { return $op; }
            if ($op->action === 'transfer' && $op->state === 'awaiting_code' && !TransferInputs::validCode($op->payload['epp'] ?? null)) { return $op; }
            if ($op->state === 'running' && (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now())) {
                $op->update(['state' => 'uncertain', 'safe_code' => 'stale_claim', 'lease_expires_at' => null]);
            } elseif (in_array($op->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)
                && (AcquisitionRetryProof::virgin($op)||(in_array($reason,ExecutionWindow::BALANCE_HOLDS,true)&&AcquisitionRetryProof::balanceRefusalRetryable($op))||($reason==='namesilo_account_guard'&&$op->registrar_key==='namesilo'&&AcquisitionRetryProof::capacityRetry($op))||(in_array($reason,['enom_test_call_cap','enom_test_transfer_call_cap','write_acceptance_required','nameservers_required',
                    'registry_details_unsupported','processing','write_disabled','usd_balance_required','enom_test_transfer_usd_balance_required','enom_test_setup_required','enom_test_transfer_setup_required',...WriteHeld::UNSENT_REFUSALS],true)&&AcquisitionRetryProof::testCallRetry($op)))) {
                $op->update(['state' => 'held', 'safe_code' => $reason]);
            }
            return $op;
        });
    }

    public function requireProcessing(AccountBinding $binding): void { $this->authority->requireProcessing($binding); }

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
