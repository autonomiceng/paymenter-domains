<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchRegistryObservation};
/** Last successful expiry survives registry outages, always explicitly stale when current evidence fails. */
final class WatchRegistryProjection
{
    public function read(int $id,int $owner,int $now): ?array
    {
        if (!WatchName::whereKey($id)->where('user_id',$owner)->exists()) { return null; }
        $base=WatchRegistryObservation::where('watch_id',$id)->where('user_id',$owner);
        $latest=(clone$base)->orderByDesc('id')->first(); $good=(clone$base)->where('result','facts')->orderByDesc('id')->first();
        $stamp=(new WatchDnsChecks())->registryTarget($id,$owner,false,(int)($good?->source_cursor??0))['stamp']??null;
        $work=DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->first();
        $valid=$good?->valid_until;
        if ($good && WatchRegistryChecks::urgent($good->facts['expiry']??null,$now)) { $valid=min((int)$valid,$good->started_at+43200); }
        $stale=!$good || !$stamp || !hash_equals($good->stamp,$stamp) || $latest?->result!=='facts' || $now<$good->started_at || $now>=$valid
            || !in_array($work->safe_code??null,[null,'budget_exhausted'],true);
        return ['expiry'=>$good?->facts['expiry'],'precision'=>$good?->facts['precision'],'source'=>$good?->facts['source'],
            'checked_at'=>$good?->started_at,'valid_until'=>$valid,'stale'=>$stale,'result'=>$latest?->result??'unknown',
            'safe_code'=>$latest?->safe_code??'expiry_unknown','notice_eligible'=>!$stale && $latest?->id===$good?->id];
    }
}
