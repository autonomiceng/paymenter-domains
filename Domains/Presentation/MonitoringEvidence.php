<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicObservation,PublicDnsObservation,WatchName};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value;
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
        // How the public read ended: answered, a confirmed negative (every A/AAAA query got a valid negative reply), or
        // unknown (a resolver failure, timeout or no address query), so an empty result never reads as confirmed absence.
        $answers=[];$asked=[];
        try{foreach($public?->facts['queries']??[] as $q){if(($q['name']??null)!==$name||!in_array($q['type']??null,['A','AAAA'],true)){continue;}
            $asked[]=$q['state']??null;
            foreach($q['records']??[] as $r){if(is_string($r['value']??null)&&filter_var($r['value'],FILTER_VALIDATE_IP)){$answers[]=$q['type'].' @ → '.$r['value'];}}}}
        catch(\Illuminate\Contracts\Encryption\DecryptException){$answers=[];$asked=[];}
        $publicRead=$public===null?null:($answers!==[]?'answered':($asked!==[]&&array_unique($asked)===['negative']?'negative':'unknown'));
        return ['state'=>$state,'public_stale'=>self::publicStale($d,$public,$name),'public'=>$answers,'public_at'=>$public?->started_at,'public_read'=>$publicRead,
            'registrar'=>$registrar,'registrar_at'=>$families['families']['dns']['at']??null,'registrar_read'=>$families['families']['dns']['state']??null,'at'=>$families['at']??null,'families'=>$families,
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
    /** The Monitoring rows, label => value: what public DNS answered and what the registrar holds, each with when it was
     * checked. None only follows a read that confirmed there is no address: every public A/AAAA query got a valid
     * negative reply, or a complete registrar DNS read. A read that failed or stopped part way says Couldn't read with
     * when it was tried. Saved public answers past their freshness, empty or not, say they are not current. Never
     * checked says Not read yet, once. $withRecords: the page already shows the registrar records and their last read,
     * so the refresh row is left out.
     * @return array<string,Value> */
    public static function rows(array $evidence, bool $withRecords = false): array
    {
        $time = static function ($at): ?Fact {
            $t = is_int($at) ? $at : (is_string($at) && $at !== '' ? strtotime($at) : false);
            return $t === false ? null : Fact::date($t, Reader::Staff);
        };
        $seen = static function (array $answers, $at, bool $confirmed, string $checked, string $failed, bool $stale = false) use ($time): Value {
            if (($when = $time($at)) === null) { return $answers === [] ? Value::state(State::NotReadYet, Reader::Staff) : Value::fact(Fact::lines($answers)); }
            $label = $stale ? 'Not current. Last checked' : $checked;
            if ($answers !== []) { return Value::fact(Fact::lines($answers), $label, $when); }
            return $confirmed ? Value::plainAt(Plain::None, $label, $when) : Value::state(State::CouldntRead, Reader::Staff, $failed, null, $when);
        };
        $refresh = ($at = $time($evidence['at'] ?? null)) === null ? Value::state(State::NotReadYet, Reader::Staff) : Value::fact(Fact::entered($evidence['state']), 'Read', $at);
        return [...($withRecords ? [] : ['Registrar refresh' => $refresh]),
            'Public DNS' => $seen($evidence['public'] ?? [], $evidence['public_at'] ?? null, ($evidence['public_read'] ?? null) === 'negative',
                'Checked', 'The check got no clear answer. Last tried', (bool) ($evidence['public_stale'] ?? false)),
            'Registrar records' => $seen($evidence['registrar'] ?? [], $evidence['registrar_at'] ?? null, ($evidence['registrar_read'] ?? null) === 'complete',
                'Read', 'The last read did not finish. Last tried')];
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
