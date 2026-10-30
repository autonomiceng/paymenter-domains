<?php
// Enom444f31c bounded refresh/follow-up; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueFreshness, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
/** No installed schedule or writer. One turn/account, bounded discovery and one read job/turn. */
final class ObservationWork
{
    private const STOPPED=['read_unavailable','budget_exhausted','superseded',...ReadFailure::CODES];
    /** Only the current binding, generation and follow-up phase can stop this holding. */
    public static function stopped(Domain $d): bool
    {
        if (!Schema::hasTable('domains_observation_work')) { return false; }
        return self::current($d)->whereIn('safe_code',self::STOPPED)->exists() && ObservationEligibility::allows($d);
    }
    /** Batched stopped() for a page of domains: the ids, as keys, of those whose current work stopped. */
    public static function stoppedIds(\Illuminate\Support\Collection $domains): array
    {
        if ($domains->isEmpty()||!Schema::hasTable('domains_observation_work')) { return []; }
        $rows=DB::table('domains_observation_work')->whereIn('domain_id',$domains->pluck('id'))->whereIn('safe_code',self::STOPPED)
            ->get(['domain_id','server_id','account_fingerprint','generation','phase']);
        $stopped=$domains->filter(fn(Domain $d)=>$rows->contains(fn($r)=>(int)$r->domain_id===(int)$d->id&&(int)$r->server_id===(int)$d->server_id
            &&$r->account_fingerprint===$d->account_fingerprint&&(int)$r->generation===(int)$d->authority_generation&&$r->phase===self::phase($d)))
            ->values();
        $pending=$stopped->whereIn('status',['registration_pending','transfer_pending']);
        $operations=$pending->isEmpty()?collect():Operation::whereIn('domain_id',$pending->pluck('id'))->whereIn('action',['register','transfer'])->get()->groupBy('domain_id');
        return $stopped->filter(fn(Domain $d)=>ObservationEligibility::allows($d,$operations->get($d->id,collect())))->pluck('id')->flip()->all();
    }
    /** Advisory selection only; resume revalidates the locked holding and job. */
    public static function currentId(Domain $d): ?int
    {
        $id=self::current($d)->orderByDesc('id')->value('id');
        return $id===null?null:(int)$id;
    }
    private static function current(Domain $d): \Illuminate\Database\Query\Builder
    {
        return DB::table('domains_observation_work')->where('domain_id',$d->id)->where('server_id',$d->server_id)
            ->where('account_fingerprint',$d->account_fingerprint)->where('generation',$d->authority_generation)
            ->where('phase',self::phase($d));
    }
    /** Under the account lock, retire obsolete idle work before unsent proof can be lost. Returns true if unsent. */
    public static function discardUnsent(Domain $d, \DateTimeImmutable $now, ?\Illuminate\Support\Collection $operations=null): bool
    {
        if (ObservationEligibility::allows($d,$operations)) { return false; }
        if (Schema::hasTable('domains_observation_work')) { self::current($d)->where('lease_until','<=',$now->getTimestamp())->delete(); }
        return true;
    }
    public function __construct(private readonly DomainObservation $observer, private readonly Clock $clock,
        private readonly ReadLimiter $budget, private readonly ?CatalogueSync $catalogue=null) {}
    public function enroll(int $server): void
    {
        OperationClaims::outside();
        DB::transaction(function () use ($server): void {
            $a=Account::where('server_id',$server)->lockForUpdate()->firstOrFail();
            DB::table('domains_observation_queues')->insertOrIgnore(['server_id'=>$server,'account_fingerprint'=>$a->account_fingerprint]);
        });
    }
    public function tick(int $accounts=8): array
    {
        OperationClaims::outside(); if ($accounts<1||$accounts>32) { throw new \InvalidArgumentException('Invalid account batch.'); }
        $queues=DB::transaction(function () use ($accounts): array {
            $q=DB::table('domains_observation_queues'); $rows=(clone $q)->orderBy('turn')->orderBy('server_id')->limit($accounts)->lockForUpdate()->get();
            $turn=(int)$q->max('turn');
            foreach ($rows as $row) { (clone $q)->where('id',$row->id)->update(['turn'=>++$turn]); }
            return $rows->all();
        });
        $summary=[];
        foreach ($queues as $queue) {
            try {
                $account=Account::where('server_id',$queue->server_id)->first();
                if($account&&$account->account_fingerprint===$queue->account_fingerprint
                    &&!EnomTestManagement::accepted(CatalogueAcceptances::binding($account))
                    &&(EnomTestRegistration::enabled(CatalogueAcceptances::binding($account))||EnomTestRenewal::enabled(CatalogueAcceptances::binding($account))||EnomTestTransfer::enabled(CatalogueAcceptances::binding($account)))){$summary[$queue->server_id]='read_deferred';continue;}
                $this->discover($queue); if ($this->catalogue) {
                    try { $this->catalogue($queue); }
                    catch (ReadDeferred|EnomTestCallLimit) { /* Local refusal does not invalidate saved evidence. */ }
                    catch (\Throwable $e) {
                        try { $this->catalogueFailure($queue,ReadFailure::code($e)); }
                        catch (\Throwable) { /* Diagnostic persistence cannot prevent independent domain reads. */ }
                        try { logger()->warning('Domains catalogue check failed.', ['server_id'=>(int)$queue->server_id,'account_fingerprint'=>$queue->account_fingerprint,'safe_code'=>ReadFailure::code($e)]); }
                        catch (\Throwable) { /* Logging cannot prevent this account's observation work. */ }
                    }
                }
                $job=$this->claim($queue); if (!$job) { $summary[$queue->server_id]='idle'; continue; }
                try { $code=$this->observer->run((int)$job->domain_id,(int)$job->generation,$job->account_fingerprint); }
                catch (ReadDeferred|EnomTestCallLimit) { $code='read_deferred'; }
                catch (\Throwable $error) { $code=ReadFailure::code($error); }
                $this->finish($job,$code); $summary[$queue->server_id]=$code;
            } catch (\Throwable $e) {
                try { logger()->warning('Domains observation account failed.', ['server_id'=>(int)$queue->server_id,'account_fingerprint'=>$queue->account_fingerprint,'safe_code'=>ReadFailure::code($e)]); }
                catch (\Throwable) { /* Logging cannot interrupt other account turns. */ }
                $summary[$queue->server_id]=ReadFailure::code($e);
            }
        }
        return $summary;
    }
    private function discover(object $queue): void
    {
        DB::transaction(function () use ($queue): void {
            $a=Account::where('server_id',$queue->server_id)->lockForUpdate()->firstOrFail();
            if ($a->account_fingerprint!==$queue->account_fingerprint) { throw new \DomainException('Observation queue binding changed.'); }
            $q=DB::table('domains_observation_queues')->where('id',$queue->id); $current=(clone $q)->lockForUpdate()->firstOrFail();
            $rows=Domain::where('server_id',$a->server_id)->where('id','>',$current->cursor)->orderBy('id')->limit(25)->get();
            foreach ($rows as $d) {
                if ($d->adoptionPending() || !in_array($d->status,['active','registration_pending','transfer_pending'],true)||$d->account_fingerprint!==$a->account_fingerprint) { continue; }
                if (self::discardUnsent($d,$this->clock->now())) { continue; }
                DB::table('domains_observation_work')->insertOrIgnore(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,
                    'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>self::phase($d),'due_at'=>$this->clock->now()->getTimestamp()]);
            }
            $q->update(['cursor'=>$rows->count()===25?$rows->last()->id:0]);
        });
    }
    private function claim(object $queue): ?object
    {
        return DB::transaction(function () use ($queue): ?object {
            $now=$this->clock->now()->getTimestamp(); $q=DB::table('domains_observation_work')->where('server_id',$queue->server_id)
                ->where('account_fingerprint',$queue->account_fingerprint)->where('due_at','<=',$now)->where('tries','<',28)->where('lease_until','<=',$now)
                ->where(function($q){$q->whereNull('safe_code')->orWhereNotIn('safe_code',self::STOPPED);});
            $job=$q->orderBy('due_at')->orderBy('id')->lockForUpdate()->first(); if (!$job) { return null; }
            $d=Domain::find($job->domain_id);
            if ($d && ($d->adoptionPending() || (int)$job->generation!==$d->authority_generation || $job->phase!==self::phase($d))) {
                DB::table('domains_observation_work')->where('id',$job->id)->update(['safe_code'=>'superseded']); return null;
            }
            $job->claim=bin2hex(random_bytes(16));
            DB::table('domains_observation_work')->where('id',$job->id)->update(['claim'=>$job->claim,'lease_until'=>$now+300,'tries'=>$job->tries+1]);
            return $job;
        });
    }
    private function finish(object $job, string $code): void
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
    /** Internal audited read-only resume. Fresh staff policy/permission belongs36. */
    public function resume(int $id, string $reason, ?StaffOperationReview $review = null): void
    {
        OperationClaims::outside(); if (!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$reason)) { throw new \InvalidArgumentException('Safe reason required.'); }
        $seed=Domain::findOrFail(DB::table('domains_observation_work')->where('id',$id)->value('domain_id'));
        DB::transaction(function () use ($id,$reason,$seed,$review): void {
            $l=HoldingLocks::take($seed,$review?->actorId); $review?->check($l); $d=$l['domain']; $q=DB::table('domains_observation_work')->where('id',$id); $job=(clone $q)->lockForUpdate()->firstOrFail();
            if ($job->domain_id!=$d->id||$job->server_id!=$d->server_id||$job->phase!==self::phase($d)||(int)$job->generation!==$d->authority_generation||$job->account_fingerprint!==$d->account_fingerprint||$job->lease_until>$this->clock->now()->getTimestamp()||$job->resumes>=20) { throw new \DomainException('Read resume unavailable.'); }
            $p=$d->observation??[]; unset($p['record_hold']); $p['resumptions']=[...($p['resumptions']??[]),['work_id'=>$id,'reason'=>$reason,'at'=>$this->clock->now()->format(DATE_ATOM)]];
            $d->update(['observation'=>$p]); $q->update(['resumes'=>$job->resumes+1,'tries'=>0,'claim'=>null,'safe_code'=>null,'due_at'=>$this->clock->now()->getTimestamp()]); $review?->record($l);
        });
    }
    private static function phase(Domain $d): string
    { $follow=$d->observation['followup']??[]; return isset($follow['operation_key'])&&($follow['generation']??null)===$d->authority_generation?hash('sha256',$follow['operation_key']):'holding'; }
    /** Internal bounded catalogue failure resume; installed staff policy remains36. */
    public function resumeCatalogue(int $server, string $reason): void
    {
        OperationClaims::outside(); if (!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$reason)) { throw new \InvalidArgumentException('Safe reason required.'); }
        DB::transaction(function () use ($server,$reason): void {
            $q=DB::table('domains_observation_queues')->where('server_id',$server); $row=(clone $q)->lockForUpdate()->firstOrFail();
            if ($row->catalogue_lease>$this->clock->now()->getTimestamp()||$row->catalogue_resumes>=20) { throw new \DomainException('Catalogue resume unavailable.'); }
            $audit=json_decode($row->catalogue_audit??'[]',true,8,JSON_THROW_ON_ERROR);
            $audit[]=['reason'=>$reason,'at'=>$this->clock->now()->format(DATE_ATOM)];
            $q->update(['catalogue_resumes'=>$row->catalogue_resumes+1,'catalogue_hold'=>null,'catalogue_due'=>$this->clock->now()->getTimestamp(),'catalogue_audit'=>json_encode($audit,JSON_THROW_ON_ERROR)]);
        });
    }
    private function catalogue(object $queue): void
    {
        $now=$this->clock->now()->getTimestamp();
        $current=DB::table('domains_observation_queues')->where('id',$queue->id)->firstOrFail();
        if ($current->catalogue_due>$now||$current->catalogue_hold!==null||$current->catalogue_lease>$now) { return; }
        $a=Account::where('server_id',$queue->server_id)->firstOrFail();
        if ($a->account_fingerprint!==$queue->account_fingerprint) { throw new ReadDeferred('Observation queue binding changed.'); }
        $b=CatalogueAcceptances::binding($a); if (TestCatalogue::testDeployment($b)||EnomLive::deployment($b)) { return; } $accepted=CatalogueAcceptances::load($b); $keys=array_keys($accepted->endings);
        if (!$keys) { return; } $fresh=new CatalogueFreshness; $now=$this->clock->now()->getTimestamp();
        $claim=DB::transaction(function () use ($queue,$now): ?object {
            $q=DB::table('domains_observation_queues')->where('id',$queue->id); $row=(clone $q)->lockForUpdate()->firstOrFail();
            if ($row->catalogue_due>$now||$row->catalogue_hold!==null||$row->catalogue_lease>$now) { return null; }
            // Crash after sending holds until explicit resume; an expired lease never automatically replays failure.
            $row->catalogue_claim=bin2hex(random_bytes(16));
            $q->update(['catalogue_claim'=>$row->catalogue_claim,'catalogue_lease'=>$now+300,'catalogue_hold'=>'catalogue_interrupted']); return $row;
        });
        if (!$claim) { return; } $cursor=$claim->catalogue_cursor%count($keys); $deferred=false; $ok=false; $code='catalogue_evidence_missing';
        try {
            $ctx=new RequestContext($b,RequestOrigin::Background,$this->clock->now()->modify('+250 seconds'),new RequestBudget(25),$this->clock,$this->budget,stopOnReadFailure:true);
            $summary=$this->catalogue->refresh($b->serverId,$ctx,$fresh,[$keys[$cursor]]);
            $ok=$summary['failed']===[]&&$summary['synced']+count($summary['quarantined']??[])===1;
            $codes=$summary['failure_codes']??[]; $code=reset($codes)?:$code;
        } catch (ReadDeferred|EnomTestCallLimit) { $deferred=true; } catch (\Throwable $error) { $code=ReadFailure::code($error); }
        if (!$ok&&!$deferred) {
            try { $this->catalogueFailure($queue,$code,$claim->catalogue_claim); }
            catch (\Throwable) { /* Claim completion cannot depend on diagnostic persistence. */ }
        }
        DB::table('domains_observation_queues')->where('id',$queue->id)->where('catalogue_claim',$claim->catalogue_claim)->update([
            'catalogue_claim'=>null,'catalogue_lease'=>0,'catalogue_hold'=>$ok||$deferred?null:$code,
            'catalogue_cursor'=>$cursor+($ok?1:0),'catalogue_due'=>$now+($deferred?60:max(1,intdiv($fresh->intervalSeconds,count($keys))))]);
    }
    private function catalogueFailure(object $queue, string $code, ?string $claim=null): void
    {
        $code=ReadFailure::safe($code);
        DB::transaction(function () use ($queue,$code,$claim): void {
            $q=DB::table('domains_observation_queues')->where('id',$queue->id)->where('account_fingerprint',$queue->account_fingerprint);
            if ($claim!==null) { $q->where('catalogue_claim',$claim); }
            $row=(clone $q)->lockForUpdate()->first(); if (!$row) { return; }
            $audit=json_decode($row->catalogue_audit??'[]',true,8,JSON_THROW_ON_ERROR);
            $last=end($audit);
            if ($claim===null && ($last['code']??null)===$code && ($last['account_fingerprint']??null)===$queue->account_fingerprint) { return; }
            $audit[]=['code'=>$code,'server_id'=>(int)$queue->server_id,'account_fingerprint'=>$queue->account_fingerprint,'at'=>$this->clock->now()->format(DATE_ATOM)];
            $values=['catalogue_audit'=>json_encode($audit,JSON_THROW_ON_ERROR)];
            if ($claim!==null) { $values['catalogue_hold']=$code; }
            $q->update($values);
        });
    }
}
