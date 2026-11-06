<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicObservation,PublicDnsObservation,WatchName};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
/** Read-only evidence: public answers never certify registrar mutations or billing. */
final class MonitoringEvidence
{
    public static function domain(WatchName $w): ?Domain
    {
        return $w->managed_domain_id===null?null:Domain::whereKey($w->managed_domain_id)->where('user_id',$w->user_id)->where('domain',$w->canonical_name)->first();
    }
    public static function read(?Domain $d,?PublicDnsObservation $public=null,?string $name=null): array
    {
        $name??=$d?->domain;
        if($d&&$public===null&&\Illuminate\Support\Facades\Schema::hasTable('domains_managed_public_observations')){$public=ManagedPublicObservation::where('domain_id',$d->id)->where('user_id',$d->user_id)->where('generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->where('domain_revision',$d->confirmed_revision)->where('check_kind','points')->orderByDesc('id')->first();}
        $families=$d?RecordFamilies::read($d,app(Clock::class)->now()):null;
        $states=array_column(array_filter($families['rows']??[],fn($r)=>$r['state']!=='unsupported'),'state');
        $state=!$states?'Unknown':(count(array_unique($states))===1&&$states[0]==='complete'?'Complete':(array_intersect($states,['complete','partial'])?'Partial':'Unknown'));
        $registrar=[];
        foreach($families['families']['dns']['records']??[] as $r){if(in_array($r['type']??null,['A','AAAA'],true)){$registrar[]=$r['type'].' '.($r['name']?:'@').' → '.$r['address'];}}
        $answers=[];
        try{foreach($public?->facts['queries']??[] as $q){if(($q['name']??null)!==$name||!in_array($q['type']??null,['A','AAAA'],true)){continue;}
            foreach($q['records']??[] as $r){if(is_string($r['value']??null)&&filter_var($r['value'],FILTER_VALIDATE_IP)){$answers[]=$q['type'].' @ → '.$r['value'];}}}}
        catch(\Illuminate\Contracts\Encryption\DecryptException){$answers=[];}
        return ['state'=>$state,'public_stale'=>self::publicStale($d,$public,$name),'public'=>$answers,'public_at'=>$public?->started_at,'registrar'=>$registrar,'registrar_at'=>$families['families']['dns']['at']??null,'at'=>$families['at']??null,'families'=>$families,
            'separation'=>RecordFamilies::EVIDENCE];
    }
    /** Use the established public-signal freshness and structural target checks; retained answers are historical. */
    private static function publicStale(?Domain $d,?PublicDnsObservation $public,?string $name): bool
    {
        $now=app(Clock::class)->now()->getTimestamp();
        if(!$public||$public->check_kind!=='points'||$public->result!=='complete'||$public->partial||$public->valid_until===null||$now<$public->started_at||$now>=$public->valid_until){return true;}
        if($public instanceof ManagedPublicObservation){
            if(!$d||$d->domain!==$name||(int)$public->domain_id!==(int)$d->id||(int)$public->user_id!==(int)$d->user_id||$public->account_fingerprint!==$d->account_fingerprint||(int)$public->generation!==(int)$d->authority_generation||(int)$public->domain_revision!==(int)$d->confirmed_revision){return true;}
            $target=\Paymenter\Extensions\Servers\Domains\Dns\PublicSignalsTarget::managed((int)$d->id,(int)$d->user_id,$d->account_fingerprint,(int)$d->authority_generation,(int)$d->confirmed_revision);
        }else{
            $watch=WatchName::whereKey($public->watch_id)->where('user_id',$public->user_id)->where('canonical_name',$name)->first();
            if(!$watch||(int)$public->watch_revision!==(int)$watch->revision){return true;}
            $target=\Paymenter\Extensions\Servers\Domains\Dns\PublicSignalsTarget::watch((int)$watch->id,(int)$watch->user_id,(int)$watch->revision);
        }
        try{$fresh=(new \Paymenter\Extensions\Servers\Domains\Services\PublicSignalsProjection)->read($target,$now);}
        catch(\Illuminate\Contracts\Encryption\DecryptException){return true;}
        return !collect(['website','email'])->contains(fn($axis)=>!($fresh[$axis]['stale']??true)&&($fresh[$axis]['checked_at']??null)===$public->started_at);
    }
    /** Only timestamps actually retained by the registrar inventory are represented as read events. */
    public static function history(WatchName $w): array
    {
        $d=self::domain($w);if(!$d){return [];}$e=self::read($d);$f=$e['families'];$rows=[];
        if($f['last_complete_at']&&$f['last_complete_at']!==$f['at']){$rows[]=['time'=>$f['last_complete_at'],'event'=>'Registrar read complete','note'=>'All supported record families were checked.','by'=>'System'];}
        if($f['at']){$rows[]=['time'=>$f['at'],'event'=>'Registrar read '.strtolower($e['state']),'note'=>implode('; ',array_map(fn($r)=>$r['label'].': '.$r['value'],$f['rows'])).'. Public DNS is separate.','by'=>'System'];}
        return $rows;
    }
}
