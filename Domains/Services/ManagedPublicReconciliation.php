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
    /** At most ten hints and two/owner; persistent round-robin across owner IDs. No I/O. */
    public function due(int $now): array
    {
        if (!$this->signals->enabled() || DB::transactionLevel()!==0) { return []; }
        return DB::transaction(function()use($now):array {
            PublicDnsQuota::seed($now);$pool=DB::table(PublicDnsQuota::TABLE)->where('id',1)->lockForUpdate()->first();
            // SQL NULL is accepted work, too.
            $base=DB::table(ManagedPublicWorkSchema::TABLE)->whereIn('kind',['delegation','points'])
                ->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_domains as d')->whereColumn('d.id',ManagedPublicWorkSchema::TABLE.'.domain_id')
                    ->whereColumn('d.authority_generation',ManagedPublicWorkSchema::TABLE.'.generation')->whereColumn('d.user_id',ManagedPublicWorkSchema::TABLE.'.user_id')
                    ->whereColumn('d.account_fingerprint',ManagedPublicWorkSchema::TABLE.'.account_fingerprint')->whereIn('d.status',['active','grace']))->whereNull('retired_at')->where(fn($q)=>$q->whereNull('safe_code')->orWhere('safe_code','<>','awaiting_acceptance'))
                ->where('due_at','<=',$now)->where(fn($q)=>$q->whereNull('lease_until')->orWhere('lease_until','<=',$now))
                ->where(fn($q)=>$q->whereNull('backoff_until')->orWhere('backoff_until','<=',$now));
            $owners=(clone$base)->where('user_id','>',$pool->dispatch_cursor)->select('user_id')->distinct()->orderBy('user_id')->limit(5)->pluck('user_id');
            if ($owners->isEmpty()) { $owners=(clone$base)->select('user_id')->distinct()->orderBy('user_id')->limit(5)->pluck('user_id'); }
            $out=[];foreach($owners as$owner){foreach((clone$base)->where('user_id',$owner)->orderBy('due_at')->orderBy('id')->limit(2)->get()as$w){$out[]=['domain_id'=>(int)$w->domain_id,'user_id'=>(int)$owner,'kind'=>$w->kind];}}
            if ($owners->isNotEmpty()) { DB::table(PublicDnsQuota::TABLE)->where('id',1)->update(['dispatch_cursor'=>$owners->last()]); }
            return $out;
        });
    }
}
