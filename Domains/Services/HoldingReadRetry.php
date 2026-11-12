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
    public const STOPPED_CAUSE='The registrar read stopped.';
    public const STOPPED=['read_unavailable','budget_exhausted','superseded','records_incomplete',...ReadFailure::CODES];
    public static function stoppedQuery(Builder $q): Builder
    {
        return $q->where(fn($q)=>$q->whereIn('safe_code',array_values(array_diff(self::STOPPED,self::TRANSIENT)))
            ->orWhere(fn($q)=>$q->whereIn('safe_code',self::TRANSIENT)->where('tries','>=',self::LIMIT)));
    }
    /** A registration or transfer in progress may not be in the account yet: a not-found read retries like a failed read, then stops. */
    public static function scheduled(Domain $d, string $code, int $tries): string
    { return $code==='domain_not_in_account'&&in_array($d->status,['registration_pending','transfer_pending'],true)&&$tries<self::LIMIT?'read_failed':$code; }
    public static function delay(int $tries): int { return min(3600,60*(2**min(6,max(0,$tries-1)))); }
    /** Partial supported reads can recover; known unsupported data and identity/configuration failures need review. */
    public static function retryableHold(Domain $d): bool
    {
        if(!ObservationRecords::held($d)){return false;}
        $hold=$d->observation['record_hold'];
        return in_array($hold['reason']??null,['records_unavailable','glue_unavailable'],true)
            && in_array($hold['code']??null,[null,'read_failed','read_deadline','read_exception','read_no_evidence'],true);
    }
    /** A core success can still contain a partial family; use the same retry policy for scheduled and manual reads. */
    public static function result(Domain $d,string $code): string
    {
        if($code!=='refreshed'||!ObservationRecords::held($d)){return $code;}
        if(self::retryableHold($d)){return 'read_failed';}
        $held=$d->observation['record_hold'];
        return in_array($held['code']??null,ReadFailure::CODES,true)?$held['code']:'records_incomplete';
    }
    /** Staff completion updates only idle current work or its own manual lease, never another live lease or financial job. */
    public static function completedManually(Domain $seed, string $code='refreshed', ?string $claim=null): void
    {
        if(!in_array($code,['refreshed','domain_not_in_account'],true)){throw new \InvalidArgumentException('Invalid manual read result.');}
        if(!\Illuminate\Support\Facades\Schema::hasTable('domains_observation_work')){return;}
        \Illuminate\Support\Facades\DB::transaction(function()use($seed,$code,$claim):void {
            $locked=HoldingLocks::take($seed);$d=$locked['domain'];
            if(ObservationRecords::identity($seed)!==ObservationRecords::identity($d)){return;}
            if($code==='domain_not_in_account'&&(($d->observation['record_failure']['identity']??null)!==ObservationRecords::identity($d)||($d->observation['record_failure']['code']??null)!==$code)){return;}
            $now=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp();
            $q=HoldingObservationWork::current($d)->where(fn($q)=>$q->whereNull('claim')->orWhere('lease_until','<=',$now)->when($claim!==null&&$claim!=='',fn($q)=>$q->orWhere('claim',$claim)));
            $job=(clone $q)->lockForUpdate()->first();if(!$job){return;}
            $result=self::result($d,$code);$partial=$code==='refreshed'&&$result!==$code;
            $tries=$partial?(int)$job->tries+1:0;
            $q->update(['tries'=>$tries,'claim'=>null,'lease_until'=>0,'safe_code'=>$result,
                'due_at'=>$now+($partial?self::delay($tries):($code==='refreshed'&&($d->observation['followup']['state']??null)==='pending'?300:86400))]);
        });
    }
    /** This identity's confirmed not-found read, unless a later read of the same identity proved ownership. Other read failures are never absence. */
    public static function absent(Domain $d): bool
    { return self::absenceFailure($d)!==null&&!self::absenceSuperseded($d); }
    private static function absenceFailure(Domain $d): ?array
    {
        $failure=$d->observation['record_failure']??null;
        return is_array($failure)&&($failure['identity']??null)===ObservationRecords::identity($d)&&($failure['code']??null)==='domain_not_in_account'?$failure:null;
    }
    private static function absenceSuperseded(Domain $d): bool
    { $failure=self::absenceFailure($d); return $failure!==null&&self::ownedAfter($d,$failure['at']??null); }
    /** Saved facts of this identity proved ownership strictly after $at, the time of a failure or hold that said otherwise.
     * Equal or unreadable times, foreign facts and facts that do not prove ownership keep what the failure or hold said. */
    public static function ownedAfter(Domain $d, mixed $at): bool
    {
        $facts=$d->observation['facts']??null;
        if (!is_array($facts)||!ObservationProjection::matches($d,$facts)||($facts['owned']??null)!==true) { return false; }
        $time=static function(mixed $value): ?int { try { return is_string($value)?(new \DateTimeImmutable($value))->getTimestamp():(is_int($value)?$value:null); } catch (\Throwable) { return null; } };
        $owned=$time($facts['at']??null); $before=$time($at);
        return $owned!==null&&$before!==null&&$owned>$before;
    }
    public static function status(Domain $d, ?\DateTimeImmutable $now=null): array
    {
        $job=\Illuminate\Support\Facades\Schema::hasTable('domains_observation_work')?HoldingObservationWork::current($d,true)->orderByDesc('id')->first():null;
        $eligible=$d->status==='active'&&!$d->adoptionPending()&&ObservationEligibility::allows($d);
        $stopped=$job&&self::stoppedQuery(HoldingObservationWork::current($d,true))->exists();
        $code=$job&&in_array($job->safe_code,self::STOPPED,true)?$job->safe_code:null;
        if (self::absent($d)) { $code='domain_not_in_account'; $stopped=true; }
        // A later read proved ownership, so the stopped job no longer means absence. A held family keeps its own diagnostic.
        elseif ($code==='domain_not_in_account'&&self::absenceSuperseded($d)) { $code=ObservationRecords::held($d)?'records_incomplete':null; }
        $now??=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now();
        return ['read_review'=>$eligible&&$code!==null&&in_array($code,['read_failed','read_deadline','read_no_evidence'],true)&&($stopped||($job->tries??0)>=self::LIMIT||($job->resumes??0)>0),
            'records_paused'=>$job?->safe_code==='records_disabled',
            'read_overdue'=>$eligible&&$job&&!$stopped&&$job->due_at+self::OVERDUE_GRACE_SECONDS<$now->getTimestamp()&&!HoldingObservationWork::reading($job,$now->getTimestamp()),
            'next_read'=>$eligible&&$job&&!$stopped?(int)$job->due_at:null,'read_stopped'=>(bool)$stopped,
            'read_code'=>$code,'read_failed_at'=>$code?($d->observation['read_retry']['at']??$d->observation['record_failure']['at']??null):null,'read_attempts'=>(int)($job->tries??0),
            'read_cause'=>match($code){'read_failed'=>'The registrar read failed.','read_deadline'=>'The registrar read timed out.',
                'configuration_unverified'=>'The registrar account configuration is not verified.',
                'binding_mismatch','read_identity_changed'=>'The registrar account or domain identity changed.',
                'encryption_unavailable'=>'Saved registrar data could not be decrypted.',
                'records_incomplete'=>'The registrar did not return a complete supported record family.',
                'domain_not_in_account'=>'Domain is not in the registrar account.',
                null=>null,default=>self::STOPPED_CAUSE}];
    }
}
