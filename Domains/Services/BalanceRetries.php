<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{BalanceRetry,DriverResult,Disposition,Money,RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAdmission, AcquisitionLane, AcquisitionResult, AcquisitionRetryProof};
/** Scheduling and the cheap first read. Readmission itself belongs to OperationClaims' ordered financial locks. */
final class BalanceRetries
{
    public const BALANCE_CODES=['registrar_balance_insufficient','usd_balance_required','enom_test_transfer_usd_balance_required'];
    /** A failed read or missing price proves nothing about the order, so it retries here before anyone is asked. */
    public const READ_CODES=['registrar_read_failed','registrar_price_missing'];
    public const READ_LIMIT=6;
    public const INITIAL_CODES=[...self::BALANCE_CODES,...self::READ_CODES];
    /** Old builds saved failed reads and taken names under this code too, so a claimed hold proves nothing until one fresh look. */
    public const LEGACY_CODE='write_acceptance_required';
    public const RELEASE_CODES=[...self::INITIAL_CODES,self::LEGACY_CODE];
    public const REVIEW_CODES=['balance_retry_funding_changed','balance_retry_authority_changed','balance_retry_setup_changed'];
    public const CODES=[...self::INITIAL_CODES,...self::REVIEW_CODES,'write_rate_limited','processing'];
    public const MESSAGE='Not sent: registrar balance is too low. Add funds. It will send automatically once funds are available.';
    public const READ_STOPPED="It won't retry by itself. Release to try again, or cancel and refund.";
    /** Work needing locked admission or diagnosis, even if its current proof can no longer authorize sending. */
    public static function pending(Operation $op): bool
    {
        return $op->state === 'held' && AcquisitionResult::continuation($op)?->lane === AcquisitionLane::BalanceWait;
    }
    public static function legacyHold(Operation $op): bool
    {
        return $op->state==='held'&&$op->safe_code===self::LEGACY_CODE&&!isset($op->policy['balance_retry']['window_at'])
            &&($prior=$op->acquisitionAttemptFacts())!==null&&!$prior->sealed()&&AcquisitionRetryProof::releasedForReadmission($op,$prior)
            &&($op->action!=='register'||!AcquisitionRetryProof::catalogueRetry($op));
    }
    /** The probe belongs to the admitted automatic lane. */
    public static function active(Operation $op): bool
    {
        return AcquisitionResult::continuation($op)?->lane === AcquisitionLane::BalancePurchase;
    }
    public static function owns(Operation $op): bool
    {
        return AcquisitionResult::continuation($op)?->lane?->ownsBalance() === true;
    }
    private static function reads(Operation $op): int {return (int)($op->policy['read_retries']??0);}
    public static function candidate(Operation $op): bool
    { return self::pending($op)&&OperationClaims::balanceAdmissionReason($op)===null; }
    private static function supported(Operation $op): bool
    {
        return $op->action==='register'||$op->action==='transfer'&&$op->registrar_key==='enom'
            &&Account::where('server_id',$op->server_id)->value('environment')==='test';
    }
    /** Called with the domain and operation locked, after a definitely-unsent finish. */
    public static function held(Operation $op,\DateTimeImmutable $now,?Money $cost=null,bool $confirmedShortfall=false): bool
    {
        if(!self::supported($op)||!OperationClaims::balanceRefusalRetryable($op)){return false;}
        $retry=BalanceRetry::held($op,$now,$cost);
        // Tries cannot bound reads: a long balance wait says nothing about read health, and a balance answer proves it.
        $reads=self::reads($op);$read=in_array($op->safe_code,self::READ_CODES,true)||$reads>0&&$op->safe_code==='write_rate_limited';
        $reads=$read?$reads+1:(in_array($op->safe_code,self::BALANCE_CODES,true)?0:$reads);$stopped=$read&&$reads>=self::READ_LIMIT;
        $policy=[...$op->policy,'balance_retry'=>$retry->record];unset($policy['read_retries']);if($reads>0){$policy['read_retries']=$reads;}
        $op->update(['policy'=>$policy,'safe_code'=>$stopped&&$op->safe_code==='write_rate_limited'?'registrar_read_failed':$op->safe_code]);
        AcquisitionResult::schedule($op, $stopped ? null : AcquisitionLane::BalanceWait,
            $stopped ? null : $now->getTimestamp() + $retry->delay(),
            $confirmedShortfall && !$stopped ? $now : null, preserveShortfall: !$stopped);
        if($read&&!$stopped||$retry->cost===null&&$op->safe_code!=='registrar_balance_insufficient'&&!$stopped){return true;} // A stored check alone proves no shortfall.
        // One durable alert and one native inbox receipt for this hold, regardless of retry count.
        if(Schema::hasTable('domains_obligation_alerts')){ObligationAlerts::record($op->domain,'operation_review',$now->getTimestamp(),(int)$op->id);}
        $key=($stopped?'read-hold:':'balance-hold:').$op->operation_key.':'.$op->authority_generation.':'.$retry->record['held_at'];
        // The request page carries the next step (MESSAGE or READ_STOPPED) and its actions.
        $line=\Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::action($op->action).' of '.$op->domain->domain.($stopped?' stopped retrying.':' is waiting for registrar funds.');
        $id=(int)$op->id;
        DB::afterCommit(static fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffAlert::send(
            $stopped?'Registrar reads keep failing':'Registrar balance is too low',$line,'filament.admin.resources.registrar-requests.view',deliveryKey:$key,parameters:['record'=>$id]));
        return true;
    }
    /** Our own rate guard proves nothing was sent, so nobody needs to act: it waits on the same timed retry as a balance hold. Called locked. */
    public static function rateHeld(Operation $op,\DateTimeImmutable $now): void
    {
        if ($op->safe_code !== 'write_rate_limited' || !self::supported($op) || BalanceRetry::from($op) !== null
            || !AcquisitionRetryProof::capacityRetry($op) || !($window = AcquisitionResult::window($op))
            || in_array($window->origin, [AcquisitionAdmission::TestCapacity->value, AcquisitionAdmission::StaffRelease->value], true)) { return; }
        // A contact step that sent nothing has no balance question; its bounded retries own it (ContactStepRetries).
        if(ContactProfileHold::unsent($op,retry:true)){return;}
        $op->update(['policy'=>[...$op->policy,'balance_retry'=>BalanceRetry::held($op,$now,null)->admitted($op,$now)->record]]);
        self::held($op,$now);
    }
    /** Retry windows may advance only on locked held-to-queued admission; sent authority is retained. */
    public static function guard(Operation $op): void
    {
        $old=$op->getOriginal('policy')['balance_retry']??null;$next=BalanceRetry::from($op);
        if($old===null){return;}
        if(!$next){throw new \DomainException('System retry authority is retained.');}
        $firstClaim=$old['claim_key']===null&&$old['request_fingerprint']===null&&$op->getOriginal('state')==='queued'&&$op->state==='running'
            &&$op->getRawOriginal('started_at')===null&&$op->getRawOriginal('request_fingerprint')===null
            &&($op->acquisitionWorker !== null || (($op->getOriginal('attempt')??[])===[]
                &&!array_intersect(['claim','seal'],array_keys($op->getOriginal('funding')??[]))))&&$next->record['claim_key']!==null;
        foreach(['type','operation_key','generation','claim_key','request_fingerprint','held_at'] as $key){
            if($firstClaim&&in_array($key,['claim_key','request_fingerprint'],true)){continue;}
            if($old[$key]!==$next->record[$key]){throw new \DomainException('Original system retry identity is frozen.');}
        }
        if($old['tries']!==$next->record['tries']||$old['window_at']!==$next->record['window_at']){
            if($op->getOriginal('state')!=='held'||$op->state!=='queued'||$next->record['tries']!==min(1000000,$old['tries']+1)
                ||$next->window===null||$old['window_at']!==null&&$next->window<new \DateTimeImmutable($old['window_at'])){
                throw new \DomainException('System retry window requires readmission.');
            }
        }
        if ($old['cap_refusal'] !== $next->record['cap_refusal']
            || ($op->getOriginal('attempt')['seal'] ?? null) === 'sealed' && $old !== $next->record) {
            throw new \DomainException('Sealed system retry authority is frozen.');
        }
    }
    /** Use the last fresh quote only to stop early. A positive result still needs the full fresh preflight. */
    public static function probe(Operation $op,RegistrarDriver $reader,RequestContext $context): ?DriverResult
    {
        $retry=BalanceRetry::from($op);if(!$retry?->window){return null;}
        $balance=BalanceReads::read($reader,$context,'preflight');$funds=$balance->evidence()->balance;
        $context->binding->requireMatch($balance->binding);
        if($balance->disposition!==Disposition::Accepted||$funds?->currency!=='USD'){
            if(self::reads($op)>0){throw new WriteHeld('registrar_read_failed');}
            throw new ReadDeferred('Balance retry read unavailable.');
        }
        // Reprice when backoff reaches its six-hour cap; an old high quote must not starve an affordable order.
        // Even an empty account reprices at the cap. Fresh preflight reads retain their normal budgets.
        $cost=$retry->cost??new Money('0.000001','USD');
        if($retry->delay()<21600&&!WriteMoney::sufficient($funds,$cost)){throw new WriteHeld('registrar_balance_insufficient',$retry->cost);}
        return $balance;
    }
}
