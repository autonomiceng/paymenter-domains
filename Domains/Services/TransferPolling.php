<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Read scheduling only. The sealed transfer's seven-day window never authorizes another purchase or a refund. */
final class TransferPolling
{
    public static function applies(Operation $op): bool
    {
        return $op->action==='transfer' && ($op->attempt['seal']??null)==='sealed' && $op->started_at!==null
            && (in_array($op->state,['pending','uncertain','running'],true)||$op->state==='held'&&$op->safe_code==='transfer_needs_action');
    }
    public static function ownsObservation(Domain $domain): bool
    {
        return $domain->status==='transfer_pending' && $domain->operations()->where('action','transfer')
            ->where('authority_generation',$domain->authority_generation)->where('account_fingerprint',$domain->account_fingerprint)
            ->get()->contains(fn($op)=>self::applies($op));
    }
    public static function expected(Operation $op): bool
    {
        $r=$op->policy['reconciliation']??[];
        return self::applies($op) && in_array($r['transfer_status']??null,['pending','needs_action'],true)
            && in_array($r['reason']??null,['transfer_pending','transfer_needs_action','transfer_read_deferred','original_attempt_unresolved','poll_limit_reached'],true);
    }
    /** Unsent readmission can advance the execution window, but the sealed claim keeps that window immutable. */
    public static function start(Operation $op): int
    {
        try {
            if(($op->attempt['seal']??null)==='sealed'){return (ExecutionWindow::start($op)??$op->started_at)?->getTimestamp()??0;}
        } catch (\Throwable) { /* Invalid window authority cannot grant a later watch or hide a stale read. */ }
        return $op->started_at?->getTimestamp()??0;
    }
    public static function deadline(Operation $op): int { return self::start($op)+7*86400; }
    public static function light(Operation $op,int $now): bool
    {
        $r=$op->policy['reconciliation']??[];
        return self::applies($op)&&!self::expired($op,$now)&&!($r['manual_check']??false)
            &&(self::expected($op)||in_array($r['reason']??null,['original_attempt_unresolved','transfer_read_deferred'],true)&&($r['polls']??0)===0);
    }
    public static function due(Operation $op,int $now): bool
    {
        if(!self::applies($op)){return true;}
        $r=$op->policy['reconciliation']??[];
        if($r['manual_check']??false){return ($r['next_at']??0)<=$now;}
        if(($r['final_check']??null)==='done'){return false;}
        if(self::expired($op,$now)&&!isset($r['final_check'])){return true;}
        if(!isset($r['final_check'])&&($r['polls']??0)>=OperationReconciliation::POLL_LIMIT&&!self::expected($op)){return false;}
        return ($r['next_at']??0)<=$now;
    }
    public static function expired(Operation $op,int $now): bool { return self::applies($op)&&$now>=self::deadline($op); }
    public static function delay(int $checks): int { return min(43200,7200*(2**min(3,max(0,$checks-1)))); }
    /** Called under the operation locks before I/O, including failed/deferred attempts and worker restarts. */
    public static function reserve(Operation $op,int $now): void
    {
        $p=$op->policy;$r=$p['reconciliation']??[];$checks=(int)($r['checks']??0)+1;
        $manual=$r['manual_check']??false;$final=self::expired($op,$now)&&!$manual;
        $p['reconciliation']=[...$r,'checks'=>$checks,'next_at'=>$final||$manual?$now+self::delay($checks):min(self::deadline($op),$now+self::delay($checks))];
        if($final){$p['reconciliation']['final_check']='running';}
        $op->update(['policy'=>$p]);
    }
    /** No wire means no provider-poll backoff was earned. Keep the original FIFO position while capacity is absent. */
    public static function defer(array $policy,int $now,bool $noWire): array
    {
        if(!$noWire){$policy=self::countResumption($policy);}
        $r=$policy['reconciliation'];
        $r['capacity_wait_since']??=$now;
        if($noWire){$r['checks']=max(0,$r['checks']-1);}
        if($noWire||($r['final_check']??null)==='running'){
            $r['next_at']=$now+($noWire?60:self::delay($r['checks']));
            if(($r['final_check']??null)==='running'){$r['final_check']='pending';}
        }
        $policy['reconciliation']=$r;return $policy;
    }
    private static function countResumption(array $policy): array
    {
        if(isset($policy['reconciliation']['manual_resumption'])){
            $policy['reconciliation_resumptions']=[...($policy['reconciliation_resumptions']??[]),$policy['reconciliation']['manual_resumption']];
            unset($policy['reconciliation']['manual_resumption']);
        }
        return $policy;
    }
    public static function finished(array $policy,bool $readStarted=true): array
    {
        if($readStarted){$policy=self::countResumption($policy);if($policy['reconciliation']['manual_check']??false){$policy['reconciliation']['manual_check']=false;}}
        unset($policy['reconciliation']['capacity_wait_since']);
        if(($policy['reconciliation']['final_check']??null)==='running'){$policy['reconciliation']['final_check']='done';}
        return $policy;
    }
    /** Deferred transfers go first, oldest refusal first; the existing bounded account cursor still rotates batches. */
    public static function priority(Operation $op): array
    {
        $since=$op->policy['reconciliation']['capacity_wait_since']??null;
        return self::applies($op)&&$since!==null?[0,$since,$op->id]:[1,0,$op->id];
    }
}
