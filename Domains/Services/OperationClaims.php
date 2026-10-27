<?php
// Claim/seal semantics adapted from Enom 444f31c Services/DomainOperations.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Server, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, SealPreflight, WriteGate};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AdmittedOperation, DispatchReadiness, DriverResult, Environment, Query, RequestContext};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};

/** All financial checks run twice: claim and final seal. No provider callback runs under these locks. */
final class OperationClaims
{
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock) {}

    public function requireProcessing(AccountBinding $binding): void { $this->bindings->requireProcessing($binding); }

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
            if ($op->action === 'transfer' && !TransferInputs::validCode($op->payload['epp'] ?? null)) { return null; }
            $capacityRetry=self::capacityRetry($op);$testCallRetry=self::testCallRetry($op);
            // A TEST history proves the prior send was refused. A staff readmission therefore starts a fresh
            // execution window, even when this operation already has an older unsent claim.
            $claimRetry=$capacityRetry&&!$testCallRetry;
            if (!self::virgin($op)&&!$capacityRetry&&!$testCallRetry) {
                $op->update(['state' => 'uncertain', 'safe_code' => 'prior_attempt', 'lease_expires_at' => null]); return null;
            }
            try { [$binding, $paid] = $this->validate($op, $domain, $all, $funding, $server, $account); }
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
            $digest = self::digest($op, $domain);$retry=$claimRetry;
            $window=self::executionStart($op);
            if($claimRetry&&(!$window||$window>$now||$now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds')>$window->setTimezone(new \DateTimeZone('UTC'))->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds'))){
                $op->update(['state'=>'held','safe_code'=>$window&&$op->started_at&&$window>$op->started_at?'namesilo_account_guard':'write_rate_limited']);return null;
            }
            if($claimRetry&&($op->request_fingerprint!==$digest||($op->funding['claim']??null)!==$paid)){$op->update(['state'=>'held','safe_code'=>'claim_authority_invalid']);return null;}
            $attempt=$claimRetry?$op->attempt:($testCallRetry
                ?array_replace(['test_call_refusals'=>$op->attempt['test_call_refusals']],array_intersect_key($op->attempt,['capacity_deferrals'=>true]),
                    ['claim_key'=>bin2hex(random_bytes(16)),'seal'=>'absent','send_state'=>'definitely_unsent'])
                :['claim_key'=>bin2hex(random_bytes(16)),'seal'=>'absent','send_state'=>'definitely_unsent']);
            $op->update(['state' => 'running', 'safe_code' => null, 'started_at' => $retry?$op->started_at:$claimStartedAt,
                'lease_expires_at' => $now->modify('+'.ExecuteOperation::LEASE_SECONDS.' seconds'), 'request_fingerprint' => $digest,
                'attempt' => $attempt,
                'funding' => [...($op->funding ?? []), 'claim' => $paid]]);
            return [$op, $domain, $binding];
        });
    }

    public function seal(Operation $claimed, DispatchReadiness $ready, \DateTimeImmutable $entry, ?SealPreflight $preflight = null, ?WriteGate $writer = null, ?RequestContext $wire = null): ?Operation
    {
        return $this->locked((int) $claimed->id, function ($op, $domain, $all, $funding, $server, $account) use ($claimed, $ready, $entry, $preflight, $writer, $wire): ?Operation {
            if (!self::sameClaim($op, $claimed)) { return null; }
            if ($this->expire($op) || !self::unsealed($op)) { return null; }
            try {
                self::requireSameEvidence($op, $claimed); self::requireNoSendEvidence($op);
                [$binding, $paid] = $this->validate($op, $domain, $all, $funding, $server, $account);
                ContactSetup::requireReady($op);
                $preflight?->requireAtSeal($op, $domain, $ready);
                $writer?->requireAtSeal($op, $domain, $ready);
                $wire?->requireReservedCapacity();$now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
                $ready->requireCurrent($binding, self::query($op, $domain), self::payloadDigest($op), $now);
                $window=self::executionStart($op)??throw new \DomainException('Missing execution window.');
                $windowEnd=$window->setTimezone(new \DateTimeZone('UTC'))->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds');
                if($op->started_at&&$window>$op->started_at&&$windowEnd<$now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds')){
                    throw new WriteHeld('namesilo_account_guard');
                }
                $lastSend = min($windowEnd,
                    $entry->setTimezone(new \DateTimeZone('UTC'))->modify('+'.(ExecuteOperation::EXECUTION_SECONDS - ExecuteOperation::FINISH_SECONDS).' seconds'));
                if ($ready->observedAt < $window || $op->request_fingerprint !== self::digest($op, $domain)
                    || !$op->lease_expires_at || $op->lease_expires_at < $now->modify('+'.(ExecuteOperation::REQUEST_SECONDS + ExecuteOperation::CLEANUP_SECONDS).' seconds')
                    || $lastSend < $now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds')
                    || $ready->validUntil < $now->modify('+'.ExecuteOperation::REQUEST_SECONDS.' seconds')) {
                    throw new \DomainException('Insufficient dispatch lease or changed request.');
                }
            } catch (ReadDeferred $error) {throw $error;} catch (\Throwable $error) {
                $reason=$error instanceof WriteHeld?$error->reason:'seal_authority_invalid';
                $op->update(['state' => 'held', 'safe_code' => $reason, 'lease_expires_at' => null]); return null;
            }
            $attempt = $op->attempt;
            $attempt['seal'] = 'sealed'; $attempt['dispatch_attempted_at'] = $now->format(DATE_ATOM);
            $attempt['send_state'] = 'possibly_sent';
            $attempt['authorized_cost'] = ['amount' => $ready->cost->amount, 'currency' => $ready->cost->currency];
            if ($ready->query->action === Action::Register) {
                $attempt['registration_admission'] = ['premium' => false, 'name' => $ready->query->name,
                    'years' => $ready->query->years, 'cost' => $attempt['authorized_cost']];
            }
            if ($ready->query->action === Action::Transfer) { $attempt['transfer_admission'] = $ready->transfer; }
            $op->update(['attempt' => $attempt, 'funding' => [...$op->funding, 'seal' => $paid]]);
            return $op;
        });
    }

    /** Post-read/write bookkeeping needs only domain -> ordered operations, never financial authority again. */
    public function finish(Operation $claimed, ?DriverResult $result = null, ?string $holdReason = null): Operation
    {
        self::outside();
        return DB::transaction(function () use ($claimed, $result, $holdReason): Operation {
            $domain = Domain::whereKey($claimed->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $claimed->domain_id)->orderBy('id')->lockForUpdate()->get();
            $op = $all->firstWhere('id', $claimed->id);
            if (!$op) { return $claimed->fresh(); }
            $sealed = !self::unsealed($op);
            $late = self::sameWorker($op, $claimed) && $sealed
                && $op->state === 'uncertain' && $op->safe_code === 'stale_claim';
            if (!self::sameClaim($op, $claimed) && !$late) { return $op; }
            $expired = $late || !$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now();
            $attempt = $op->attempt;
            if ($sealed && !array_key_exists('execution', $attempt)) {
                // Recorded null is intentional and distinct from absent pre-send execution.
                $attempt['execution'] = $result?->jsonSerialize();
                if ($result) {
                    // Evidence is the bounded neutral fact graph, never a provider response or contact payload.
                    $attempt['evidence'] = json_decode(json_encode($result->evidence(), JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
                    $attempt['charge'] = $attempt['evidence']['charge'];
                    $attempt['order_candidates'] = $attempt['evidence']['candidates'];
                }
            }
            // Ack/rejection still require action-specific correlation/completion in 21/22. No refund/release here.
            $op->attempt = $attempt;
            $completion = !$expired && $sealed && $result ? RegistrationCompletion::record($op, $domain, $result) : null;
            $op->update(array_replace(['attempt' => $attempt, 'state' => ($sealed || $expired) ? 'uncertain' : 'held',
                'safe_code' => $expired ? 'stale_claim' : ($sealed ? 'reconciliation_required' : ($holdReason ?? 'pre_send_interrupted')), 'lease_expires_at' => null], $completion ?? []));
            return $op;
        });
    }

    /** Retry only an exact live, unsealed local-capacity refusal; retain the original claim identity. */
    public function defer(Operation $claimed): Operation
    {
        return $this->locked((int)$claimed->id,function($op)use($claimed):Operation {
            if(!self::sameClaim($op,$claimed)||!$op->lease_expires_at||$op->lease_expires_at<=$this->clock->now()||!self::unsealed($op)){return $op;}
            $history=$op->attempt['capacity_deferrals']??[];$a=[...$op->attempt,'capacity_deferrals'=>[...$history,$this->clock->now()->format(DATE_ATOM)]];
            $op->update(['state'=>count($history)<5?'queued':'held','safe_code'=>'write_rate_limited','attempt'=>$a,'lease_expires_at'=>null]);return $op;
        });
    }
    /** A TEST counter refusal proves this worker did not cross the provider boundary. */
    public function holdTestCallCap(Operation $claimed): Operation
    {
        return $this->locked((int)$claimed->id,function($op)use($claimed):Operation {
            if(!self::sameClaim($op,$claimed)||!in_array($op->action,['register','transfer'],true)||$op->state!=='running') { return $op; }
            $attempt=$op->attempt??[];$sealed=($attempt['seal']??null)==='sealed';
            try { self::requireNoSendEvidence($op); } catch (\Throwable) { return $op; }
            if($sealed) {
                if(($attempt['send_state']??null)!=='possibly_sent'||!isset($attempt['dispatch_attempted_at'],$attempt['authorized_cost'],$attempt[$op->action==='transfer'?'transfer_admission':'registration_admission'])) { return $op; }
                if(!EnomTestCalls::purchaseUnconsumed($op->account_fingerprint,(int)$op->id)) { return $op; }
            } elseif(!self::unsealed($op)||($attempt['send_state']??null)!=='definitely_unsent') { return $op; }
            $history=$attempt['test_call_refusals']??[];
            if(!self::testCallHistory($history)) { return $op; }
            $event=['phase'=>$sealed?'purchase':'preflight','refused_at'=>$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
                'dispatch_attempted_at'=>$sealed?$attempt['dispatch_attempted_at']:null,'authorized_cost'=>$sealed?$attempt['authorized_cost']:null];
            if(count($history)>=100) { return $op; }
            $funding=$op->funding??[];unset($funding['claim'],$funding['seal']);
            $refusalAttempt=['test_call_refusals'=>[...$history,$event],...array_intersect_key($attempt,['capacity_deferrals'=>true])];
            $op->update(['attempt'=>$refusalAttempt,'funding'=>$funding,'state'=>'held','safe_code'=>$op->action==='transfer'?'enom_test_transfer_call_cap':'enom_test_call_cap',
                'lease_expires_at'=>null,'started_at'=>null,'request_fingerprint'=>null]);
            return $op;
        });
    }
    private static function capacityRetry(Operation $op): bool
    {
        $a=$op->attempt??[];$history=$a['test_call_refusals']??[];
        return self::unsealed($op)&&isset($a['claim_key'],$a['capacity_deferrals'])&&is_array($a['capacity_deferrals'])
            &&count($a['capacity_deferrals'])<=5&&(!array_key_exists('test_call_refusals',$a)||self::testCallHistory($history))
            &&!array_diff(array_keys($a),['claim_key','seal','send_state','capacity_deferrals','test_call_refusals']);
    }
    /** An audited staff release grants a bounded new window without changing original claim evidence. */
    private static function executionStart(Operation $op): ?\DateTimeImmutable
    {
        $start=$op->started_at;
        if(!$start||$op->registrar_key!=='namesilo'||!in_array($op->action,['register','transfer'],true)){return $start;}
        foreach($op->policy['staff_actions']??[] as $event){
            if(($event['action']??null)!=='release'||($event['phase']??null)!=='completed'
                ||($event['operation_key']??null)!==$op->operation_key||($event['generation']??null)!==$op->authority_generation
                ||($event['before']['state']??null)!=='held'||($event['before']['reason']??null)!=='namesilo_account_guard'
                ||($event['after']['state']??null)!=='queued'||!is_array($event['after']??null)
                ||!array_key_exists('reason',$event['after'])||$event['after']['reason']!==null||!is_string($event['at']??null)){continue;}
            try{$at=new \DateTimeImmutable($event['at']);if($at->format(DATE_ATOM)!==$event['at']){continue;}}
            catch(\Throwable){continue;}
            if($at>$start){$start=$at;}
        }
        return $start;
    }
    public static function accountGuardRetryable(Operation $op): bool
    {
        return $op->state==='held'&&$op->safe_code==='namesilo_account_guard'&&$op->registrar_key==='namesilo'
            &&in_array($op->action,['register','transfer'],true)&&$op->lease_expires_at===null&&$op->completed_at===null&&self::capacityRetry($op);
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
                && (self::virgin($op)||($reason==='namesilo_account_guard'&&$op->registrar_key==='namesilo'&&self::capacityRetry($op))||(in_array($reason,['enom_test_call_cap','enom_test_transfer_call_cap','write_acceptance_required','nameservers_required',
                    'registry_details_unsupported','processing','write_disabled','usd_balance_required','enom_test_transfer_usd_balance_required','enom_test_setup_required','enom_test_transfer_setup_required'],true)&&self::testCallRetry($op)))) {
                $op->update(['state' => 'held', 'safe_code' => $reason]);
            }
            return $op;
        });
    }
    /** Staff may release only this retained, proven-unsent TEST preflight hold. */
    public static function testCallRefusalRetryable(Operation $op): bool
    {
        return $op->state==='held'&&in_array($op->safe_code,['enom_test_call_cap','enom_test_transfer_call_cap','pre_send_interrupted','seal_authority_invalid','claim_authority_invalid',
                'nameservers_required','write_acceptance_required','write_rate_limited','registry_details_unsupported','processing','write_disabled',
                'usd_balance_required','enom_test_transfer_usd_balance_required','enom_test_setup_required','enom_test_transfer_setup_required'],true)
            &&in_array($op->action,['register','transfer'],true)&&$op->registrar_key==='enom'
            &&$op->lease_expires_at===null&&$op->completed_at===null
            &&$op->provider_order_id===null&&$op->confirmed_expires_at===null
            &&self::testCallRefusalUnsent($op)
            &&self::testCallRetry($op)
            &&self::testCallRetryFunding($op);
    }

    /** A retained claim is allowed only because the TEST proof below establishes it was unsealed. */
    private static function testCallRetryFunding(Operation $op): bool
    {
        $funding=$op->funding??[];unset($funding['claim']);$withoutClaim=clone $op;$withoutClaim->setAttribute('funding',$funding);
        return FinancialAdjustments::unattemptedFunding($withoutClaim);
    }

    /** Prior TEST cap refusals remain negative proof through a later unsealed retry or refund decision. */
    public static function testCallRefusalUnsent(Operation $op): bool
    {
        $a=$op->attempt??[];$f=$op->funding??[];$history=$a['test_call_refusals']??null;
        $holdCodes=['enom_test_call_cap','enom_test_transfer_call_cap','pre_send_interrupted','seal_authority_invalid','claim_authority_invalid','nameservers_required',
            'write_acceptance_required','write_rate_limited','registry_details_unsupported','processing','write_disabled','usd_balance_required','enom_test_transfer_usd_balance_required','enom_test_setup_required','enom_test_transfer_setup_required'];
        if(!in_array($op->action,['register','transfer'],true)||$op->registrar_key!=='enom'||!self::testCallHistory($history)||!$history||!self::unsealed($op)
            ||$op->lease_expires_at!==null||$op->provider_order_id!==null||$op->confirmed_expires_at!==null||$op->completed_at!==null
            ||array_key_exists('actual',$op->cost??[])||array_key_exists('execution',$op->cost??[])||array_key_exists('dispatch_attempted_at',$op->policy??[])
            ||array_diff(array_keys($f),['claim','seal','refund_authorization','refund','financial_adjustments'])||array_key_exists('seal',$f)) { return false; }
        $held=$op->state==='held'&&in_array($op->safe_code,$holdCodes,true);
        $queued=$op->state==='queued'&&in_array($op->safe_code,[null,'write_rate_limited'],true);
        $approved=$op->state==='rejected'&&$op->safe_code==='refund_approved';
        $terminal=$op->state==='rejected'&&(in_array($op->safe_code,$holdCodes,true)||$approved)
            &&($a['disposition']??null)==='rejected'&&($a['rejection_basis']??null)==='definitely_unsent'
            &&($a['terminal']??null)===['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation];
        if(!$held&&!$queued&&!$approved&&!$terminal) { return false; }
        if($approved) {
            $auth=$f['refund_authorization']??null;
            if(!is_array($auth)||array_keys($auth)!==['actor_id','reason','at','operation_key','generation']||!is_int($auth['actor_id']??null)
                ||$auth['actor_id']<1||!is_string($auth['reason']??null)||!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$auth['reason'])
                ||!is_string($auth['at']??null)||$auth['operation_key']!==$op->operation_key||$auth['generation']!==$op->authority_generation) { return false; }
        }
        $unclaimedKeys=['test_call_refusals','capacity_deferrals'];
        if($terminal){$unclaimedKeys=[...$unclaimedKeys,'seal','send_state','disposition','rejection_basis','terminal'];}
        if(!isset($a['claim_key'])) {
            $deferrals=$a['capacity_deferrals']??[];
            return !array_diff(array_keys($a),$unclaimedKeys)&&$op->started_at===null&&$op->request_fingerprint===null
                &&($terminal?(($a['seal']??null)==='absent'&&($a['send_state']??null)==='definitely_unsent'):(!array_key_exists('seal',$a)&&!array_key_exists('send_state',$a)))&&!array_key_exists('claim',$f)&&!array_key_exists('seal',$f)
                &&(!array_key_exists('capacity_deferrals',$a)||(is_array($deferrals)&&array_is_list($deferrals)&&count($deferrals)<=5
                    &&!array_filter($deferrals,static fn($at)=>!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$at))));
        }
        $allowed=['test_call_refusals','claim_key','seal','send_state','capacity_deferrals'];
        if($terminal){$allowed[]='disposition';$allowed[]='rejection_basis';$allowed[]='terminal';}
        $deferrals=$a['capacity_deferrals']??[];
        return isset($a['claim_key'],$a['seal'],$a['send_state'])&&is_string($a['claim_key'])&&preg_match('/^[a-f0-9]{32}$/D',$a['claim_key'])
            &&$a['seal']==='absent'&&$a['send_state']==='definitely_unsent'&&$op->started_at!==null
            &&is_string($op->request_fingerprint)&&preg_match('/^[a-f0-9]{64}$/D',$op->request_fingerprint)
            &&is_array($f['claim']??null)&&!array_diff(array_keys($a),$allowed)
            &&(!array_key_exists('capacity_deferrals',$a)||(is_array($deferrals)&&array_is_list($deferrals)&&count($deferrals)<=6
                &&!array_filter($deferrals,static fn($at)=>!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$at))));
    }

    /** Authorizes nonmonetary fresh profile creation for this live, funded, unsealed claim only. */
    public function profileAuthority(Operation $claimed): array
    {
        return $this->locked((int) $claimed->id, function ($op, $domain, $all, $funding, $server, $account) use ($claimed): array {
            [$binding] = $this->validate($op, $domain, $all, $funding, $server, $account);
            if (!self::sameEvidence($op, $claimed)) { throw new ProfileAuthorityMismatch('Operation evidence changed.'); }
            ContactSetup::requireReady($op);
            if (!self::sameClaim($op, $claimed) || !self::unsealed($op) || $op->lease_expires_at <= $this->clock->now()
                || self::payloadDigest($op) !== self::payloadDigest($claimed)) { throw new ProfileAuthorityMismatch('Profile claim unavailable.'); }
            return [$binding, (int) $domain->user_id];
        });
    }

    /** Bridge consumption: acquire current ordered financial locks, commit, then send outside locks. */
    public function verifySend(Operation $sealed, AdmittedOperation $admitted, RequestContext $context,
        DispatchReadiness $ready, ?SealPreflight $preflight, WriteAcceptance $acceptance): array
    {
        return $this->locked((int) $sealed->id, function ($op, $domain, $all, $funding, $server, $account) use ($sealed, $admitted, $context, $ready, $preflight, $acceptance): array {
            [$binding, $paid] = $this->validate($op, $domain, $all, $funding, $server, $account);
            $binding->requireMatch($admitted->binding); $binding->requireMatch($context->binding);
            ContactSetup::requireReady($op); $preflight?->requireAtSeal($op, $domain, $ready);
            $acceptance->requireCurrent($binding, self::query($op, $domain), $op, true);
            $ready->requireCurrent($binding, self::query($op, $domain), self::payloadDigest($op), $this->clock->now());
            self::requireSameEvidence($op, $sealed); self::requireNoSendEvidence($op);
            if (!self::sameClaim($op, $sealed) || ($op->attempt['seal'] ?? null) !== 'sealed'
                || ($op->attempt['send_state'] ?? null) !== 'possibly_sent' || array_key_exists('execution', $op->attempt)
                || $op->lease_expires_at <= $this->clock->now() || $this->clock->now() >= $context->deadline
                || $op->request_fingerprint !== self::digest($op, $domain) || $op->operation_key !== $admitted->operationKey
                || $op->attempt['claim_key'] !== $admitted->claimKey || $op->authority_generation !== $admitted->authorityGeneration
                || $admitted->fundingEvidenceKey !== 'operation:'.$op->id.':seal' || !self::query($op, $domain)->matches($admitted->query)
                || self::payloadDigest($op) !== $admitted->desiredStateDigest || ($op->funding['seal'] ?? null) !== $paid
                || ($op->attempt['authorized_cost'] ?? null) !== ['amount' => $admitted->authorizedCost->amount, 'currency' => $admitted->authorizedCost->currency]) {
                throw new \DomainException('Sealed dispatch authority changed.');
            }
            return $op->payload;
        });
    }

    private static function requireSameEvidence(Operation $current, Operation $snapshot): void
    {
        if (!self::sameEvidence($current, $snapshot)) { throw new \DomainException('Operation evidence changed.'); }
    }
    private static function sameEvidence(Operation $current, Operation $snapshot): bool
    {
        foreach (['attempt', 'funding', 'cost', 'policy'] as $field) {
            if ($current->$field !== $snapshot->$field) { return false; }
        }
        return true;
    }
    /** Independent presence guard: null charge/refund/candidate facts are still uncertainty. */
    private static function requireNoSendEvidence(Operation $op): void
    {
        if (array_diff(array_keys($op->attempt ?? []), ['claim_key', 'seal', 'send_state', 'capacity_deferrals', 'test_call_refusals', 'dispatch_attempted_at', 'authorized_cost', 'registration_admission', 'transfer_admission'])
            || array_key_exists('refund', $op->funding ?? []) || array_key_exists('actual', $op->cost ?? [])
            || array_key_exists('execution', $op->cost ?? []) || array_key_exists('dispatch_attempted_at', $op->policy ?? [])) {
            throw new \DomainException('Prior operation evidence blocks dispatch.');
        }
    }

    private function validate(Operation $op, Domain $domain, $all, InvoiceFunding $funding, Server $server, Account $account): array
    {
        $binding = new AccountBinding($account->registrar_key, (int) $account->server_id, Environment::from($account->environment), $account->stable_identity);
        if ($op->account_fingerprint !== $binding->fingerprint || $domain->account_fingerprint !== $binding->fingerprint
            || $op->registrar_key !== $binding->registrarKey || (int) $op->server_id !== $binding->serverId
            || $domain->registrar_key !== $binding->registrarKey || (int) $domain->server_id !== $binding->serverId
            || !in_array($op->action, ['register', 'transfer'], true) || $domain->status !== ($op->action === 'transfer' ? 'transfer_pending' : 'registration_pending') || !$domain->billing_managed
            || $domain->reservation_name !== $domain->domain || $domain->provider_domain_id !== null
            || $domain->provider_expires_at !== null || $domain->provider_order_id !== null || $op->provider_order_id !== null
            || $op->confirmed_expires_at !== null
            || $all->contains(static fn ($other) => $other->id !== $op->id && (!self::resolvedOther($other)
                || $other->account_fingerprint !== $op->account_fingerprint || $other->server_id !== $op->server_id
                || $other->authority_generation !== $op->authority_generation || $other->service_id !== $op->service_id))) {
            throw new \DomainException('Acquisition authority is unavailable.');
        }
        if ($op->action === 'transfer' && ($op->term !== 1 || !TransferInputs::validCode($op->payload['epp'] ?? null)
            || !TransferInputs::deadline($op, $this->clock->now()))) { throw new \DomainException('Transfer inputs incomplete.'); }
        $paid=$funding->requireInitial($op,$domain);
        $this->bindings->requireProcessingLocked($server,$account);
        $op->setRelation('domain', $domain); // Contact validation consumes the already locked holding.
        return [$binding,$paid];
    }

    private static function resolvedOther(Operation $op): bool
    {
        // A state label alone is never proof of a safe earlier outcome.
        return $op->state === 'rejected' && ($op->attempt['disposition'] ?? null) === 'rejected'
            && ($op->attempt['rejection_basis'] ?? null) === 'definitely_unsent'
            && ($op->attempt['send_state'] ?? null) === 'definitely_unsent' && self::unsealed($op)
            && ($op->attempt['charge'] ?? null) === null && ($op->attempt['order_candidates'] ?? []) === [];
    }

    /** Internal ordered authority scope shared by read-only reconciliation. */
    public function locked(int $id, \Closure $callback, ?int $staffId = null): mixed
    {
        self::outside();
        // Discovery is before the transaction, so it cannot establish an old repeatable-read view.
        $route = Operation::findOrFail($id); $holding = Domain::findOrFail($route->domain_id);
        return DB::transaction(function () use ($route, $holding, $callback, $staffId) {
            $users=User::whereKey(array_filter([$holding->user_id,$staffId]))->orderBy('id')->lockForUpdate()->get();
            if(!$users->firstWhere('id',$holding->user_id)){throw new \DomainException('Missing domain owner.');}
            $staff=$users->firstWhere('id',$staffId); if($staff){$staff->setRelation('role',$staff->role()->lockForUpdate()->first());}
            $funding = InvoiceFunding::lock((int) $route->invoice_id);
            $server = Server::whereKey($route->server_id)->lockForUpdate()->firstOrFail();
            $account = Account::where('server_id', $route->server_id)->lockForUpdate()->firstOrFail();
            // Lock credentials before domain/operation rows (same server mutex as rotation).
            $server->settings()->orderBy('id')->lockForUpdate()->get();
            $domain = Domain::whereKey($route->domain_id)->lockForUpdate()->firstOrFail();
            $all = Operation::where('domain_id', $domain->id)->orderBy('id')->lockForUpdate()->get();
            $op = $all->firstWhere('id', $route->id);
            if (!$op || $op->invoice_id !== $route->invoice_id || $op->server_id !== $route->server_id
                || $domain->user_id !== $holding->user_id || $op->domain_id !== $domain->id) {
                throw new \DomainException('Claim route changed.');
            }
            if ($domain->adoptionPending()) { throw new \DomainException('Registration verification required.'); }
            return $callback($op, $domain, $all, $funding, $server, $account, $staff);
        });
    }

    private function expire(Operation $op): bool
    {
        if ($op->lease_expires_at && $op->lease_expires_at > $this->clock->now()) { return false; }
        $op->update(['state' => 'uncertain', 'safe_code' => 'stale_claim', 'lease_expires_at' => null]);
        return true;
    }
    private static function virgin(Operation $op): bool { return ($op->attempt ?? []) === [] && !array_key_exists('refund', $op->funding ?? []) && self::unsealed($op); }
    private static function testCallRetry(Operation $op): bool
    {
        if(!self::testCallRefusalUnsent($op)||!in_array($op->state,['queued','held'],true)) { return false; }
        $attempt=$op->attempt??[];
        return !isset($attempt['capacity_deferrals'])||count($attempt['capacity_deferrals'])<=5;
    }
    private static function testCallHistory(mixed $history): bool
    {
        if(!is_array($history)||!array_is_list($history)||count($history)>100) { return false; }
        foreach($history as $event) {
            if(!is_array($event)||array_keys($event)!==['phase','refused_at','dispatch_attempted_at','authorized_cost']
                ||!in_array($event['phase']??null,['preflight','purchase'],true)||!is_string($event['refused_at']??null)) { return false; }
            try { if((new \DateTimeImmutable($event['refused_at']))->format(DATE_ATOM)!==$event['refused_at']) { return false; } }
            catch(\Throwable) { return false; }
            if($event['phase']==='preflight') {
                if($event['dispatch_attempted_at']!==null||$event['authorized_cost']!==null) { return false; }
            } elseif(!is_string($event['dispatch_attempted_at'])||!is_array($event['authorized_cost'])
                ||array_keys($event['authorized_cost'])!==['amount','currency']||!is_string($event['authorized_cost']['amount']??null)
                ||$event['authorized_cost']['currency']!=='USD') { return false; }
        }
        return true;
    }
    private static function unsealed(Operation $op): bool
    {
        $a = $op->attempt ?? [];
        return (!array_key_exists('seal', $a) || $a['seal'] === 'absent')
            && !array_key_exists('dispatch_attempted_at', $a) && !array_key_exists('execution', $a)
            && !array_key_exists('provider_reference', $a) && $op->provider_order_id === null
            && !array_key_exists('execution', $op->cost ?? []) && !array_key_exists('actual', $op->cost ?? [])
            && !array_key_exists('dispatch_attempted_at', $op->policy ?? []);
    }
    private static function sameClaim(Operation $a, Operation $b): bool
    {
        return $a->state === 'running' && $b->state === 'running' && self::sameWorker($a, $b)
            && $a->getRawOriginal('lease_expires_at') === $b->getRawOriginal('lease_expires_at');
    }
    private static function sameWorker(Operation $a, Operation $b): bool
    {
        return ($a->attempt['claim_key'] ?? null) !== null && ($a->attempt['claim_key'] ?? null) === ($b->attempt['claim_key'] ?? null)
            && $a->getRawOriginal('started_at') === $b->getRawOriginal('started_at')
            && $a->request_fingerprint === $b->request_fingerprint;
    }
    public static function payloadDigest(Operation $op): string { return hash('sha256', json_encode($op->payload, JSON_THROW_ON_ERROR)); }
    public static function query(Operation $op, Domain $domain): Query { return new Query($domain->domain, Action::from($op->action), (int) $op->term); }
    private static function digest(Operation $op, Domain $domain): string
    {
        return hash('sha256', json_encode([$op->operation_key, $domain->domain, $op->action, $op->term, $op->account_fingerprint,
            $op->authority_generation, self::payloadDigest($op)], JSON_THROW_ON_ERROR));
    }
    public static function outside(): void
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Operation work must start outside database transactions.'); }
    }
}
