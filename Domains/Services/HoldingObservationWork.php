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
    /** Called under the holding locks when registration or transfer becomes active. Never replaces existing work. */
    public static function queueInitial(Domain $d, \DateTimeImmutable $now): void
    {
        if ($d->status!=='active'||!Schema::hasTable('domains_observation_work')) { return; }
        DB::table('domains_observation_work')->insertOrIgnore(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,
            'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>self::phase($d),'due_at'=>$now->getTimestamp(),
            'safe_code'=>self::firstRecordRead($d)?self::INITIAL_READ:null]);
    }
    /**
     * What the current read waits for, and when it is due. 'first': the first scheduled read has not finished (never tried, resumed
     * or given a result, or leased on its first try). 'retry': a failed or recovered read tries again. 'next': the next routine read,
     * daily or a follow-up. A closed record gate reads no records, so it has no read for a held family to wait for.
     * @return array{wait:'first'|'retry'|'next',due:int}|null
     */
    public static function nextRead(Domain $d): ?array
    {
        if (!Schema::hasTable('domains_observation_work')) { return null; }
        $account=Account::where('server_id',$d->server_id)->first();
        if(!$account||!$account->processing_enabled||$account->account_fingerprint!==$d->account_fingerprint
            ||!\App\Models\Server::whereKey($d->server_id)->where('enabled',true)->where('extension','Domains')->exists()){return null;}
        try {
            $binding=AccountBindings::binding($account);
            if((TestCatalogue::testDeployment($binding)||EnomLive::deployment($binding))
                &&!EnomTestManagement::accepted($binding)){return null;}
        } catch (\Throwable) { return null; }
        $job=self::current($d,true)->orderByDesc('id')->first();
        if ($job===null||$job->safe_code==='records_disabled') { return null; }
        $first=in_array($job->safe_code,[null,self::INITIAL_READ],true)&&(int)$job->resumes===0
            &&((int)$job->tries===0||((int)$job->tries===1&&(int)$job->lease_until>app(Clock::class)->now()->getTimestamp()));
        $retry=in_array($job->safe_code,[null,self::INITIAL_READ],true)||in_array($job->safe_code,HoldingReadRetry::TRANSIENT,true);
        return ['wait'=>$first?'first':($retry?'retry':'next'),'due'=>(int)$job->due_at];
    }
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
    /** A live lease means a scheduled or manual registrar read for this holding is running now. */
    public static function leased(Domain $d, \DateTimeImmutable $now): bool
    { return Schema::hasTable('domains_observation_work')&&self::current($d,true)->where('lease_until','>',$now->getTimestamp())->exists(); }
    /**
     * Manual Sync now takes the same lease the scheduler claims, so the two never read one holding at once.
     * Returns the work id and claim, or null while another read holds the lease. Tries and due time stay with the scheduler.
     */
    public static function claimManual(Domain $d, \DateTimeImmutable $now, int $seconds): ?array
    {
        if (!Schema::hasTable('domains_observation_work')) { return [0,'']; }
        return DB::transaction(function()use($d,$now,$seconds): ?array {
            self::lockAccount((int)$d->server_id);
            self::queueInitial($d,$now);
            $job=self::current($d)->lockForUpdate()->first();
            if (!$job) { return [0,'']; }
            if ($job->lease_until>$now->getTimestamp()) { return null; }
            $claim=bin2hex(random_bytes(16));
            DB::table('domains_observation_work')->where('id',$job->id)->update(['claim'=>$claim,'lease_until'=>$now->getTimestamp()+$seconds]);
            return [(int)$job->id,$claim];
        });
    }
    /** True while this manual claim still owns a live lease on unchanged work. */
    public static function ownsManual(int $id, string $claim, \DateTimeImmutable $now): bool
    {
        if ($claim==='') { return true; }
        $q=DB::table('domains_observation_work')->where('id',$id)->where('claim',$claim)->where('lease_until','>',$now->getTimestamp());
        if (DB::transactionLevel()===0) { return $q->exists(); }
        $server=DB::table('domains_observation_work')->where('id',$id)->value('server_id');
        if ($server===null) { return false; }
        self::lockAccount((int)$server);
        return $q->lockForUpdate()->exists();
    }
    /** Server, then account, then work rows: the order scheduler discovery and its lease check use. */
    private static function lockAccount(int $server): void
    { \App\Models\Server::whereKey($server)->lockForUpdate()->first(); Account::where('server_id',$server)->lockForUpdate()->first(); }
    /** Releases only this manual lease; a scheduler claim taken after expiry keeps its own. */
    public static function releaseManual(int $id, string $claim): void
    { if ($claim!=='') { DB::table('domains_observation_work')->where('id',$id)->where('claim',$claim)->update(['claim'=>null,'lease_until'=>0]); } }
    public function __construct(private readonly DomainObservation $observer, private readonly Clock $clock) {}
    /** Shared reviewed recovery; the selected scheduler supplies its captured generation guard. */
    public function resume(int $id, string $reason, StaffOperationReview|\Paymenter\Extensions\Servers\Domains\Services\StaffSyncReview|null $review, \Closure $current): void
    {
        OperationClaims::outside(); if (!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$reason)) { throw new \InvalidArgumentException('Safe reason required.'); }
        $seed=Domain::findOrFail(DB::table('domains_observation_work')->where('id',$id)->value('domain_id'));
        DB::transaction(function () use ($id,$reason,$seed,$review,$current): void {
            $l=HoldingLocks::take($seed,$review?->actorId); $current((int)$seed->server_id); $review?->check($l); $d=$l['domain']; $q=DB::table('domains_observation_work')->where('id',$id); $job=(clone $q)->lockForUpdate()->firstOrFail();
            if ($job->domain_id!=$d->id||$job->server_id!=$d->server_id||$job->phase!==self::phase($d)||(int)$job->generation!==$d->authority_generation||$job->account_fingerprint!==$d->account_fingerprint||$job->lease_until>$this->clock->now()->getTimestamp()||$job->resumes>=20) { throw new \DomainException('Read resume unavailable.'); }
            if ($blocked=ObservationWork::resumeBlocked($d)) { throw new StaffReviewRefused($blocked); }
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
        $credential=null;
        try{return $this->observer->run((int)$job->domain_id,(int)$job->generation,$job->account_fingerprint,readRecords:true,current:$current,
            completion:function(string $code,?\Closure $credentials)use($job,$current,&$credential):string{return $this->finish($job,$code,$current,$credentials,$credential);},
            attempted:static function(?string $revision)use(&$credential):void{$credential=$revision;});}
        catch(ReadDeferred|EnomTestCallLimit){$code='read_deferred';}
        catch(\Throwable $error){$code=ReadFailure::code($error);}
        return $this->finish($job,$code,$current,credential:$credential);
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
                // Older partial-family jobs stopped at their first failure. Keep their attempts and resume the bounded policy.
                if(HoldingReadRetry::retryableHold($d)){
                    self::current($d)->whereIn('safe_code',['records_incomplete','read_exception','read_no_evidence'])->where('tries','<',HoldingReadRetry::LIMIT)
                        ->where('lease_until','<=',$this->clock->now()->getTimestamp())->update(['safe_code'=>'read_failed']);
                }
                // Legacy healthy ownership-only jobs must not postpone a missing or stale family read. Only a future
                // due time moves forward: an overdue one keeps its age, which claim() uses to stop starvation.
                if($this->observer->readsRecords()&&$d->status==='active'&&ObservationProjection::recordStatus($d,$this->clock->now())['stale']){
                    self::current($d)->where('safe_code','refreshed')->where('lease_until','<=',$this->clock->now()->getTimestamp())
                        ->where('due_at','>',$this->clock->now()->getTimestamp())->update(['due_at'=>$this->clock->now()->getTimestamp()]);
                }
            }
            $q->update(['cursor'=>$rows->count()===25?$rows->last()->id:0]);
        });
    }
    /** Repaired configuration wakes stopped reads once per attempted credential revision. */
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
            // Acquisition completion marks its initial read before discovery. Select those jobs before the bounded
            // window so an older backlog cannot hide them. Due transient retries follow, before routine refreshes.
            // Work overdue by more than ROUTINE_WAIT ranks with initial reads by due time, so a steady stream of new
            // domains or retries can't starve a routine refresh.
            $starved=$now-self::ROUTINE_WAIT;
            $due=$q->orderByRaw("CASE WHEN safe_code = ? OR due_at < ? THEN 0 WHEN safe_code IN (?, ?) THEN 1 ELSE 2 END",[self::INITIAL_READ,$starved,...HoldingReadRetry::TRANSIENT])->orderBy('due_at')->orderBy('id')->limit(self::FIRST_READ_WINDOW)->lockForUpdate()->get(); if ($due->isEmpty()) { return null; }
            $domains=Domain::whereIn('id',$due->pluck('domain_id'))->get()->keyBy('id');
            $oldest=$due->first();
            $job=(int)$oldest->due_at<$starved?$oldest:($due->first(fn($row)=>self::firstRecordRead($domains->get($row->domain_id))) ?? $oldest);
            $d=$domains->get($job->domain_id);
            if($d&&TransferPolling::ownsObservation($d)){DB::table('domains_observation_work')->where('id',$job->id)->delete();return null;}
            if ($d && (!in_array($d->status,['active','registration_pending','transfer_pending'],true)||!ObservationEligibility::allows($d)||$d->account_fingerprint!==$job->account_fingerprint||$d->adoptionPending() || (int)$job->generation!==$d->authority_generation || $job->phase!==self::phase($d))) {
                DB::table('domains_observation_work')->where('id',$job->id)->update(['safe_code'=>'superseded']); return null;
            }

            $job->claim=bin2hex(random_bytes(16));
            DB::table('domains_observation_work')->where('id',$job->id)->update(['claim'=>$job->claim,'lease_until'=>$now+300,'tries'=>$job->tries+1]);
            return $job;
        });
    }
    /** Pending initial read, preserved on local deferral until the registrar returns a result. */
    private const INITIAL_READ='initial_read';
    /** Due rows the scheduler looks through for a first record read. */
    private const FIRST_READ_WINDOW=25;
    /** Seconds a due read may wait behind initial reads and retries before it takes its turn by due time. */
    private const ROUTINE_WAIT=3600;
    /**
     * An active domain with no completed record read yet. Saved history that fails validation (unreadable, a time
     * after now, a sequence ahead of management) is not a first read: it waits its turn like a routine read.
     */
    private static function firstRecordRead(?Domain $d): bool
    {
        if (!$d||$d->status!=='active') { return false; }
        try {
            $saved=$d->observation['records']??null;
            if (!is_array($saved)||($saved['identity']??null)!==ObservationRecords::identity($d)) { return true; }
            $current=ObservationRecords::current($d);
            return $current!==null&&($current['last_complete_at']??null)===null;
        }
        catch (\Illuminate\Contracts\Encryption\DecryptException) { return false; }
    }
    private function finish(object $job, string $code, \Closure $guard, ?\Closure $credentials=null, ?string $credential=null): string
    {
        return DB::transaction(function()use($job,$code,$guard,$credentials,$credential):string {
            try { $guard(); if($credentials){$credentials();} }
            catch (\DomainException) {
                // Draining a stopped generation may release only its own lease, never reschedule it.
                DB::table('domains_observation_work')->where('id',$job->id)->where('claim',$job->claim)
                    ->update(['claim'=>null,'lease_until'=>0]); return 'read_deferred';
            }
            $this->finishCurrent($job,$code,$credential); return $code==='read_deferred'?$code:(DB::table('domains_observation_work')->where('id',$job->id)->value('safe_code')??$code);
        });
    }
    private function finishCurrent(object $job, string $code, ?string $credential): void
    {
        $now=$this->clock->now()->getTimestamp();
        if ($code==='read_deferred') {
            DB::table('domains_observation_work')->where('id',$job->id)->where('claim',$job->claim)->update([
                'claim'=>null,'lease_until'=>0,'tries'=>$job->tries,'safe_code'=>$job->safe_code,'due_at'=>$now+60]); return;
        }
        $d=Domain::findOrFail($job->domain_id); $tries=$job->tries+1;
        $code=HoldingReadRetry::result($d,$code);
        $code=HoldingReadRetry::scheduled($d,$code,$tries);
        if(in_array($code,HoldingReadRetry::RECOVERABLE,true)){
            $p=$d->observation??[];$prior=$p['read_retry']??[];$verifiedCredential=null;
            try{$a=Account::where('server_id',$d->server_id)->sole();$verifiedCredential=app(AccountBindings::class)->holdingCredentialRevision(AccountBindings::binding($a));}catch(\Throwable){}
            // A broken or replaced configuration must remain eligible for recovery after repair.
            $credential=$credential!==null&&$credential===$verifiedCredential?$credential:null;
            $p['read_retry']=['work_id'=>(int)$job->id,'credential'=>$credential,'at'=>$this->clock->now()->format(DATE_ATOM),
                'auto_resumed'=>($prior['work_id']??null)===$job->id?($prior['auto_resumed']??false):false];
            $d->update(['observation'=>$p]);
        }
        $pending=$code==='healthy_pending'; $start=$d->operations()->where('action','transfer')->where('authority_generation',$d->authority_generation)->orderBy('id')->get()->first(fn($o)=>($o->attempt['seal']??null)==='sealed'&&in_array($o->state,['pending','uncertain','held'],true))?->started_at;
        $startAt=$start instanceof \DateTimeInterface?$start->getTimestamp():($start===null?null:(new \DateTimeImmutable($start))->getTimestamp());
        $within=$pending&&$startAt!==null&&$now<$startAt+7*86400;
        // A closed record gate still completed the ordinary core read. Keep its own code so stale-family discovery cannot wake it early.
        $coreRead=in_array($code,['refreshed','records_disabled'],true);
        $exhausted=$pending?(!$within||$tries>=28):(!$coreRead&&$tries>=5);
        $follow=($d->observation['followup']['state']??null)==='pending';
        DB::table('domains_observation_work')->where('id',$job->id)->where('claim',$job->claim)->update([
            'claim'=>null,'lease_until'=>0,'tries'=>$coreRead?0:$tries,
            'due_at'=>$now+($pending?21600:($coreRead?($code==='refreshed'&&$follow?300:86400):HoldingReadRetry::delay($tries))),
            'safe_code'=>in_array($code,HoldingReadRetry::STOPPED,true)?$code:($exhausted?'budget_exhausted':$code)]);
    }
    public static function phase(Domain $d): string
    { $follow=$d->observation['followup']??[]; return isset($follow['operation_key'])&&($follow['generation']??null)===$d->authority_generation?hash('sha256',$follow['operation_key']):'holding'; }
}
