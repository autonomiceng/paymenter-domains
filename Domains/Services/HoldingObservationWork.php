<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
/** Shared bounded holding portion. Only its owner selects and advances account turns. */
final class HoldingObservationWork
{
    /** Only the current binding, generation and follow-up phase can stop this holding. */
    public static function stopped(Domain $d): bool
    {
        if (!Schema::hasTable('domains_observation_work')) { return false; }
        return HoldingReadRetry::stoppedQuery(self::current($d,true))->exists() && ObservationEligibility::allows($d);
    }
    /** Batched stopped() for a page of domains: the ids, as keys, of those whose current work stopped. */
    public static function stoppedIds(\Illuminate\Support\Collection $domains): array
    {
        if ($domains->isEmpty()||!Schema::hasTable('domains_observation_work')) { return []; }
        $rows=HoldingReadRetry::stoppedQuery(DB::table('domains_observation_work')->whereIn('domain_id',$domains->pluck('id')))
            ->get(['domain_id','server_id','account_fingerprint','generation','phase']);
        $stopped=$domains->filter(fn(Domain $d)=>$rows->contains(fn($r)=>(int)$r->domain_id===(int)$d->id&&(int)$r->server_id===(int)$d->server_id
            &&$r->account_fingerprint===$d->account_fingerprint&&(int)$r->generation===(int)$d->authority_generation&&$r->phase===self::readPhase($d)))
            ->values();
        $pending=$stopped->whereIn('status',['registration_pending','transfer_pending']);
        $operations=$pending->isEmpty()?collect():Operation::whereIn('domain_id',$pending->pluck('id'))->whereIn('action',['register','transfer'])->get()->groupBy('domain_id');
        return $stopped->filter(fn(Domain $d)=>ObservationEligibility::allows($d,$operations->get($d->id,collect())))->pluck('id')->flip()->all();
    }
    /** Advisory selection only; resume revalidates the locked holding and job. */
    public static function currentId(Domain $d): ?int
    {
        $id=self::current($d,true)->orderByDesc('id')->value('id');
        return $id===null?null:(int)$id;
    }
    private static function readPhase(Domain $d): ?string
    {try{return self::phase($d);}catch(\Illuminate\Contracts\Encryption\DecryptException){return null;}}
    public static function current(Domain $d,bool $advisory=false): \Illuminate\Database\Query\Builder
    {
        return DB::table('domains_observation_work')->where('domain_id',$d->id)->where('server_id',$d->server_id)
            ->where('account_fingerprint',$d->account_fingerprint)->where('generation',$d->authority_generation)
            ->where('phase',$advisory?self::readPhase($d):self::phase($d));
    }
    /** Under the account lock, retire obsolete idle work before unsent proof can be lost. Returns true if unsent. */
    public static function discardUnsent(Domain $d, \DateTimeImmutable $now, ?\Illuminate\Support\Collection $operations=null): bool
    {
        if (ObservationEligibility::allows($d,$operations)) { return false; }
        if (Schema::hasTable('domains_observation_work')) { self::current($d)->where('lease_until','<=',$now->getTimestamp())->delete(); }
        return true;
    }
    public function __construct(private readonly DomainObservation $observer, private readonly Clock $clock) {}
    /** Shared reviewed recovery; the selected scheduler supplies its captured generation guard. */
    public function resume(int $id, string $reason, StaffOperationReview|\Paymenter\Extensions\Servers\Domains\Services\StaffSyncReview|null $review, \Closure $current): void
    {
        OperationClaims::outside(); if (!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$reason)) { throw new \InvalidArgumentException('Safe reason required.'); }
        $seed=Domain::findOrFail(DB::table('domains_observation_work')->where('id',$id)->value('domain_id'));
        DB::transaction(function () use ($id,$reason,$seed,$review,$current): void {
            $l=HoldingLocks::take($seed,$review?->actorId); $current((int)$seed->server_id); $review?->check($l); $d=$l['domain']; $q=DB::table('domains_observation_work')->where('id',$id); $job=(clone $q)->lockForUpdate()->firstOrFail();
            if ($job->domain_id!=$d->id||$job->server_id!=$d->server_id||$job->phase!==self::phase($d)||(int)$job->generation!==$d->authority_generation||$job->account_fingerprint!==$d->account_fingerprint||$job->lease_until>$this->clock->now()->getTimestamp()||$job->resumes>=20) { throw new \DomainException('Read resume unavailable.'); }
            $p=$d->observation??[]; unset($p['record_hold']); $p['resumptions']=[...($p['resumptions']??[]),['work_id'=>$id,'reason'=>$reason,'at'=>$this->clock->now()->format(DATE_ATOM)]];
            $d->update(['observation'=>$p]); $q->update(['resumes'=>$job->resumes+1,'tries'=>0,'claim'=>null,'safe_code'=>null,'due_at'=>$this->clock->now()->getTimestamp()]); $review?->record($l);
        });
    }
    public function turn(object $queue, \Closure $guard, ?\Closure $catalogue = null): string
    {
        $guard((int)$queue->server_id);
        $account=Account::where('server_id',$queue->server_id)->firstOrFail();
        if($account->account_fingerprint!==$queue->account_fingerprint){throw new \DomainException('Observation queue binding changed.');}
        $b=AccountBindings::binding($account);
        if(!EnomTestManagement::accepted($b)&&(EnomTestRegistration::enabled($b)||EnomTestRenewal::enabled($b)||EnomTestTransfer::enabled($b))){return 'read_deferred';}
        $this->discover($queue,$guard); if($catalogue){$catalogue();}
        $job=$this->claim($queue,$guard); if(!$job){return 'idle';}
        $current=function()use($guard,$job):void {
            $guard((int)$job->server_id);
            $q=DB::table('domains_observation_work')->where('id',$job->id)->where('claim',$job->claim)
                ->where('generation',$job->generation)->where('account_fingerprint',$job->account_fingerprint)->where('phase',$job->phase)
                ->where('lease_until','>',$this->clock->now()->getTimestamp());
            if(!(DB::transactionLevel()>0?$q->lockForUpdate():$q)->exists()){throw new ReadDeferred('Stale observation work.');}
        };
        try{return $this->observer->run((int)$job->domain_id,(int)$job->generation,$job->account_fingerprint,readRecords:true,current:$current,
            completion:fn(string $code,?\Closure $credentials):string=>$this->finish($job,$code,$current,$credentials));}
        catch(ReadDeferred|EnomTestCallLimit){$code='read_deferred';}
        catch(\Throwable $error){$code=ReadFailure::code($error);}
        return $this->finish($job,$code,$current);
    }
    private function discover(object $queue, \Closure $guard): void
    {
        DB::transaction(function () use ($queue,$guard): void {
            $guard((int)$queue->server_id);
            $a=Account::where('server_id',$queue->server_id)->lockForUpdate()->firstOrFail();
            if ($a->account_fingerprint!==$queue->account_fingerprint) { throw new \DomainException('Observation queue binding changed.'); }
            $q=DB::table('domains_observation_queues')->where('id',$queue->id); $current=(clone $q)->lockForUpdate()->firstOrFail();
            $rows=Domain::where('server_id',$a->server_id)->where('id','>',$current->cursor)->orderBy('id')->limit(25)->get();
            foreach ($rows as $d) {
                if ($d->adoptionPending() || !in_array($d->status,['active','registration_pending','transfer_pending'],true)||$d->account_fingerprint!==$a->account_fingerprint) { continue; }
                // Financial transfer reconciliation already reads status, history and ownership. Avoid a second automatic poller.
                if(TransferPolling::ownsObservation($d)){
                    self::current($d)->where('lease_until','<=',$this->clock->now()->getTimestamp())->delete();continue;
                }
                if (self::discardUnsent($d,$this->clock->now())) { continue; }
                DB::table('domains_observation_work')->insertOrIgnore(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,
                    'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>self::phase($d),'due_at'=>$this->clock->now()->getTimestamp()]);
                $this->recover($d);
                // Legacy healthy ownership-only jobs must not postpone a missing or stale family read.
                if($d->status==='active'&&ObservationProjection::recordStatus($d,$this->clock->now())['stale']){
                    self::current($d)->where('safe_code','refreshed')->where('lease_until','<=',$this->clock->now()->getTimestamp())
                        ->update(['due_at'=>$this->clock->now()->getTimestamp()]);
                }
            }
            $q->update(['cursor'=>$rows->count()===25?$rows->last()->id:0]);
        });
    }
    /** Repaired configuration wakes legacy stopped reads once; a new failure records today's credential fence. */
    private function recover(Domain $d): void
    {
        $job=self::current($d)->lockForUpdate()->first();$now=$this->clock->now()->getTimestamp();
        if(!$job||$job->lease_until>$now||!in_array($job->safe_code,HoldingReadRetry::RECOVERABLE,true)
            ||($job->safe_code!=='configuration_unverified'&&$job->tries<HoldingReadRetry::LIMIT)||$job->resumes>=20){return;}
        try{$a=Account::where('server_id',$d->server_id)->sole();$credential=app(AccountBindings::class)->holdingCredentialRevision(AccountBindings::binding($a));}
        catch(\Throwable){return;}
        $p=$d->observation??[];$prior=$p['read_retry']??[];
        if(($prior['work_id']??null)===(int)$job->id&&($prior['credential']??null)===$credential){return;}
        $p['read_retry']=['work_id'=>(int)$job->id,'credential'=>$credential,'auto_resumed'=>true,'at'=>$this->clock->now()->format(DATE_ATOM)];
        $p['resumptions']=[...($p['resumptions']??[]),['work_id'=>(int)$job->id,'reason'=>'configuration_restored','at'=>$this->clock->now()->format(DATE_ATOM)]];
        $d->update(['observation'=>$p]);
        self::current($d)->where('id',$job->id)->update(['tries'=>0,'resumes'=>$job->resumes+1,'claim'=>null,'safe_code'=>null,'due_at'=>$now]);
    }
    private function claim(object $queue, \Closure $guard): ?object
    {
        return DB::transaction(function () use ($queue,$guard): ?object {
            $guard((int)$queue->server_id);
            $now=$this->clock->now()->getTimestamp(); $q=DB::table('domains_observation_work')->where('server_id',$queue->server_id)
                ->where('account_fingerprint',$queue->account_fingerprint)->where('due_at','<=',$now)->where('tries','<',28)->where('lease_until','<=',$now)
                ->where(function($q){$q->whereNull('safe_code')->orWhereNotIn('safe_code',HoldingReadRetry::STOPPED)
                    ->orWhere(fn($q)=>$q->whereIn('safe_code',HoldingReadRetry::TRANSIENT)->where('tries','<',HoldingReadRetry::LIMIT));});
            $job=$q->orderBy('due_at')->orderBy('id')->lockForUpdate()->first(); if (!$job) { return null; }
            $d=Domain::find($job->domain_id);
            if($d&&TransferPolling::ownsObservation($d)){DB::table('domains_observation_work')->where('id',$job->id)->delete();return null;}
            if ($d && (!in_array($d->status,['active','registration_pending','transfer_pending'],true)||!ObservationEligibility::allows($d)||$d->account_fingerprint!==$job->account_fingerprint||$d->adoptionPending() || (int)$job->generation!==$d->authority_generation || $job->phase!==self::phase($d))) {
                DB::table('domains_observation_work')->where('id',$job->id)->update(['safe_code'=>'superseded']); return null;
            }

            $job->claim=bin2hex(random_bytes(16));
            DB::table('domains_observation_work')->where('id',$job->id)->update(['claim'=>$job->claim,'lease_until'=>$now+300,'tries'=>$job->tries+1]);
            return $job;
        });
    }
    private function finish(object $job, string $code, \Closure $guard, ?\Closure $credentials=null): string
    {
        return DB::transaction(function()use($job,$code,$guard,$credentials):string {
            try { $guard(); if($credentials){$credentials();} }
            catch (\DomainException) {
                // Draining a stopped generation may release only its own lease, never reschedule it.
                DB::table('domains_observation_work')->where('id',$job->id)->where('claim',$job->claim)
                    ->update(['claim'=>null,'lease_until'=>0]); return 'read_deferred';
            }
            $this->finishCurrent($job,$code); return $code==='read_deferred'?$code:(DB::table('domains_observation_work')->where('id',$job->id)->value('safe_code')??$code);
        });
    }
    private function finishCurrent(object $job, string $code): void
    {
        $now=$this->clock->now()->getTimestamp();
        if ($code==='read_deferred') {
            DB::table('domains_observation_work')->where('id',$job->id)->where('claim',$job->claim)->update([
                'claim'=>null,'lease_until'=>0,'tries'=>$job->tries,'safe_code'=>$job->safe_code,'due_at'=>$now+60]); return;
        }
        $d=Domain::findOrFail($job->domain_id); $tries=$job->tries+1;
        if($code==='refreshed'&&ObservationRecords::held($d)){
            $held=$d->observation['record_hold'];
            $code=in_array($held['code']??null,ReadFailure::CODES,true)?$held['code']:'records_incomplete';
        }
        if(in_array($code,HoldingReadRetry::RECOVERABLE,true)){
            $p=$d->observation??[];$prior=$p['read_retry']??[];$credential=null;
            try{$a=Account::where('server_id',$d->server_id)->sole();$credential=app(AccountBindings::class)->holdingCredentialRevision(AccountBindings::binding($a));}catch(\Throwable){}
            $p['read_retry']=['work_id'=>(int)$job->id,'credential'=>$credential,'at'=>$this->clock->now()->format(DATE_ATOM),
                'auto_resumed'=>($prior['work_id']??null)===$job->id?($prior['auto_resumed']??false):false];
            $d->update(['observation'=>$p]);
        }
        $pending=$code==='healthy_pending'; $start=$d->operations()->where('action','transfer')->where('authority_generation',$d->authority_generation)->orderBy('id')->get()->first(fn($o)=>($o->attempt['seal']??null)==='sealed'&&in_array($o->state,['pending','uncertain','held'],true))?->started_at;
        $startAt=$start instanceof \DateTimeInterface?$start->getTimestamp():($start===null?null:(new \DateTimeImmutable($start))->getTimestamp());
        $within=$pending&&$startAt!==null&&$now<$startAt+7*86400;
        $exhausted=$pending?(!$within||$tries>=28):($code!=='refreshed'&&$tries>=5);
        $follow=($d->observation['followup']['state']??null)==='pending';
        DB::table('domains_observation_work')->where('id',$job->id)->where('claim',$job->claim)->update([
            'claim'=>null,'lease_until'=>0,'tries'=>$code==='refreshed'?0:$tries,
            'due_at'=>$now+($pending?21600:($code==='refreshed'?($follow?300:86400):HoldingReadRetry::delay($tries))),
            'safe_code'=>in_array($code,HoldingReadRetry::STOPPED,true)?$code:($exhausted?'budget_exhausted':$code)]);
    }
    public static function phase(Domain $d): string
    { $follow=$d->observation['followup']??[]; return isset($follow['operation_key'])&&($follow['generation']??null)===$d->authority_generation?hash('sha256',$follow['operation_key']):'holding'; }
}
