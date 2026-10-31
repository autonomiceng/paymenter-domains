<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{BalanceRetry,DriverResult,Disposition,Money,RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
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
    public const READ_STOPPED="It won't retry by itself. Release to try again, or cancel and add credit.";
    /** Work needing locked admission or diagnosis, even if its current proof can no longer authorize sending. */
    public static function pending(Operation $op): bool
    {
        return $op->state==='held'&&(self::legacyHold($op)||in_array($op->safe_code,self::CODES,true)
            &&(in_array($op->safe_code,self::INITIAL_CODES,true)||array_key_exists('balance_retry',$op->policy??[]))
            &&!(in_array($op->safe_code,self::READ_CODES,true)&&self::reads($op)>=self::READ_LIMIT))&&self::supported($op);
    }
    public static function legacyHold(Operation $op): bool
    {
        return $op->state==='held'&&$op->safe_code===self::LEGACY_CODE&&isset($op->attempt['claim_key'])&&($op->policy['balance_retry']['window_at']??null)===null;
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
    public static function held(Operation $op,\DateTimeImmutable $now,?Money $cost=null): void
    {
        if(!self::supported($op)||!OperationClaims::balanceRefusalRetryable($op)||!Schema::hasTable('domains_runtime_operations')){return;}
        $retry=BalanceRetry::held($op,$now,$cost);
        // Tries cannot bound reads: a long balance wait says nothing about read health, and a balance answer proves it.
        $reads=self::reads($op);$read=in_array($op->safe_code,self::READ_CODES,true)||$reads>0&&$op->safe_code==='write_rate_limited';
        $reads=$read?$reads+1:(in_array($op->safe_code,self::BALANCE_CODES,true)?0:$reads);$stopped=$read&&$reads>=self::READ_LIMIT;
        $policy=[...$op->policy,'balance_retry'=>$retry->record];unset($policy['read_retries']);if($reads>0){$policy['read_retries']=$reads;}
        $op->update(['policy'=>$policy,'safe_code'=>$stopped&&$op->safe_code==='write_rate_limited'?'registrar_read_failed':$op->safe_code]);
        DB::table('domains_runtime_operations')->updateOrInsert(['operation_id'=>$op->id],['due_at'=>$now->getTimestamp()+$retry->delay()]);
        if($read&&!$stopped||$retry->cost===null&&$op->safe_code!=='registrar_balance_insufficient'&&!$stopped){return;} // A stored check alone proves no shortfall.
        // One durable alert and one native inbox receipt for this hold, regardless of retry count.
        if(Schema::hasTable('domains_obligation_alerts')){ObligationAlerts::record($op->domain,'operation_review',$now->getTimestamp(),(int)$op->id);}
        $key=($stopped?'read-hold:':'balance-hold:').$op->operation_key.':'.$op->authority_generation.':'.$retry->record['held_at'];
        DB::afterCommit(static fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffAlert::send(
            $stopped?'Registrar reads keep failing':'Registrar balance is too low',($stopped?self::READ_STOPPED:self::MESSAGE).' Open request #'.$op->id.'.','filament.admin.resources.domains.index',deliveryKey:$key));
    }
    /** The existing purchase-scope negative proof also permits retaining a system retry's original claim. */
    public static function capReset(Operation $op): bool
    {
        try {
            $before=clone $op;$before->setRawAttributes($op->getRawOriginal(),true);
            $old=BalanceRetry::from($before);$next=BalanceRetry::from($op);
            if(!$old?->window||!$next||$before->registrar_key!=='enom'||$before->state!=='running'||$op->state!=='held'||$op->safe_code!=='write_rate_limited'
                ||$op->lease_expires_at!==null||$op->started_at!=$before->started_at||$op->request_fingerprint!==$before->request_fingerprint
                ||$op->provider_order_id!==null||$op->confirmed_expires_at!==null||$op->completed_at!==null
                ||($before->attempt['seal']??null)!=='sealed'||($before->attempt['send_state']??null)!=='possibly_sent'
                ||!isset($before->attempt['authorized_cost'],$before->attempt['dispatch_attempted_at'],$before->attempt[$op->action==='transfer'?'transfer_admission':'registration_admission'])
                ||array_diff(array_keys($before->attempt),['claim_key','seal','send_state','dispatch_attempted_at','authorized_cost','registration_admission','transfer_admission','capacity_deferrals','test_call_refusals'])
                ||array_diff(array_keys($op->cost??[]),['estimated','currency','unit'])||array_key_exists('dispatch_attempted_at',$op->policy??[])
                ||!EnomTestCalls::purchaseUnconsumed($op->account_fingerprint,(int)$op->id)){return false;}
            $cap=$next->record['cap_refusal'];$policy=$before->policy;$policy['balance_retry']=array_replace($old->record,['cap_refusal'=>$cap]);
            $funding=$before->funding;unset($funding['seal']);
            return $cap!==null&&$cap['dispatch_attempted_at']===$before->attempt['dispatch_attempted_at']&&$cap['authorized_cost']===$before->attempt['authorized_cost']
                &&$op->policy===$policy&&$op->funding===$funding&&$op->attempt===['claim_key'=>$before->attempt['claim_key'],'seal'=>'absent','send_state'=>'definitely_unsent',
                    ...array_intersect_key($before->attempt,['capacity_deferrals'=>true,'test_call_refusals'=>true])];
        }catch(\Throwable){return false;}
    }
    /** Retry windows may advance only on locked held-to-queued admission; sent authority is retained. */
    public static function guard(Operation $op,bool $capReset): void
    {
        $old=$op->getOriginal('policy')['balance_retry']??null;$next=BalanceRetry::from($op);
        if($old===null){return;}
        if(!$next){throw new \DomainException('System retry authority is retained.');}
        $firstClaim=$old['claim_key']===null&&$old['request_fingerprint']===null&&$op->getOriginal('state')==='queued'&&$op->state==='running'
            &&$op->getOriginal('started_at')===null&&$op->getOriginal('request_fingerprint')===null&&($op->getOriginal('attempt')??[])===[]
            &&!array_intersect(['claim','seal'],array_keys($op->getOriginal('funding')??[]))&&$next->record['claim_key']!==null;
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
        if($old['cap_refusal']!==$next->record['cap_refusal']&&!$capReset
            ||($op->getOriginal('attempt')['seal']??null)==='sealed'&&!$capReset&&$old!==$next->record){throw new \DomainException('Sealed system retry authority is frozen.');}
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
