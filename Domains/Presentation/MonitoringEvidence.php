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
        return ['state'=>$state,'public'=>$answers,'public_at'=>$public?->started_at,'registrar'=>$registrar,'registrar_at'=>$families['families']['dns']['at']??null,'at'=>$families['at']??null,'families'=>$families,
            'separation'=>'Public DNS can’t confirm a registrar change or renewal.'];
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
