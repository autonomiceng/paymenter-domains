<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\AcquisitionAttempt;
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
    /** An accepted sealed transfer before its first status read: the next poll reads it, so no one has a step yet. */
    public static function firstPoll(Operation $op): bool
    {
        $r=$op->policy['reconciliation']??[];
        return self::applies($op)&&$op->state==='uncertain'&&$op->safe_code==='reconciliation_required'&&empty($r['read_failed'])
            &&($r['polls']??0)===0&&!isset($r['transfer_status'])&&in_array($r['reason']??null,['original_attempt_unresolved','transfer_read_deferred'],true)
            &&in_array($op->attempt['execution']['disposition']??null,['accepted','pending'],true);
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
    public static function cutoff(Operation $op): int { return self::deadline($op) + 7 * TransferFollowup::DAY; }
    public static function light(Operation $op,int $now): bool
    {
        $r=$op->policy['reconciliation']??[];
        return self::applies($op)&&!self::expired($op,$now)&&!($r['manual_check']??false)
            &&(self::expected($op)||in_array($r['reason']??null,['original_attempt_unresolved','transfer_read_deferred'],true)&&($r['polls']??0)===0);
    }
    public static function due(Operation $op,int $now): bool
    {
        if(!self::applies($op)){return true;}
        $next = self::schedule($op, $now)['next_read_at'];
        return $next !== null && $next <= $now && !self::reading($op, $now);
    }

    public static function reading(Operation $op, int $now): bool
    {
        return (self::readUntil($op) ?? 0) > $now;
    }

    public static function readUntil(Operation $op): ?int
    {
        $attempt = AcquisitionAttempt::of((int) $op->id, $op->action);
        return $attempt ? $attempt->facts->readUntil?->getTimestamp() : ($op->policy['reconciliation']['reading_until'] ?? null);
    }

    /** Unix seconds for both the dispatcher and staff. Local reservations are never a registrar observation. */
    public static function schedule(Operation $op, int $now): array
    {
        $r = $op->policy['reconciliation'] ?? [];
        $read = $op->action === 'transfer' ? TransferFollowup::observation($op) : null;
        $view = ['last_read_at' => $read['at'] ?? null, 'last_result' => $read['result'] ?? null,
            'next_read_at' => null, 'stop_reason' => null];
        if (!self::applies($op)) {
            $view['stop_reason'] = $op->state;
            return $view;
        }
        $deadline = self::deadline($op);
        $cutoff = self::cutoff($op);
        $next = (int) ($r['next_at'] ?? 0);
        if ($r['manual_check'] ?? false) {
            $view['next_read_at'] = $next ?: $now;
            return $view;
        } elseif (($r['final_check'] ?? null) === 'done') {
            $view['stop_reason'] = TransferFollowup::stopReason($op, $read);
            if ($view['stop_reason'] === null) {
                // An old final check's backoff was inoperative. Only a real read, an active reservation
                // (including a crashed worker) or a capacity retry can pace the second week.
                $retry = isset($r['capacity_wait_since']) ? $next : 0;
                // An unfinished check could have entered the wire any time before its saved deadline.
                $lastPossibleRead = max($read['at'], self::readUntil($op) ?? 0);
                $view['next_read_at'] = max($retry, $deadline, $lastPossibleRead + TransferFollowup::DAY);
            }
        } elseif (!isset($r['final_check']) && ($now >= $deadline
            || ($r['polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT && !self::expected($op))) {
            $view['next_read_at'] = $deadline;
        } else {
            $view['next_read_at'] = $next ?: $now;
        }
        if ($view['stop_reason'] === null && $now >= $cutoff) {
            $view['next_read_at'] = null;
            $view['stop_reason'] = 'transfer_followup_expired';
        } elseif ($view['next_read_at'] !== null && $view['next_read_at'] >= $cutoff) {
            $view['next_read_at'] = null;
            $view['stop_reason'] = 'transfer_followup_window_complete';
        }
        return $view;
    }
    public static function followupScheduled(Operation $op, int $now): bool
    {
        return self::expired($op, $now) && self::schedule($op, $now)['next_read_at'] !== null;
    }

    /** A failed read before day seven can still have an overdue check scheduled. */
    public static function retryingFailedRead(Operation $op, int $now): bool
    {
        return ($op->policy['reconciliation']['reason'] ?? null) === 'transfer_status_unavailable'
            && self::followupScheduled($op, $now);
    }

    public static function expired(Operation $op,int $now): bool { return self::applies($op)&&$now>=self::deadline($op); }
    public static function delay(int $checks): int { return min(43200,7200*(2**min(3,max(0,$checks-1)))); }
    /** Called under the operation locks before I/O, including failed/deferred attempts and worker restarts. */
    public static function reserve(Operation $op,int $now): void
    {
        $p=$op->policy;$r=$p['reconciliation']??[];$checks=(int)($r['checks']??0)+1;
        $manual=$r['manual_check']??false;$overdue=self::expired($op,$now);
        $final=$overdue&&!$manual&&($r['final_check']??null)!=='done';
        $p['reconciliation']=[...$r,'checks'=>$checks,'next_at'=>$final||$manual?$now+self::delay($checks):min(self::deadline($op),$now+self::delay($checks))];
        if ($overdue) {
            if (!AcquisitionAttempt::of((int) $op->id, $op->action)) {
                $p['reconciliation']['reading_until'] = $now + OperationReconciliation::READ_SECONDS;
            }
            if (!$final && !$manual) { $p['reconciliation']['next_at'] = $now + TransferFollowup::DAY; }
        }
        if($final){$p['reconciliation']['final_check']='running';}
        $op->update(['policy'=>$p]);
    }
    /** No wire means no provider-poll backoff was earned. Keep the original FIFO position while capacity is absent. */
    public static function defer(array $policy,int $now,bool $noWire): array
    {
        if(!$noWire){$policy=self::countResumption($policy);}
        $r=$policy['reconciliation'];
        unset($r['reading_until']);
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
        unset($policy['reconciliation']['capacity_wait_since'], $policy['reconciliation']['reading_until']);
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
