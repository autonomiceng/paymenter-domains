<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Explicit scheduler seams; no native cron callback or network is registered here. */
final readonly class ManagedPublicReconciliation
{
    public function __construct(private ManagedPublicSignals $signals) {}
    /** One bounded page/tick; completion schedules the next daily pass. */
    public function sweep(int $now,int $limit=25): int
    {
        if (!$this->signals->enabled() || DB::transactionLevel()!==0 || $now<0 || $limit<1 || $limit>100) { return 0; }
        PublicDnsQuota::seed($now);$q=DB::table(PublicDnsQuota::TABLE)->where('id',1);$row=$q->first();
        if ((int)$row->sweep_due_at>$now) { return 0; }
        $domains=Domain::where('id','>',$row->sweep_cursor)->orderBy('id')->limit($limit)->get();
        foreach($domains as$d) { $this->signals->enqueue((int)$d->id,(int)$d->user_id,$now); }
        // Deleted native/ledger rows must not leave immortal jobs. Retained normal history is unchanged.
        DB::table(ManagedPublicWorkSchema::TABLE)->whereNull('retired_at')->whereNotExists(fn($q)=>$q->selectRaw('1')->from('domains_domains as d')
            ->whereColumn('d.id',ManagedPublicWorkSchema::TABLE.'.domain_id'))->update(['retired_at'=>$now,'claim'=>null,'lease_until'=>null,'safe_code'=>'target_changed']);
        // Optimistic cursor prevents a concurrent older page from rewinding a completed pass.
        $q->where('sweep_cursor',$row->sweep_cursor)->where('sweep_due_at',$row->sweep_due_at)->update($domains->count()<$limit?
            ['sweep_cursor'=>0,'sweep_due_at'=>$now+86400]:['sweep_cursor'=>$domains->last()->id]);
        return $domains->count();
    }
    /** Shared dispatch/health eligibility; dormant or obsolete managed targets are not outages. */
    public static function eligible(): \Illuminate\Database\Query\Builder
    {
        return DB::table(ManagedPublicWorkSchema::TABLE)->whereIn('kind',['delegation','points'])
                ->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_domains as d')->whereColumn('d.id',ManagedPublicWorkSchema::TABLE.'.domain_id')
                    ->whereColumn('d.authority_generation',ManagedPublicWorkSchema::TABLE.'.generation')->whereColumn('d.user_id',ManagedPublicWorkSchema::TABLE.'.user_id')
                    ->whereColumn('d.account_fingerprint',ManagedPublicWorkSchema::TABLE.'.account_fingerprint')->whereIn('d.status',['active','grace']))->whereNull('retired_at')->where(fn($q)=>$q->whereNull('safe_code')->orWhere('safe_code','<>','awaiting_acceptance'));
    }
    /** At most ten hints and two/owner; persistent round-robin across owner IDs. No I/O. */
    public function due(int $now,bool $includeWatches=false): array
    {
        if ((!$this->signals->enabled() && !$includeWatches) || DB::transactionLevel()!==0) { return []; }
        return DB::transaction(function()use($now,$includeWatches):array {
            PublicDnsQuota::seed($now);$pool=DB::table(PublicDnsQuota::TABLE)->where('id',1)->lockForUpdate()->first();
            // SQL NULL is accepted work, too.
            $base=self::eligible()
                ->where('due_at','<=',$now)->where(fn($q)=>$q->whereNull('lease_until')->orWhere('lease_until','<=',$now))
                ->where(fn($q)=>$q->whereNull('backoff_until')->orWhere('backoff_until','<=',$now));
            if (!$this->signals->enabled()) { $base->whereRaw('1=0'); }
            $watch=DB::table('domains_watch_work as w')->join('domains_watch_names as n','n.id','=','w.watch_id')
                ->whereIn('w.kind',['expiry','delegation','points'])->where('n.state','active')->whereNull('n.managed_domain_id')
                ->where('w.due_at','<=',$now)->where(fn($q)=>$q->whereNull('w.lease_until')->orWhere('w.lease_until','<=',$now))
                ->where(fn($q)=>$q->whereNull('w.backoff_until')->orWhere('w.backoff_until','<=',$now));
            if (!$includeWatches) { $watch->whereRaw('1=0'); }
            $all=(clone$base)->select('user_id')->union((clone$watch)->select('n.user_id'));
            $owners=DB::query()->fromSub($all,'owners')->where('user_id','>',$pool->dispatch_cursor)->orderBy('user_id')->limit(5)->pluck('user_id');
            if ($owners->isEmpty()) { $owners=DB::query()->fromSub($all,'owners')->orderBy('user_id')->limit(5)->pluck('user_id'); }
            $out=[];
            foreach($owners as$owner) {
                $managed=(clone$base)->where('user_id',$owner)->select('id','domain_id','kind','due_at')->orderBy('due_at')->orderBy('id')->limit(2)->get()
                    ->map(fn($w)=>['domain_id'=>(int)$w->domain_id,'user_id'=>(int)$owner,'kind'=>$w->kind,'due_at'=>(int)$w->due_at,'sort_id'=>(int)$w->id]);
                $watched=(clone$watch)->where('n.user_id',$owner)->select('w.id','w.watch_id','w.kind','w.due_at')->orderBy('w.due_at')->orderBy('w.id')->limit(2)->get()
                    ->map(fn($w)=>['watch_id'=>(int)$w->watch_id,'user_id'=>(int)$owner,'kind'=>$w->kind,'due_at'=>(int)$w->due_at,'sort_id'=>(int)$w->id]);
                // One owner turn across both public target families; earlier due work wins.
                $candidates=[...$managed,...$watched];
                usort($candidates,fn($a,$b)=>[$a['due_at'],$a['sort_id'],isset($a['watch_id'])]<=>[$b['due_at'],$b['sort_id'],isset($b['watch_id'])]);
                foreach(array_slice($candidates,0,2)as$hint) { unset($hint['due_at'],$hint['sort_id']);$out[]=$hint; }
            }
            if ($owners->isNotEmpty()) { DB::table(PublicDnsQuota::TABLE)->where('id',1)->update(['dispatch_cursor'=>$owners->last()]); }
            return $out;
        });
    }
}
