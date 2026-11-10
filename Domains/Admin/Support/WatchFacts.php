<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchAudit,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary,Missing};
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchWrites as Writes,WatchNoticePreferences};
/** Monitored domain, DNS template and hosting product facts for staff pages. A null value applies but is missing: the page shows Missing with the cause. */
final class WatchFacts
{
    public const EVENTS=['staff_pause'=>'Monitoring paused','staff_resume'=>'Monitoring resumed','staff_profile'=>'DNS template changed','staff_retire'=>'Monitoring stopped','staff_read_requested'=>'Check requested','staff_note'=>'Note'];
    /** A resume saved while the hosting service is suspended leaves monitoring paused. */
    public const RESUME_SAVED='Monitoring will resume';
    public const DECRYPT="Can't read saved data. Ask your site admin.";
    public const TEMPLATE_UNAVAILABLE='Saved DNS records cannot be read. Ask your site admin to restore this template.';
    private static function gate(): void{abort_unless(Staff::can(Writes::VIEW),403);}
    private static function fact(mixed $value,string|\Illuminate\Support\HtmlString|null $sub=null,?string $url=null,?string $tone=null,?string $cause=null,bool $check=false): array{return compact('value','sub','tone','url','cause','check');}
    /** @return array{Domain:array<string,array>,Checks:array<string,array>} */
    public static function for(WatchName $w,?array $row=null): array
    {
        self::gate();$active=$w->state==='active';$missing=self::missingCause($w,'points');
        // Paused and stopped names keep their last saved results on this page; the list reads only active names.
        if(!$row){$s=$active?null:((new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->staffWatches([(int)$w->id])['watch:'.$w->id]??null);$row=$s?['keys'=>WatchFindings::keys($s),'summary'=>$s]:WatchFindings::summaries([$w])[$w->id];}
        $s=$row['summary'];$keys=$row['keys'];
        $c=WatchFindings::customers([$w->user_id])[$w->user_id];$profile=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchProfile($w);
        $domain=['Customer'=>self::fact($c['label'],$c['email'],url:$c['url']),
            'Expected nameservers'=>self::fact(WatchFindings::who(\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchExpected($w)),cause:'Choose it in Change DNS template'),
            'DNS template'=>self::fact($profile?->name,self::templateProblem($w,$profile),tone:$profile&&self::templateProblem($w,$profile)?'warning':null,url:$profile?RoutingProfileResource::getUrl('view',['record'=>$profile->id]):null,cause:'Choose one in Change DNS template'),
            'Registrar'=>self::fact(WatchFindings::who($w->registration),cause:self::missingCause($w,'expiry'))];
        // Hidden when the registry does not publish an expiry date.
        if(!in_array('watch.no_coverage',$keys,true)){$domain['Expires']=self::expires($s,$keys,$active,$w);}
        $sid=$w->sources()->where('user_id',$w->user_id)->where('state','watch')->orderBy('id')->value('service_id');$service=$sid?\App\Models\Service::with('product')->find($sid):null;
        if($service){$domain['Hosting service']=self::fact($service->product?->name??'Service #'.$service->id,'Service #'.$service->id,url:Staff::can('admin.services.update')?\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$service->id]):null);}
        $checks=['Nameservers'=>self::nameservers($w),'DNS records'=>self::records($s,$active,$missing)];
        if(!in_array('watch.no_coverage',$keys,true)){$checks['Registry']=in_array('watch.not_registered',$keys,true)?self::fact('Not registered',tone:'warning'):self::fact(($s?->freshness['registry']['checkedAt']??null)?'Registered':null,cause:self::missingCause($w,'expiry'));}
        $last=self::lastCheck($w,$s);$last['check']=$active && $last['value']===null;$checks['Last check']=$last;
        // Last check already says when. Only the saved DNS answer that Last check describes drops its second Checked time;
        // another check in the same minute keeps its own.
        $lastPoints=($lastAt=(int)($last['at']??0))>0&&is_string($last['value'])&&$last['value']!==''&&in_array('points',$last['from']??[],true);
        $checked=fn(int|string $at,bool $points=false)=>$points&&$lastPoints&&($t=is_int($at)?$at:(int)strtotime($at))===$lastAt?null:Sheet::moment(is_int($at)?$at:(int)strtotime($at),'Checked')->toHtml();
        if($active){$checks['Next check']=self::nextCheck($w);}
        // Alerts are on only while monitoring runs. Pausing keeps the customer's saved choice for when monitoring resumes.
        $p=WatchNoticePreferences::read((int)$w->user_id,$w->canonical_name);$on=$p['account']['enabled']&&$p['target']['enabled'];
        // A failed customer alert says why and what to do; the staff alert links here.
        $failed=NoticeHealth::open('watch',(int)$w->id,$w->canonical_name);
        $off=match(true){!in_array($w->state,['active','paused'],true)=>'Monitoring stopped',$w->state==='paused'&&$on=>'Paused. Alerts resume with monitoring.',default=>'The customer turns this on from their domain page'};
        $checks['Monitoring alerts']=self::fact($on&&$active?'On':'Off',implode(' · ',array_filter([$on&&$active?null:$off,$failed['text']??null]))?:null,tone:$failed['tone']??null);
        // An empty string does not apply here and stays hidden; null is Missing.
        $shown=fn(array $facts)=>array_filter($facts,fn($f)=>$f['value']!=='');
        $e=\Paymenter\Extensions\Servers\Domains\Presentation\MonitoringEvidence::read(\Paymenter\Extensions\Servers\Domains\Presentation\MonitoringEvidence::domain($w),$w->points,$w->canonical_name);
        // Comparison needs a template; saved public answers do not. Never label retained evidence Missing.
        if($w->points && $s?->website===null && $s?->email===null && $e['public']){
            $at=$e['public_at'];
            $checks['DNS records']=self::fact(implode('; ',$e['public']),
                new \Illuminate\Support\HtmlString(($e['public_stale']?'Historical answers. ':'').($at&&($when=$checked($at,true))!==null?$when.'. ':'').
                ($profile?'DNS template comparison is unavailable for these saved records.':'Choose a DNS template to compare these records.')));
        }
        // Registrar records exist only for a domain we register. The Domain section says their state once.
        if($w->managed_domain_id!==null){
            $domain['Registrar records']=self::fact($e['state'],$e['at']?Sheet::moment((int)strtotime($e['at']),'Checked'):'No registrar read yet.');
            $domain['Billing and ownership']=self::fact('Separate checks',$e['separation']);
        }
        // A paused or stopped name with no saved result has nothing to show; its status already says why.
        if(!$active){foreach(['Nameservers','Registry','Last check'] as $label){if(isset($checks[$label])&&$checks[$label]['value']===null){$checks[$label]['value']='';}}}
        // Saved answers only. Registrar records with no values are already Unknown in the Domain section.
        $evidence=[];foreach(['public'=>'Public DNS','registrar'=>'Registrar records'] as $key=>$label){
            if(!$e[$key]&&($key==='registrar'||(!$active&&$e['public_at']===null))){continue;}
            $at=$e[$key.'_at'];$sub=$at?rtrim(($key==='public'&&$e['public_stale']?'Historical answers. ':'').($checked($at,$key==='public')??'')):'No saved observation.';
            $evidence[$label]=self::fact($e[$key]?implode('; ',$e[$key]):'Unknown',$at?($sub===''?null:new \Illuminate\Support\HtmlString($sub)):$sub);}
        return array_filter(['Domain'=>$shown($domain),'Checks'=>$shown($checks),'Evidence'=>$evidence]);
    }
    /** A saved choice is never replaced automatically, including on a watch that returns after its template was turned off. */
    private static function templateProblem(WatchName $w,?WatchProfile $profile): ?string
    {
        if(!$profile || $w->managed_domain_id!==null || \Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::usable($profile) || !in_array($w->state,['active','paused'],true)){return null;}
        return ($profile->readProfile()===null?'Saved DNS records cannot be read.':'This template is off.').' Choose another in Change DNS template.';
    }
    public static function missingCause(WatchName $w,?string $kind=null): string
    {
        if($w->state==='paused'){return 'Paused';}
        if($w->state!=='active'){return 'Monitoring stopped; no further checks';}
        return $w->work->contains(fn($row)=>($kind===null||$row->kind===$kind)&&($row->due_at!==null||$row->lease_until>app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp()))
            ?Missing::fills('after the next check'):'No check scheduled. Check the background worker.';
    }
    private const EXPIRY_OK=['ready','rotation','no_coverage','not_registered','rate_limited','budget_exhausted','target_changed'];
    private static function registryFailed(WatchName $w): bool
    { $code=$w->work->firstWhere('kind','expiry')?->safe_code; return $code!==null && !in_array($code,self::EXPIRY_OK,true); }
    /** The last check failed in whole or in part, by the rule Last check uses for its red line. The status never reads healthy beside it. */
    public static function lastCheckFailed(WatchName $w,?DomainSummary $s): bool
    {
        if(!$s){return false;}
        $running=$w->state==='active';
        return self::registryFailed($w)||array_filter($s->freshness??[],fn($f)=>$f['checkedAt']!==null && $f['partial'] && (!$f['stale']||!$running))!==[];
    }
    /** The latest check across nameservers, records and registry; 'from' names the checks made at that instant. Only failed checks are red; waiting or old evidence explains its next step. */
    public static function lastCheck(WatchName $w,?DomainSummary $s): array
    {
        $fresh=$s?->freshness??[];$at=max([0,...array_map(fn($f)=>(int)$f['checkedAt'],$fresh)]);
        if(!$s){$at=max((int)$w->delegation?->started_at,(int)$w->registry?->started_at);return [...self::fact($at?self::date($at):($w->state==='active'?null:''),cause:'No monitoring summary saved. Check the customer and domain records.'),'at'=>$at,'from'=>[]];}
        if(!$at){return self::fact(null,cause:self::missingCause($w));}
        $failed=self::registryFailed($w);
        // A paused or stopped name reads as stale in every summary, so its saved failure still counts and it has no next check.
        $running=$w->state==='active';
        $partial=array_filter($fresh,fn($f)=>$f['checkedAt']!==null && $f['partial'] && (!$f['stale']||!$running));
        $stale=$running?array_filter($fresh,fn($f)=>$f['stale']):[];
        $retry=$running?' Checks retry automatically.':($w->state==='paused'?(\Paymenter\Extensions\Servers\Domains\Services\WatchDiscovery::canResume($w)?' Resume monitoring to check again.':' Checks start again when the hosting service is active.'):'');
        return [...self::fact(self::date($at),$failed?'Registry lookup failed.'.$retry:($partial?'Some checks failed.'.$retry:($stale?'Some results are waiting for the next check.':null)),tone:$failed||$partial?'danger':null),'at'=>$at,'from'=>array_keys(array_filter($fresh,fn($f)=>(int)$f['checkedAt']===$at))];
    }
    public static function expires(?DomainSummary $s,array $keys,bool $active,?WatchName $watch=null): array
    {
        if($s?->expiry){$soon=in_array('staff.expiring',$keys,true)||in_array('watch.expired',$keys,true);return self::fact(\Carbon\CarbonImmutable::parse($s->expiry)->format('M j, Y'),$soon?WatchFindings::expires($s->expiry):null,tone:$soon?'warning':null);}
        return self::fact($active?null:'',cause:$watch?self::missingCause($watch,'expiry'):'No expiry result saved. Check the monitoring schedule.',check:$active);
    }
    private static function nextCheck(WatchName $w): array
    {
        $work=$w->work()->get();if($work->contains(fn($r)=>$r->lease_until>app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp())){return self::fact('Checking now');}
        $due=$work->min('due_at');return [...self::fact($due?self::date((int)$due):null,cause:'No check scheduled. Open Registrars and check the background worker.',check:true),'at'=>$due?(int)$due:null];
    }
    /** The nameservers the last delegation check found, compared with the expected ones. */
    private static function nameservers(WatchName $w): array
    {
        $o=$w->delegation;if(!$o?->dns){return self::fact(null,cause:self::missingCause($w,'delegation'));}
        $found=[];foreach($o->facts['queries']??[] as $q){if(($q['name']??null)===$w->canonical_name && ($q['type']??null)==='NS' && ($q['state']??null)==='rrset'){$found=array_values(array_filter(array_column($q['records']??[],'value'),'is_string'));}}
        $expected=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchExpected($w);
        if(!\Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::usable(\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchProfile($w)) || !$expected){return self::fact($found?implode(', ',$found):WatchFindings::who($o->dns),'Choose a DNS template to compare nameservers.');}
        $same=$o->dns===$expected;
        return self::fact($found?implode(', ',$found):WatchFindings::who($o->dns),$same?'Matches template':($expected==='platform'?$w->canonical_name.' does not use '.\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::nameservers().'.':'Does not match template'),tone:$same?null:'warning');
    }
    private static function records(?DomainSummary $s,bool $active,string $missing): array
    {
        // Paused and stopped names keep no current record results; the row is hidden until monitoring resumes.
        if($s?->website===null && $s?->email===null){return self::fact($active?null:'',cause:$missing);}
        $to='point to '.WatchFindings::company();if($s->website===true && $s->email===true){return self::fact('Website and email '.$to);}
        $lines=[];foreach(['Website'=>$s->website,'Email'=>$s->email] as $label=>$v){if($v!==null){$lines[]=$label.' '.($v?'points to '.WatchFindings::company():'points elsewhere');}}
        return self::fact($lines,tone:in_array(false,[$s->website,$s->email],true)?'warning':null);
    }
    /** Staff changes, newest first; null when the saved notes can't be decrypted. */
    public static function history(WatchName $w): ?array
    {
        self::gate();
        try{$rows=WatchAudit::where('subject_type','watch')->where('subject_id',$w->id)->whereIn('event',array_keys(self::EVENTS))->orderByDesc('id')->limit(50)->get()->map(fn($a)=>['time'=>gmdate('c',$a->created_at->getTimestamp()),'event'=>$a->event==='staff_resume'&&($a->data['hosting']??null)==='suspended'?self::RESUME_SAVED:self::EVENTS[$a->event],'actor'=>(int)$a->actor_id,'note'=>$a->reason!==self::EVENTS[$a->event]?$a->reason:null])->all();}
        catch(\Illuminate\Contracts\Encryption\DecryptException){return null;}
        $names=Staff::can('admin.users.view')?User::whereIn('id',array_column($rows,'actor'))->get(['id','first_name','last_name','email'])->mapWithKeys(fn($u)=>[$u->id=>trim($u->first_name.' '.$u->last_name)?:$u->email])->all():[];
        $rows=[...array_map(fn($r)=>[...$r,'by'=>$names[$r['actor']]??'Staff #'.$r['actor']],$rows),...\Paymenter\Extensions\Servers\Domains\Presentation\MonitoringEvidence::history($w)];
        usort($rows,fn($a,$b)=>strtotime($b['time'])<=>strtotime($a['time']));return $rows;
    }
    public static function usedBy(int $id): int
    {
        self::gate();
        $inherited=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::uses($id);
        return WatchName::where(fn($q)=>$q->where('ns_profile_id',$id)->when($inherited,fn($q)=>$q->orWhereNull('ns_profile_id')))->whereIn('state',['active','paused'])->count()
            + \Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::configured(
                \Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::eligible(app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp()),'domains_domains.id','domains_domains.user_id',profile:$id)
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('domains_watch_names as predecessor')
                    ->whereColumn('predecessor.user_id','domains_domains.user_id')->whereColumn('predecessor.canonical_name','domains_domains.reservation_name')
                    ->where(fn($q)=>$q->where('predecessor.ns_profile_id',$id)->when($inherited,fn($q)=>$q->orWhereNull('predecessor.ns_profile_id')))->whereIn('predecessor.state',['active','paused']))->count();
    }
    /** For plain text. The watch page shows the same instants in the viewer's zone through each fact's 'at'. */
    public static function date(?int $at): string{return $at?gmdate('M j, Y, g:i A',$at).' UTC':Missing::LABEL;}
}
