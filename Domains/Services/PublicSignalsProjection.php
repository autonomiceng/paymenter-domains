<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\PublicSignalsTarget;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicObservation,WatchName};
/** Owner-scoped DNS badges only. Never exposes permissions, registrar account IDs or expiry. */
final class PublicSignalsProjection
{
    public function read(PublicSignalsTarget $target,int $now): ?array
    {
        return DB::transaction(function()use($target,$now):?array {
            if ($target->kind==='watch') {
                $watch=WatchName::whereKey($target->id)->where('user_id',$target->owner)->where('revision',$target->revision)->first();
                if (!$watch || $watch->state==='linked' || $watch->managed_domain_id!==null) { return null; }
                return (new WatchDnsProjection())->read($target->id,$target->owner,$now);
            }
            $d=Domain::whereKey($target->id)->where('user_id',$target->owner)->first();if (!$d) { return null; }
            if ($d->account_fingerprint!==$target->account || $d->authority_generation!==$target->generation || $d->confirmed_revision!==$target->revision) { return null; }
            $out=[];
            foreach(['dns'=>'delegation','website'=>'points','email'=>'points']as$axis=>$kind) {
                $base=ManagedPublicObservation::where('domain_id',$d->id)->where('user_id',$target->owner)->where('account_fingerprint',$target->account)
                    ->where('generation',$target->generation)->where('check_kind',$kind);
                $latest=(clone$base)->orderByDesc('id')->first();$good=(clone$base)->whereNotNull($axis)->orderByDesc('id')->first();
                $work=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$d->id)->where('generation',$target->generation)->where('kind',$kind)->first();
                $current=(new ManagedPublicTarget())->capture((int)$d->id,$target->owner,$now,(int)($good?->source_cursor??0),false);
                $stale=!$work || $work->retired_at!==null || ($work->safe_code!==null && !in_array($work->safe_code,['ready','rotation','budget_exhausted'],true))
                    || !$good || !$current || !$current['profile'] || !hash_equals($good->source_digest,$current['stamp']) || $latest?->{$axis}===null
                    || $good->partial || $now<$good->started_at || $now>=$good->valid_until;
                $out[$axis]=['value'=>$good?->{$axis},'checked_at'=>$good?->started_at,'valid_until'=>$good?->valid_until,'stale'=>$stale,
                    'available'=>$latest!==null && $latest->{$axis}!==null,'mixed'=>$good?->mixed??false,'confirmed'=>$axis==='dns' && !$stale && ($latest?->ns_confirmed??false)];
            }
            $out['partial']=$out['dns']['stale'] || $out['website']['stale'] || $out['email']['stale'];return $out;
        });
    }
}
