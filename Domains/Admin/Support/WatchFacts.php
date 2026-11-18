<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Presentation\MonitoringEvidence;
use Paymenter\Extensions\Servers\Domains\Presentation\State;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault, ManagedDnsSetup, WatchTemplateAssignment};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchAudit,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary,Missing};
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchWrites as Writes,WatchNoticePreferences};
/** Monitored domain, DNS template and hosting product facts for staff pages. A null value applies but is missing: the page shows Missing with the cause. */
final class WatchFacts
{
    public const EVENTS=['staff_pause'=>'Monitoring paused','staff_resume'=>'Monitoring resumed','staff_profile'=>'DNS template changed','staff_retire'=>'Monitoring stopped','staff_read_requested'=>'Check requested','staff_note'=>'Note','discovery_returned'=>'Domain found again','discovery_active'=>'Monitoring resumed','discovery_paused'=>'Monitoring paused','discovery_retired'=>'Monitoring stopped'];
    /** A resume saved while the hosting service is suspended leaves monitoring paused. */
    public const RESUME_SAVED='Monitoring will resume';
    public const DECRYPT="Can't read saved data. Ask your site admin.";
    public const TEMPLATE_UNAVAILABLE='Saved DNS records cannot be read. Ask your site admin to restore this template.';
    private static function gate(): void{abort_unless(Staff::can(Writes::VIEW),403);}
    private static function fact(mixed $value,string|\Illuminate\Support\HtmlString|null $sub=null,?string $url=null,?string $tone=null,?string $cause=null): array{return compact('value','sub','tone','url','cause');}
    /** @return array{Domain:array<string,array>,Checks:array<string,array>} */
    public static function for(WatchName $w,?array $row=null): array
    {
        self::gate();$active=$w->state==='active';$missing=self::missingCause($w,'points');
        // Paused and stopped names keep their last saved results on this page; the list reads only active names.
        if(!$row){$s=$active?null:((new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries())->staffWatches([(int)$w->id])['watch:'.$w->id]??null);$row=$s?['keys'=>WatchFindings::keys($s),'summary'=>$s]:WatchFindings::summaries([$w])[$w->id];}
        $s=$row['summary'];$keys=$row['keys'];
        $c=WatchFindings::customers([$w->user_id])[$w->user_id];$profile=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchProfile($w);
        $expected = match (SiteDnsDefault::watchExpected($w)) {
            'platform' => "Checks expect this template's nameservers.",
            'external' => 'Different nameservers are expected.',
            default => 'Expected nameservers are unset.',
        };
        $problem = self::templateProblem($w, $profile);
        $domain=['Customer'=>self::fact($c['label'],$c['email'],url:$c['url']),
            'DNS template'=>[...self::fact($profile?->name,trim(($problem ?? ($w->ns_profile_id === null ? 'Follows the site default.' : '')).' '.$expected),
                tone:$problem?'warning':null,url:$profile?RoutingProfileResource::getUrl('view',['record'=>$profile->id]):null),
                'state'=>$profile ? null : State::NotSet],
            'Registrar'=>self::fact(WatchFindings::who($w->registration),cause:self::missingCause($w,'expiry'))];
        // Hidden when the registry does not publish an expiry date.
        if(!in_array('watch.no_coverage',$keys,true)){$domain['Expires']=self::expires($s,$keys,$active,$w);}
        $sid=$w->sources()->where('user_id',$w->user_id)->where('state','watch')->orderBy('id')->value('service_id');$service=$sid?\App\Models\Service::with('product')->find($sid):null;
        if($service){$domain['Hosting service']=self::fact($service->product?->name??'Service #'.$service->id,'Service #'.$service->id,url:Staff::can('admin.services.update')?\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$service->id]):null);}
        $checks=['Nameservers'=>self::nameservers($w,$s,$profile),'DNS records'=>self::records($s,$active,$missing)];
        if(!in_array('watch.no_coverage',$keys,true)){$checks['Registry']=in_array('watch.not_registered',$keys,true)?self::fact('Not registered',tone:'warning'):self::fact(($s?->freshness['registry']['checkedAt']??null)?'Registered':null,cause:self::missingCause($w,'expiry'));}
        $last=self::lastCheck($w,$s);$checks['Last check']=$last;
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
        $e=MonitoringEvidence::forWatch($w);
        // Comparison needs a template; saved public answers do not. Never label retained evidence Missing.
        if($w->points && $s?->website===null && $s?->email===null && $e['public'] && !self::dnsFailed($w,'points')){
            $at=$e['public_at'];
            $checks['DNS records']=self::fact(implode('; ',$e['public']),
                new \Illuminate\Support\HtmlString(($e['public_stale']?'Not current. ':'').($at&&($when=$checked($at,true))!==null?$when.'. ':'').
                ($profile?'DNS template comparison is unavailable for these saved records.':'Choose a DNS template to compare these records.')));
        }
        if ($active && self::dnsFailed($w, 'points') && $checks['DNS records']['value'] === null) {
            $previous = [];
            foreach (['website'=>'Website', 'email'=>'Email'] as $axis => $label) {
                $prior = $s?->lastKnown[$axis] ?? [];
                if (($prior['inScope'] ?? false) && is_bool($prior['value'])) {
                    $previous[] = $label.($prior['value'] ? ' matched' : ' differed from').' the template on '.self::date($prior['checkedAt']).'.';
                }
            }
            if ($previous) { $checks['DNS records'] = self::fact($previous, 'Previously observed.'); }
        }
        if (self::registryFailed($w)) {
            $prior = $s?->lastKnown['registry'] ?? [];
            $observed = ($prior['inScope'] ?? false) ? 'Previously observed '.self::date($prior['checkedAt']).'.' : null;
            $absent = ($prior['result'] ?? null) === 'not_registered';
            if ($observed && $absent) { unset($domain['Expires']); }
            if (isset($domain['Expires']) && $observed && $prior['value']) {
                $domain['Expires'] = self::fact(\Carbon\CarbonImmutable::parse($prior['value'])->format('M j, Y'), $observed);
            }
            foreach (['Expires', 'Registrar'] as $label) {
                if (!isset($domain[$label])) { continue; }
                if (empty($domain[$label]['value'])) { $domain[$label]['state'] = State::CouldntRead; }
            }
            if (isset($checks['Registry'])) {
                $checks['Registry'] = $observed ? self::fact($absent ? 'Not registered' : 'Registered', $observed) : [...self::fact(null), 'state' => State::CouldntRead];
            }
        } elseif (isset($checks['Registry']) && ($s?->freshness['registry']['stale'] ?? true) && $checks['Registry']['value'] === 'Registered') {
            $previous = $s?->lastKnown['registry'] ?? [];
            $checks['Registry'] = ($previous['inScope'] ?? false)
                ? self::fact($previous['result'] === 'not_registered' ? 'Not registered' : 'Registered', 'Previously observed '.self::date($previous['checkedAt']).'.')
                : self::fact(null, cause:self::missingCause($w, 'expiry'));
        }
        foreach (['Nameservers' => 'delegation', 'DNS records' => 'points'] as $label => $kind) {
            if (!$active || $checks[$label]['value'] !== null) { continue; }
            if (!ManagedDnsSetup::usable($profile)) { $checks[$label]['cause'] = 'Waits for a DNS template that is on.'; }
            elseif (self::dnsFailed($w, $kind)) { $checks[$label]['state'] = State::CouldntRead; }
        }
        // Registrar records exist only for a domain we register. The Domain section says their state once.
        if($w->managed_domain_id!==null){
            $domain['Registrar records']=self::fact($e['state'],$e['at']?Sheet::moment((int)strtotime($e['at']),'Checked'):'No registrar read yet.');
            $domain['Billing and ownership']=self::fact('From the registrar only',$e['separation']);
        }
        // A paused or stopped name with no saved result has nothing to show; its status already says why.
        if(!$active){foreach(['Nameservers','Registry','Last check'] as $label){if(isset($checks[$label])&&$checks[$label]['value']===null){$checks[$label]['value']='';}}}
        // Saved answers only. Registrar records with no values are already Unknown in the Domain section.
        $evidence=[];foreach(['public'=>'Public DNS','registrar'=>'Registrar records'] as $key=>$label){
            if(!$e[$key]&&($key==='registrar'||(!$active&&$e['public_at']===null))){continue;}
            // A check that saved no answers says so, so Not read yet beside its Checked time reads as one fact.
            $at=$e[$key.'_at'];$time=$at?$checked($at,$key==='public'):null;
            if($at&&$key==='registrar'&&$e['registrar_read']!=='complete'){
                $time=Sheet::moment(is_int($at)?$at:(int)strtotime($at),'Last tried')->toHtml();
            }
            $sub=$at?rtrim(($key==='public'&&$e['public_stale']?'Not current. ':'').(!$e[$key]&&$time!==null&&($key!=='public'||$e['public_read']!=='negative')?'No answers were saved. ':'').$time):'No saved observation.';
            $evidence[$label]=[...self::fact($e[$key]?implode('; ',$e[$key]):($key==='public' && $e['public_read']==='negative' ? 'None' : 'Unknown'),$at?($sub===''?null:new \Illuminate\Support\HtmlString($sub)):$sub),
                'state'=>$key==='public'&&!$e[$key]&&$e['public_read']==='unknown'?State::CouldntRead:null];}
        return array_filter(['Domain'=>$shown($domain),'Checks'=>$shown($checks),'Evidence'=>$evidence]);
    }
    /** A saved choice is never replaced automatically, including on a watch that returns after its template was turned off. */
    private static function templateProblem(WatchName $w,?WatchProfile $profile): ?string
    {
        if(!$profile || $w->managed_domain_id!==null || \Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::usable($profile) || !in_array($w->state,['active','paused'],true)){return null;}
        return ($profile->readProfile()===null?'Saved DNS records cannot be read.':'This template is off.').' Choose another template.';
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
    private static function dnsFailed(WatchName $w, string $kind): bool
    {
        $code = $w->work->firstWhere('kind', $kind)?->safe_code;
        return in_array($code, ['resolver_failed', 'invalid_answer', 'ambiguous_empty', 'read_unavailable', 'deadline', 'clock_reversal'], true);
    }

    private static function failedKinds(WatchName $w, ?DomainSummary $s): array
    {
        $kinds = [];
        if (self::registryFailed($w)) { $kinds[] = 'expiry'; }
        foreach (['delegation','points'] as $kind) {
            $f = $s?->freshness[$kind] ?? null;
            $partial = $f && $f['checkedAt'] !== null && $f['partial'] && (!$f['stale'] || $w->state !== 'active');
            $code = $w->work->firstWhere('kind', $kind)?->safe_code;
            if (self::dnsFailed($w, $kind) || ($partial && !in_array($code, ['budget_exhausted','rotation','nameservers_missing'],true))) {
                $kinds[] = $kind;
            }
        }
        return $kinds;
    }

    public static function lastCheckFailed(WatchName $w,?DomainSummary $s): bool
    { return self::failedKinds($w, $s) !== []; }

    /** Last check time across all families. A failure can exist before any observation is saved. */
    public static function lastCheck(WatchName $w,?DomainSummary $s): array
    {
        $fresh = $s?->freshness ?? [];
        $at = $s ? max([0,...array_map(fn($f)=>(int)$f['checkedAt'],$fresh)])
            : max((int)$w->delegation?->started_at,(int)$w->points?->started_at,(int)$w->registry?->started_at);
        $failed = self::failedKinds($w, $s);
        $retryKind = $failed[0] ?? null;
        foreach ($failed as $kind) {
            $work = $w->work->firstWhere('kind',$kind);
            if ($work && !$work->claim && $work->backoff_until <= time() && $work->lease_until <= time()) { $retryKind = $kind; break; }
        }
        $failure = match (true) {
            !$failed => null,
            count($failed) > 1 && in_array('expiry',$failed,true) => 'Registry and DNS checks failed.',
            $retryKind === 'expiry' => 'Registry lookup failed.',
            default => 'DNS lookup failed.',
        };
        $sub = null;
        if ($failure !== null) {
            $sub = $failure.match ($w->state) {
                'active' => ' Checks retry automatically.',
                'paused' => \Paymenter\Extensions\Servers\Domains\Services\WatchDiscovery::canResume($w) ? ' Resume monitoring to check again.' : ' Checks start again when the hosting service is active.',
                default => '',
            };
        } elseif ($at && $w->state === 'active' && array_filter($fresh, fn($f) => $f['stale'])) {
            $sub = 'Some results are waiting for the next check.';
        }
        $failedAt = null;
        if ($failure && $at) {
            // A different family can have a newer successful read. Only date a failure that supplied Last check.
            if ((int) $w->registry?->started_at === $at && $w->registry->reason === 'endpoint_failed') { $failedAt = $at; }
            foreach (['delegation','points'] as $kind) {
                if (self::dnsFailed($w,$kind) && (int) $w->{$kind}?->started_at === $at && $w->{$kind}->result !== 'complete') { $failedAt = $at; }
            }
        }
        return [...self::fact($at ? self::date($at) : null, $sub, tone:$failure ? 'danger' : null, cause:self::missingCause($w)),
            'retryKind'=>$retryKind, 'failedAt'=>$failedAt, 'at'=>$at ?: null, 'from'=>array_keys(array_filter($fresh, fn($f) => $at && (int)$f['checkedAt']===$at))];
    }
    public static function expires(?DomainSummary $s,array $keys,bool $active,?WatchName $watch=null): array
    {
        if($s?->expiry){$soon=in_array('staff.expiring',$keys,true)||in_array('watch.expired',$keys,true);return self::fact(\Carbon\CarbonImmutable::parse($s->expiry)->format('M j, Y'),$soon?WatchFindings::expires($s->expiry):null,tone:$soon?'warning':null);}
        return self::fact($active?null:'',cause:$watch?self::missingCause($watch,'expiry'):'No expiry result saved. Check the monitoring schedule.');
    }
    private static function nextCheck(WatchName $w): array
    {
        $work=$w->work()->get();if($work->contains(fn($r)=>$r->lease_until>app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp())){return self::fact('Checking now');}
        $due=$work->min('due_at');return [...self::fact($due?self::date((int)$due):null,cause:'No check scheduled. Open Registrars and check the background worker.'),'at'=>$due?(int)$due:null];
    }
    /** The nameservers the last delegation check found, compared with the expected ones. */
    private static function nameservers(WatchName $w,?DomainSummary $s,?WatchProfile $profile): array
    {
        $o = $w->delegation;
        if (!$o?->dns && $o) {
            $o = \Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation::where('watch_id', $w->id)->where('user_id', $w->user_id)
                ->where('check_kind', 'delegation')->whereNotNull('dns')->latest('id')->first();
        }
        if (!$o?->dns) { return self::fact(null, cause:self::missingCause($w,'delegation')); }
        $found=[];foreach($o->facts['queries']??[] as $q){if(($q['name']??null)===$w->canonical_name && ($q['type']??null)==='NS' && ($q['state']??null)==='rrset'){$found=array_values(array_filter(array_column($q['records']??[],'value'),'is_string'));}}
        $observed = $found ? implode(', ', $found) : ($o->dns === 'platform' ? 'Matched the template' : 'Different nameservers');
        $configured = ManagedDnsSetup::usable($profile);
        if ($o->id !== $w->delegation?->id || self::dnsFailed($w, 'delegation') || ($s?->lastKnown['dns']['stale'] ?? true)) {
            return self::fact($observed, 'Previously observed '.self::date($o->started_at).'.'.($configured ? '' : ' Choose an enabled DNS template to compare nameservers.'));
        }
        $expected=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchExpected($w);
        if (!$configured || !$expected) {
            return self::fact($observed, $configured ? 'Choose what nameservers checks should expect.' : 'Choose an enabled DNS template to compare nameservers.');
        }
        if ($expected === 'external') {
            $different = $o->dns === 'external';
            return self::fact($observed,
                $different ? 'Different nameservers were expected.' : 'Matches the template, but different nameservers were expected.',
                tone:$different ? null : 'warning');
        }
        if ($o->dns === $expected) { return self::fact($observed, 'Matches template'); }
        $site = $w->ns_profile_id === null ? $profile : SiteDnsDefault::profile();
        $expectedNames = WatchTemplateAssignment::verified($profile, $site) ? \Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::nameservers()
            : 'the nameservers in '.$profile->name;
        return self::fact($observed, $w->canonical_name.' does not use '.$expectedNames.'.', tone:'warning');
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
        try{$rows=WatchAudit::where('subject_type','watch')->where('subject_id',$w->id)->whereIn('event',array_keys(self::EVENTS))->orderByDesc('id')->limit(50)->get()->map(fn($a)=>['time'=>gmdate('c',$a->created_at->getTimestamp()),'event'=>$a->event==='staff_resume'&&($a->data['hosting']??null)==='suspended'?self::RESUME_SAVED:self::EVENTS[$a->event],'actor'=>(int)$a->actor_id,'note'=>str_starts_with($a->event,'discovery_')?null:($a->reason!==self::EVENTS[$a->event]?$a->reason:null)])->all();}
        catch(\Illuminate\Contracts\Encryption\DecryptException){return null;}
        $names=Staff::can('admin.users.view')?User::whereIn('id',array_column($rows,'actor'))->get(['id','first_name','last_name','email'])->mapWithKeys(fn($u)=>[$u->id=>trim($u->first_name.' '.$u->last_name)?:$u->email])->all():[];
        $rows=[...array_map(fn($r)=>[...$r,'by'=>$r['actor']===0?'System':($names[$r['actor']]??'Staff #'.$r['actor'])],$rows),...\Paymenter\Extensions\Servers\Domains\Presentation\MonitoringEvidence::history($w)];
        usort($rows,fn($a,$b)=>strtotime($b['time'])<=>strtotime($a['time']));return $rows;
    }
    /** The count and its drill-down use the same assignments. Paused watches keep their choice. */
    public static function assignments(int $id, ?array $setup = null): \Illuminate\Database\Query\Builder
    {
        self::gate();
        $setup ??= ManagedDnsSetup::configuration();
        $live = $setup['default'] === $id;
        $watches = WatchName::whereIn('state', ['active', 'paused'])
            ->where(fn ($q) => $q->where('ns_profile_id', $id)->when($live, fn ($q) => $q->orWhereNull('ns_profile_id')))
            ->selectRaw("id, canonical_name as domain, 'watch' as kind, case when state = 'paused' then 1 else 0 end as paused, case when ns_profile_id is null then 1 else 0 end as inherited");
        if (in_array($id, $setup['usable'], true)) {
            $domains = ManagedDnsSetup::eligible(app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp())
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('domains_watch_names as w')
                    ->whereColumn('w.user_id', 'domains_domains.user_id')->whereColumn('w.canonical_name', 'domains_domains.domain')
                    ->whereIn('w.state', ['active', 'paused'])->where(fn ($q) => $q->where('w.ns_profile_id', $id)->when($live, fn ($q) => $q->orWhereNull('w.ns_profile_id'))));
            foreach ($live ? [false, true] : [false] as $inherited) {
                $managed = ManagedDnsSetup::configured(clone $domains, 'domains_domains.id', 'domains_domains.user_id',
                    profile: $id, inherited: $inherited, configuration: $setup);
                // Negative list keys distinguish native domains from external watches with the same numeric ID.
                $watches->unionAll($managed->selectRaw("-cast(domains_domains.id as signed) as id, domain, 'registered' as kind, 0 as paused, ? as inherited", [(int) $inherited]));
            }
        }
        return \Illuminate\Support\Facades\DB::query()->fromSub($watches, 'assigned');
    }

    public static function usedBy(int $id, ?array $setup = null): int
    {
        return self::assignments($id, $setup)->count();
    }

    public static function usage(int $id): array
    {
        $setup = ManagedDnsSetup::configuration();
        $counts = self::assignments($id, $setup)->selectRaw('count(*) as assigned, sum(inherited) as inherited, sum(paused) as paused')->first();
        return ['assigned' => (int) $counts->assigned, 'inherited' => (int) $counts->inherited, 'paused' => (int) $counts->paused];
    }

    public static function usageNote(array $usage, WatchProfile $profile): ?string
    {
        if (!$usage['assigned']) { return null; }
        if (!$profile->enabled) { return 'Checks wait while this template is off.'; }
        if (!$profile->readProfile()) { return 'Checks wait until the template can be read.'; }
        $notes = [];
        if ($n = $usage['inherited']) { $notes[] = $n.($n === 1 ? ' domain follows' : ' domains follow').' the site default.'; }
        if ($n = $usage['paused']) { $notes[] = 'Monitoring is paused for '.$n.($n === 1 ? ' domain.' : ' domains.'); }
        return $notes ? implode(' ', $notes) : null;
    }
    /** For plain text. The watch page shows the same instants in the viewer's zone through each fact's 'at'. */
    public static function date(?int $at): string{return $at?\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at($at):Missing::LABEL;}
}
