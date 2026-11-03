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
    private const STOPPED=['read_unavailable','budget_exhausted','superseded',...ReadFailure::CODES];
    /** Only the current binding, generation and follow-up phase can stop this holding. */
    public static function stopped(Domain $d): bool
    {
        if (!Schema::hasTable('domains_observation_work')) { return false; }
        return self::current($d,true)->whereIn('safe_code',self::STOPPED)->exists() && ObservationEligibility::allows($d);
    }
    /** Batched stopped() for a page of domains: the ids, as keys, of those whose current work stopped. */
    public static function stoppedIds(\Illuminate\Support\Collection $domains): array
    {
        if ($domains->isEmpty()||!Schema::hasTable('domains_observation_work')) { return []; }
        $rows=DB::table('domains_observation_work')->whereIn('domain_id',$domains->pluck('id'))->whereIn('safe_code',self::STOPPED)
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
    public function turn(object $queue, \Closure $guard, ?\Closure $catalogue = null): string
    {
        $guard((int)$queue->server_id);
        $account=Account::where('server_id',$queue->server_id)->firstOrFail();
        if($account->account_fingerprint!==$queue->account_fingerprint){throw new \DomainException('Observation queue binding changed.');}
        $b=CatalogueAcceptances::binding($account);
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
        try{$code=$this->observer->run((int)$job->domain_id,(int)$job->generation,$job->account_fingerprint,readRecords:false,current:$current);}
        catch(ReadDeferred|EnomTestCallLimit){$code='read_deferred';}
        catch(\Throwable $error){$code=ReadFailure::code($error);}
        $this->finish($job,$code,$guard); return $code;
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
            }
            $q->update(['cursor'=>$rows->count()===25?$rows->last()->id:0]);
        });
    }
    private function claim(object $queue, \Closure $guard): ?object
    {
        return DB::transaction(function () use ($queue,$guard): ?object {
            $guard((int)$queue->server_id);
            $now=$this->clock->now()->getTimestamp(); $q=DB::table('domains_observation_work')->where('server_id',$queue->server_id)
                ->where('account_fingerprint',$queue->account_fingerprint)->where('due_at','<=',$now)->where('tries','<',28)->where('lease_until','<=',$now)
                ->where(function($q){$q->whereNull('safe_code')->orWhereNotIn('safe_code',self::STOPPED);});
            $job=$q->orderBy('due_at')->orderBy('id')->lockForUpdate()->first(); if (!$job) { return null; }
            $d=Domain::find($job->domain_id);
            if($d&&TransferPolling::ownsObservation($d)){DB::table('domains_observation_work')->where('id',$job->id)->delete();return null;}
            if ($d && ($d->adoptionPending() || (int)$job->generation!==$d->authority_generation || $job->phase!==self::phase($d))) {
                DB::table('domains_observation_work')->where('id',$job->id)->update(['safe_code'=>'superseded']); return null;
            }
            
            $job->claim=bin2hex(random_bytes(16));
            DB::table('domains_observation_work')->where('id',$job->id)->update(['claim'=>$job->claim,'lease_until'=>$now+300,'tries'=>$job->tries+1]);
            return $job;
        });
    }
    private function finish(object $job, string $code, \Closure $guard): void
    {
        DB::transaction(function()use($job,$code,$guard):void {
            try { $guard((int)$job->server_id); }
            catch (\DomainException) {
                // Draining a stopped generation may release only its own lease, never reschedule it.
                DB::table('domains_observation_work')->where('id',$job->id)->where('claim',$job->claim)
                    ->update(['claim'=>null,'lease_until'=>0]); return;
            }
            $this->finishCurrent($job,$code);
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
        $pending=$code==='healthy_pending'; $start=$d->operations()->where('action','transfer')->where('authority_generation',$d->authority_generation)->orderBy('id')->get()->first(fn($o)=>($o->attempt['seal']??null)==='sealed'&&in_array($o->state,['pending','uncertain','held'],true))?->started_at;
        $startAt=$start instanceof \DateTimeInterface?$start->getTimestamp():($start===null?null:(new \DateTimeImmutable($start))->getTimestamp());
        $within=$pending&&$startAt!==null&&$now<$startAt+7*86400;
        $exhausted=$pending?(!$within||$tries>=28):($code!=='refreshed'&&$tries>=5);
        $follow=($d->observation['followup']['state']??null)==='pending';
        DB::table('domains_observation_work')->where('id',$job->id)->where('claim',$job->claim)->update([
            'claim'=>null,'lease_until'=>0,'tries'=>$code==='refreshed'?0:$tries,
            'due_at'=>$now+($pending?21600:($code==='refreshed'?($follow?300:86400):900)),
            'safe_code'=>in_array($code,ReadFailure::CODES,true)?$code:($exhausted?'budget_exhausted':$code)]);
    }
    public static function phase(Domain $d): string
    { $follow=$d->observation['followup']??[]; return isset($follow['operation_key'])&&($follow['generation']??null)===$d->authority_generation?hash('sha256',$follow['operation_key']):'holding'; }
}
