<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchAudit,WatchProductMap,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary,Missing};
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchWrites as Writes,WatchNoticePreferences,PublicSuffixList,RegistrableNameResolver,PslValidator};
use Paymenter\Extensions\Servers\Domains\Data\{PslSnapshot,Registrable};
/** Monitored domain, DNS template and hosting product facts for staff pages. A null value applies but is missing: the page shows Missing with the cause. */
final class WatchFacts
{
    public const EVENTS=['staff_pause'=>'Monitoring paused','staff_resume'=>'Monitoring resumed','staff_profile'=>'DNS template changed','staff_retire'=>'Monitoring stopped','staff_read_requested'=>'Check requested','staff_note'=>'Note'];
    public const DECRYPT="Can't read saved data. Ask your site admin.";
    public const TEMPLATE_UNAVAILABLE='Saved DNS records cannot be read. Ask your site admin to restore this template.';
    private static function gate(): void{abort_unless(Staff::can(Writes::VIEW),403);}
    private static function fact(mixed $value,?string $sub=null,?string $tone=null,?string $url=null,?string $cause=null,bool $check=false): array{return compact('value','sub','tone','url','cause','check');}
    /** @return array{Domain:array<string,array>,Checks:array<string,array>} */
    public static function for(WatchName $w,?array $row=null): array
    {
        self::gate();$active=$w->state==='active';
        // Paused and stopped names keep their last saved results on this page; the list reads only active names.
        if(!$row){$s=$active?null:((new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->staffWatches([(int)$w->id])['watch:'.$w->id]??null);$row=$s?['keys'=>WatchFindings::keys($s),'summary'=>$s]:WatchFindings::summaries([$w])[$w->id];}
        $s=$row['summary'];$keys=$row['keys'];
        $c=WatchFindings::customers([$w->user_id])[$w->user_id];$profile=$w->ns_profile_id?WatchProfile::find($w->ns_profile_id):null;
        $domain=['Customer'=>self::fact($c['label'],$c['email'],null,$c['url']),
            'Expected nameservers'=>self::fact(WatchFindings::who($w->expected_dns),cause:'Choose it in Change DNS template'),
            'DNS template'=>self::fact($profile?->name,url:$profile?RoutingProfileResource::getUrl('view',['record'=>$profile->id]):null,cause:'Choose one in Change DNS template'),
            'Registrar'=>self::fact(WatchFindings::who($w->registration),cause:Missing::fills('after the next check'))];
        // Hidden when the registry does not publish an expiry date.
        if(!in_array('watch.no_coverage',$keys,true)){$domain['Expires']=self::expires($s,$keys,$active);}
        $sid=$w->sources()->where('user_id',$w->user_id)->where('state','watch')->orderBy('id')->value('service_id');$service=$sid?\App\Models\Service::with('product')->find($sid):null;
        if($service){$domain['Hosting service']=self::fact($service->product?->name??'Service #'.$service->id,'Service #'.$service->id,url:Staff::can('admin.services.update')?\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$service->id]):null);}
        $checks=['Nameservers'=>self::nameservers($w),'DNS records'=>self::records($s,$active)];
        if(!in_array('watch.no_coverage',$keys,true)){$checks['Registry']=in_array('watch.not_registered',$keys,true)?self::fact('Not registered',tone:'warning'):self::fact(($s?->freshness['registry']['checkedAt']??null)?'Registered':null,cause:Missing::fills('after the next check'));}
        $last=self::lastCheck($w,$s);$last['check']=$active && $last['value']===null;$checks['Last check']=$last;
        if($active){$checks['Next check']=self::nextCheck($w);}
        $p=WatchNoticePreferences::read((int)$w->user_id,$w->canonical_name);$on=$p['account']['enabled']&&$p['target']['enabled'];
        $checks['Monitoring alerts']=self::fact($on?'On':'Off',$on?null:'Turned off by customer');
        // An empty string does not apply here and stays hidden; null is Missing.
        $shown=fn(array $facts)=>array_filter($facts,fn($f)=>$f['value']!=='');
        return ['Domain'=>$shown($domain),'Checks'=>$shown($checks)];
    }
    /** The latest check across nameservers, records and registry. Red when out of date. */
    public static function lastCheck(WatchName $w,?DomainSummary $s): array
    {
        $fresh=$s?->freshness??[];$at=max([0,...array_map(fn($f)=>(int)$f['checkedAt'],$fresh)]);
        if(!$s){$at=max((int)$w->delegation?->started_at,(int)$w->registry?->started_at);return self::fact($at?self::date($at):($w->state==='active'?null:''),cause:self::DECRYPT);}
        if(!$at){return self::fact(null,cause:Missing::fills('after the next check'));}
        $failed=$w->work->firstWhere('kind','expiry')?->safe_code;$failed=$failed!==null && !in_array($failed,['ready','rotation','no_coverage','not_registered','rate_limited','budget_exhausted','target_changed'],true);
        return self::fact(self::date($at),$failed?'Registry lookup failed':(array_filter($fresh,fn($f)=>$f['partial'] && !$f['stale'])?'Some checks failed':null),array_filter($fresh,fn($f)=>$f['stale'])||$failed?'danger':null);
    }
    public static function expires(?DomainSummary $s,array $keys,bool $active): array
    {
        if($s?->expiry){$soon=in_array('staff.expiring',$keys,true)||in_array('watch.expired',$keys,true);return self::fact(\Carbon\CarbonImmutable::parse($s->expiry)->format('M j, Y'),$soon?WatchFindings::expires($s->expiry):null,$soon?'warning':null);}
        return self::fact($active?null:'',cause:Missing::fills('after the next check'),check:$active);
    }
    private static function nextCheck(WatchName $w): array
    {
        $work=$w->work()->get();if($work->contains(fn($r)=>$r->lease_until>time())){return self::fact('Checking now');}
        $due=$work->min('due_at');return self::fact($due?self::date((int)$due):null,cause:'No check scheduled',check:true);
    }
    /** The nameservers the last delegation check found, compared with the expected ones. */
    private static function nameservers(WatchName $w): array
    {
        $o=$w->delegation;if(!$o?->dns){return self::fact(null,cause:Missing::fills('after the next check'));}
        $found=[];foreach($o->facts['queries']??[] as $q){if(($q['name']??null)===$w->canonical_name && ($q['type']??null)==='NS' && ($q['state']??null)==='rrset'){$found=array_values(array_filter(array_column($q['records']??[],'value'),'is_string'));}}
        $same=!$w->expected_dns || $o->dns===$w->expected_dns;
        return self::fact($found?implode(', ',$found):WatchFindings::who($o->dns),$same?'Matches template':'Changed',$same?null:'warning');
    }
    private static function records(?DomainSummary $s,bool $active): array
    {
        // Paused and stopped names keep no current record results; the row is hidden until monitoring resumes.
        if($s?->website===null && $s?->email===null){return self::fact($active?null:'',cause:Missing::fills('after the next check'));}
        $to='point to '.WatchFindings::company();if($s->website===true && $s->email===true){return self::fact('Website and email '.$to);}
        $lines=[];foreach(['Website'=>$s->website,'Email'=>$s->email] as $label=>$v){if($v!==null){$lines[]=$label.' '.($v?'points to '.WatchFindings::company():'points elsewhere');}}
        return self::fact($lines,tone:in_array(false,[$s->website,$s->email],true)?'warning':null);
    }
    /** The one notice line for Problem found, e.g. "Nameservers changed: ns1.other.test, ns2.other.test." */
    public static function notice(WatchName $w,array $status,array $facts): ?string
    {
        if($status['label']!=='Problem found'){return null;}$ns=$facts['Checks']['Nameservers']['value']??null;
        return $status['reason'].($status['reason']==='Nameservers changed' && is_string($ns)?': '.$ns:'').'.';
    }
    /** Staff changes, newest first; null when the saved notes can't be decrypted. */
    public static function history(WatchName $w): ?array
    {
        self::gate();
        try{$rows=WatchAudit::where('subject_type','watch')->where('subject_id',$w->id)->whereIn('event',array_keys(self::EVENTS))->orderByDesc('id')->limit(20)->get()->map(fn($a)=>['time'=>self::date($a->created_at->getTimestamp()),'event'=>self::EVENTS[$a->event],'actor'=>(int)$a->actor_id,'note'=>$a->reason!==self::EVENTS[$a->event]?$a->reason:null])->all();}
        catch(\Illuminate\Contracts\Encryption\DecryptException){return null;}
        $names=Staff::can('admin.users.view')?User::whereIn('id',array_column($rows,'actor'))->get(['id','first_name','last_name','email'])->mapWithKeys(fn($u)=>[$u->id=>trim($u->first_name.' '.$u->last_name)?:$u->email])->all():[];
        return array_map(fn($r)=>[...$r,'by'=>$names[$r['actor']]??'Staff #'.$r['actor']],$rows);
    }
    /** Matched services for the first 25 services of the product; null until the public suffix list is usable. */
    public static function preview(WatchProductMap $m): ?array
    {
        self::gate();$psl=app(PublicSuffixList::class);$current=$psl->current();if(!$current||!$psl->parserUsable($current)){return null;}
        try{$snapshot=new PslSnapshot(PublicSuffixList::body($current),new PslValidator());}catch(\Throwable){return null;}
        $services=\App\Models\Service::where('product_id',$m->product_id)->with('order')->orderBy('id')->limit(25)->get();
        $props=DB::table('properties')->where('model_type',(new \App\Models\Service())->getMorphClass())->whereIn('model_id',$services->pluck('id'))->where('key',$m->property_key)->get()->groupBy('model_id');$out=[];
        foreach($services as $s){$values=$props[$s->id]??collect();$row=['service'=>'Service #'.$s->id,'url'=>Staff::can('admin.services.update')?\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$s->id]):null,'domain'=>null];
            if($s->status!=='active'||!$s->order||(int)$s->order->user_id!==(int)$s->user_id){$out[]=$row+['result'=>'Skipped: service not active'];continue;}
            if($values->count()!==1){$out[]=$row+['result'=>'Skipped: domain field empty'];continue;}
            try{$result=(new RegistrableNameResolver())->resolve(\Paymenter\Extensions\Servers\Domains\Dns\DnsQuery::hostname((string)$values->first()->value),$snapshot);}catch(\Throwable){$result=null;}
            $out[]=$result instanceof Registrable?['domain'=>$result->name,'result'=>'Matched']+$row:$row+['result'=>'Skipped: not a valid domain'];
        }
        return $out;
    }
    /** @return array{domains:int,products:int} */
    public static function usedBy(int $id): array{self::gate();return ['domains'=>WatchName::where('ns_profile_id',$id)->count(),'products'=>WatchProductMap::where('expected_ns_profile_id',$id)->count()];}
    public static function products(int $n): string{return $n.' hosting '.($n===1?'product':'products');}
    public static function date(?int $at): string{return $at?gmdate('M j, Y H:i',$at).' UTC':Missing::LABEL;}
}
