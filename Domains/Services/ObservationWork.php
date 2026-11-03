<?php
// Enom444f31c bounded refresh/follow-up; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueFreshness, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
/** No installed schedule or writer. One turn/account, bounded discovery and one read job/turn. */
final class ObservationWork
{
    public static function stopped(Domain $d): bool { return HoldingObservationWork::stopped($d); }
    public static function stoppedIds(\Illuminate\Support\Collection $domains): array { return HoldingObservationWork::stoppedIds($domains); }
    public static function currentId(Domain $d): ?int { return HoldingObservationWork::currentId($d); }
    public static function discardUnsent(Domain $d, \DateTimeImmutable $now, ?\Illuminate\Support\Collection $operations=null): bool
    { return HoldingObservationWork::discardUnsent($d,$now,$operations); }
    public function __construct(private readonly DomainObservation $observer, private readonly Clock $clock,
        private readonly ReadLimiter $budget, private readonly ?CatalogueSync $catalogue=null) {}
    public function enroll(int $server): void
    {
        OperationClaims::outside(); $generation=UpgradeGate::legacy();
        DB::transaction(function () use ($server,$generation): void {
            UpgradeGate::lockLegacy($generation,$server);
            $a=Account::where('server_id',$server)->lockForUpdate()->firstOrFail();
            DB::table('domains_observation_queues')->insertOrIgnore(['server_id'=>$server,'account_fingerprint'=>$a->account_fingerprint]);
        });
    }
    public function tick(int $accounts=8): array
    {
        OperationClaims::outside(); $generation=UpgradeGate::legacy(); if ($accounts<1||$accounts>32) { throw new \InvalidArgumentException('Invalid account batch.'); }
        $queues=DB::transaction(function () use ($accounts,$generation): array {
            UpgradeGate::lockLegacy($generation);
            $q=DB::table('domains_observation_queues'); $rows=(clone $q)->orderBy('turn')->orderBy('server_id')->limit($accounts)->lockForUpdate()->get();
            $turn=(int)$q->max('turn');
            foreach ($rows as $row) { (clone $q)->where('id',$row->id)->update(['turn'=>++$turn]); }
            return $rows->all();
        });
        $summary=[];
        foreach ($queues as $queue) {
            $queue->upgradeGeneration=$generation;
            try {
                $guard=static function(?int $server=null)use($generation):void {
                    if(DB::transactionLevel()>0){UpgradeGate::lockLegacy($generation,$server);}else{UpgradeGate::legacy($generation);}
                };
                $catalogue=$this->catalogue?function()use($queue):void {
                    try{$this->catalogue($queue);}
                    catch(ReadDeferred|EnomTestCallLimit){}
                    catch(\Throwable $e){
                        try{$this->catalogueFailure($queue,ReadFailure::code($e));}catch(\Throwable){}
                        try{logger()->warning('Domains catalogue check failed.',['server_id'=>(int)$queue->server_id,'account_fingerprint'=>$queue->account_fingerprint,'safe_code'=>ReadFailure::code($e)]);}catch(\Throwable){}
                    }
                }:null;
                $summary[$queue->server_id]=(new HoldingObservationWork($this->observer,$this->clock))->turn($queue,$guard,$catalogue);
            } catch (\Throwable $e) {
                try { logger()->warning('Domains observation account failed.', ['server_id'=>(int)$queue->server_id,'account_fingerprint'=>$queue->account_fingerprint,'safe_code'=>ReadFailure::code($e)]); }
                catch (\Throwable) { /* Logging cannot interrupt other account turns. */ }
                $summary[$queue->server_id]=ReadFailure::code($e);
            }
        }
        return $summary;
    }
    /** Internal audited read-only resume. Fresh staff policy/permission belongs36. */
    public function resume(int $id, string $reason, StaffOperationReview|StaffSyncReview|null $review = null): void
    {
        OperationClaims::outside(); if (!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$reason)) { throw new \InvalidArgumentException('Safe reason required.'); }
        $seed=Domain::findOrFail(DB::table('domains_observation_work')->where('id',$id)->value('domain_id'));
        DB::transaction(function () use ($id,$reason,$seed,$review): void {
            $l=HoldingLocks::take($seed,$review?->actorId); UpgradeGate::legacy(); $review?->check($l); $d=$l['domain']; $q=DB::table('domains_observation_work')->where('id',$id); $job=(clone $q)->lockForUpdate()->firstOrFail();
            if ($job->domain_id!=$d->id||$job->server_id!=$d->server_id||$job->phase!==self::phase($d)||(int)$job->generation!==$d->authority_generation||$job->account_fingerprint!==$d->account_fingerprint||$job->lease_until>$this->clock->now()->getTimestamp()||$job->resumes>=20) { throw new \DomainException('Read resume unavailable.'); }
            $p=$d->observation??[]; unset($p['record_hold']); $p['resumptions']=[...($p['resumptions']??[]),['work_id'=>$id,'reason'=>$reason,'at'=>$this->clock->now()->format(DATE_ATOM)]];
            $d->update(['observation'=>$p]); $q->update(['resumes'=>$job->resumes+1,'tries'=>0,'claim'=>null,'safe_code'=>null,'due_at'=>$this->clock->now()->getTimestamp()]); $review?->record($l);
        });
    }
    private static function phase(Domain $d): string { return HoldingObservationWork::phase($d); }
    /** Internal bounded catalogue failure resume; installed staff policy remains36. */
    public function resumeCatalogue(int $server, string $reason): void
    {
        OperationClaims::outside(); if (!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$reason)) { throw new \InvalidArgumentException('Safe reason required.'); }
        DB::transaction(function () use ($server,$reason): void {
            UpgradeGate::lockLegacy(null,$server);
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
            UpgradeGate::lockLegacy($queue->upgradeGeneration,(int)$queue->server_id);
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
        DB::transaction(function()use($queue,$claim,$ok,$deferred,$code,$cursor,$now,$fresh,$keys):void {
            try { UpgradeGate::lockLegacy($queue->upgradeGeneration,(int)$queue->server_id); }
            catch (\DomainException) {
                DB::table('domains_observation_queues')->where('id',$queue->id)->where('catalogue_claim',$claim->catalogue_claim)
                    ->update(['catalogue_claim'=>null,'catalogue_lease'=>0]); return;
            }
            DB::table('domains_observation_queues')->where('id',$queue->id)->where('catalogue_claim',$claim->catalogue_claim)->update([
                'catalogue_claim'=>null,'catalogue_lease'=>0,'catalogue_hold'=>$ok||$deferred?null:$code,
                'catalogue_cursor'=>$cursor+($ok?1:0),'catalogue_due'=>$now+($deferred?60:max(1,intdiv($fresh->intervalSeconds,count($keys))))]);
        });
    }
    private function catalogueFailure(object $queue, string $code, ?string $claim=null): void
    {
        $code=ReadFailure::safe($code);
        DB::transaction(function () use ($queue,$code,$claim): void {
            UpgradeGate::lockLegacy($queue->upgradeGeneration,(int)$queue->server_id);
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
