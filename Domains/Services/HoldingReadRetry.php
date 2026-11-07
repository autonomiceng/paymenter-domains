<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Query\Builder;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Read-only recovery policy. It grants no financial or registrar-write authority. */
final class HoldingReadRetry
{
    public const TRANSIENT=['read_failed','read_deadline'];
    public const RECOVERABLE=[...self::TRANSIENT,'configuration_unverified'];
    public const LIMIT=5;
    // Discovery admits 25 holdings and the runtime claims one per account each minute. Allow that batch to drain.
    private const OVERDUE_GRACE_SECONDS=30*60;
    public const STOPPED=['read_unavailable','budget_exhausted','superseded','records_incomplete',...ReadFailure::CODES];
    public static function stoppedQuery(Builder $q): Builder
    {
        return $q->where(fn($q)=>$q->whereIn('safe_code',array_values(array_diff(self::STOPPED,self::TRANSIENT)))
            ->orWhere(fn($q)=>$q->whereIn('safe_code',self::TRANSIENT)->where('tries','>=',self::LIMIT)));
    }
    public static function delay(int $tries): int { return min(3600,60*(2**min(6,max(0,$tries-1)))); }
    /** A successful staff retry restores only idle current read work, never a live lease or financial job. */
    public static function completedManually(Domain $seed): void
    {
        if(!\Illuminate\Support\Facades\Schema::hasTable('domains_observation_work')){return;}
        \Illuminate\Support\Facades\DB::transaction(function()use($seed):void {
            $locked=HoldingLocks::take($seed);$d=$locked['domain'];
            if(ObservationRecords::identity($seed)!==ObservationRecords::identity($d)||ObservationRecords::held($d)){return;}
            $now=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp();
            HoldingObservationWork::current($d)->where('lease_until','<=',$now)
                ->update(['tries'=>0,'claim'=>null,'safe_code'=>'refreshed','due_at'=>$now+(($d->observation['followup']['state']??null)==='pending'?300:86400)]);
        });
    }
    public static function status(Domain $d, ?\DateTimeImmutable $now=null): array
    {
        $job=\Illuminate\Support\Facades\Schema::hasTable('domains_observation_work')?HoldingObservationWork::current($d,true)->orderByDesc('id')->first():null;
        $eligible=$d->status==='active'&&!$d->adoptionPending()&&ObservationEligibility::allows($d);
        $stopped=$job&&self::stoppedQuery(HoldingObservationWork::current($d,true))->exists();
        $code=$job&&in_array($job->safe_code,self::STOPPED,true)?$job->safe_code:null;
        $now??=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now();
        return ['records_paused'=>$job?->safe_code==='records_disabled',
            'read_overdue'=>$eligible&&$job&&!$stopped&&$job->due_at+self::OVERDUE_GRACE_SECONDS<$now->getTimestamp()&&$job->lease_until<=$now->getTimestamp(),
            'next_read'=>$eligible&&$job&&!$stopped?(int)$job->due_at:null,'read_stopped'=>(bool)$stopped,
            'read_code'=>$code,'read_failed_at'=>$code?($d->observation['read_retry']['at']??$d->observation['record_failure']['at']??null):null,'read_attempts'=>(int)($job->tries??0),
            'read_cause'=>match($code){'read_failed'=>'The registrar read failed.','read_deadline'=>'The registrar read timed out.',
                'configuration_unverified'=>'The registrar account configuration is not verified.',
                'binding_mismatch','read_identity_changed'=>'The registrar account or domain identity changed.',
                'encryption_unavailable'=>'Saved registrar data could not be decrypted.',
                'records_incomplete'=>'The registrar did not return a complete supported record family.',
                null=>null,default=>'The registrar read stopped.'}];
    }
}
