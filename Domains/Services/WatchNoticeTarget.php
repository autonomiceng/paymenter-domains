<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{WatchRegistryObservation,WatchDnsObservation,ManagedPublicObservation};
use Illuminate\Support\Facades\DB;
/** Normalize only latest facts compared with the current locked target, never snapshot projections. */
final class WatchNoticeTarget
{
    public static function capture(string $kind,int $id,int $owner,int $now,bool $lock=true): ?array
    {
        $latest=static fn($q)=>$q->orderByDesc('id')->when($lock,fn($q)=>$q->lockForUpdate())->first();
        if ($kind==='watch') {
            $r=WatchRegistryObservation::where('watch_id',$id)->where('user_id',$owner)->orderByDesc('id')->first();
            $capture=static fn($cursor)=>(new WatchDnsChecks())->registryTarget($id,$owner,$lock,$cursor);
            $t=$capture((int)($r?->source_cursor??0));if (!$t) { return null; }
            $r=$latest(WatchRegistryObservation::where('watch_id',$id)->where('user_id',$owner));
            $t=$capture((int)($r?->source_cursor??0));if (!$t) { return null; }
            $w=$t['watch'];$name=$w->canonical_name;$profile=$t['profile'];$expected=$w->expected_dns==='platform';$preferences=$w->notice_preferences??[];
            $rw=DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->when($lock,fn($q)=>$q->lockForUpdate())->first();
            $valid=$r?->valid_until;
            if ($r && WatchRegistryChecks::urgent($r->facts['expiry']??null,$now)) { $valid=min((int)$valid,$r->started_at+43200); }
            $registry=$r && $r->result==='facts' && hash_equals($r->stamp,$t['stamp']) && $now>=$r->started_at && $now<$valid
                && $rw && in_array($rw->safe_code,[null,'budget_exhausted'],true)?['id'=>(int)$r->id,'at'=>(int)$r->started_at,'valid_until'=>(int)$valid,
                    'expiry'=>$r->facts['expiry']??null,'current'=>true,'complete'=>true]:null;
            $d=$latest(WatchDnsObservation::where('watch_id',$id)->where('user_id',$owner)->where('check_kind','delegation'));
            $dt=$capture((int)($d?->source_cursor??0));$stamp=$dt['stamp']??null;
            $work=DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','delegation')->when($lock,fn($q)=>$q->lockForUpdate())->first();
            // Registry capture permits profile disagreement; DNS delivery must enforce watch-wide agreement,
            // including sources outside the observation's rotating cohort, with a current locking read.
            $conflict=DB::table('domains_watch_sources as s')->join('domains_watch_product_maps as m','m.id','=','s.map_id')
                ->where('s.watch_id',$id)->where('s.state','watch')->whereNotNull('m.expected_ns_profile_id')
                ->where('m.expected_ns_profile_id','<>',$profile?->id??0)->when($lock,fn($q)=>$q->lockForUpdate())->first(['s.id']);
            $ready=$dt && $profile?->enabled && (int)$dt['watch']->ns_profile_id===(int)$profile->id && !$conflict && $work && in_array($work->safe_code,[null,'budget_exhausted','rotation'],true);
            $confirmed=$d?->facts['ns_confirmed']??false;$family='watch_dns';
        } elseif ($kind==='managed') {
            $d=ManagedPublicObservation::where('domain_id',$id)->where('user_id',$owner)->where('check_kind','delegation')->orderByDesc('id')->first();
            $capture=static fn($cursor)=>(new ManagedPublicTarget())->capture($id,$owner,$now,$cursor,$lock);
            $t=$capture((int)($d?->source_cursor??0));if (!$t) { return null; }
            $d=$latest(ManagedPublicObservation::where('domain_id',$id)->where('user_id',$owner)->where('check_kind','delegation'));
            $t=$capture((int)($d?->source_cursor??0));if (!$t) { return null; }
            $w=$t['domain'];$name=$w->domain;$profile=$t['profile'];$expected=$t['config']->expected_dns==='platform';$preferences=$t['config']->notice_preferences??[];
            $work=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('generation',$w->authority_generation)->where('kind','delegation')->when($lock,fn($q)=>$q->lockForUpdate())->first();
            $ready=$profile && $work && $work->retired_at===null && in_array($work->safe_code,[null,'ready','rotation','budget_exhausted'],true)
                && $d && $d->account_fingerprint===$w->account_fingerprint && (int)$d->generation===(int)$w->authority_generation;
            $stamp=$t['stamp'];$confirmed=$d?->ns_confirmed??false;$registry=null;$family='managed_dns';
        } else { return null; }
        $lineage='profile:'.($profile?->id??0);
        $delegation=$ready?self::dnsFact($d,$stamp,$now,$lineage,(bool)$confirmed):null;
        return compact('kind','id','owner','name','lineage','expected','preferences','registry','delegation','family','now');
    }
    /** A locked target stamp includes current profile/revision, owner and source authority. */
    public static function dnsFact(?object $d,?string $stamp,int $now,string $lineage,bool $confirmed): ?array
    {
        return $d && $stamp && hash_equals($d->source_digest,$stamp) && $d->result==='complete' && !$d->partial && $d->dns!==null
            && $now>=$d->started_at && $now<$d->valid_until?['id'=>(int)$d->id,'at'=>(int)$d->started_at,'valid_until'=>(int)$d->valid_until,
                'dns'=>$d->dns,'lineage'=>$lineage,'ns_confirmed'=>$confirmed,'complete'=>true,'current'=>true]:null;
    }
    public static function eligible(\Paymenter\Extensions\Servers\Domains\Models\WatchNotice $n,array $t,array $state): bool
    {
        if ($n->user_id!==$t['owner'] || $n->data['domain']!==$t['name']) { return false; }
        if (in_array($n->kind,['expiring','expired'],true)) {
            $days=WatchNoticePlan::day((int)strtotime($n->data['date'].' UTC'))-WatchNoticePlan::day($t['now']);
            $nearest=null;foreach(WatchNoticePlan::WINDOWS as $window) { if ($days<=$window) { $nearest=$window; } }
            return $n->threshold===$nearest && $t['kind']==='watch' && ($t['preferences']['expiry']??true) && $t['registry']!==null && $t['registry']['expiry']===$n->data['date'];
        }
        $d=$t['delegation'];
        if (!$t['expected'] || !($t['preferences']['dns']??true) || !$d || !$d['complete'] || !$d['ns_confirmed'] || $t['lineage']!==$n->data['lineage']) { return false; }
        return $n->kind==='ns_changed'?($d['dns']==='external' && ($state['dns']['episode']??null)===$n->data['episode']):
            ($n->kind==='ns_restored' && $d['dns']==='platform' && !isset($state['dns']['episode']) && ($state['dns']['resolved_episode']??null)===$n->data['episode']);
    }
}
