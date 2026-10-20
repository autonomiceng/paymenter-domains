<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchAudit,WatchProductMap};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,StaffWatchWrites as Writes,WatchNoticePreferences,PublicSuffixList,RegistrableNameResolver,PslValidator};
use Paymenter\Extensions\Servers\Domains\Data\{PslSnapshot,Registrable};
final class WatchFacts
{
    private static function gate(): void{abort_unless(Staff::can(Writes::VIEW),403);}
    public static function for(WatchName $w): array
    {
        self::gate();$owner=User::find($w->user_id);$summary=$owner?(new DomainSummaries())->one($owner,'watch',(int)$w->id):null;
        $inactive=!in_array($w->state,['active'],true);$stopped=$w->state==='paused'?'Checks paused':($w->state==='retired'?'Watch ended · no further checks':'Linked to registration · watch checks stopped');
        $c=WatchFindings::customers([$w->user_id])[$w->user_id];
        $facts=['Customer'=>[$c['label'],...($c['email']?[$c['email']]:[])],'Watch state'=>match($w->state){'active'=>'Checking','paused'=>'Checks paused','retired'=>'Watch ended','linked'=>'Linked to registration',default=>'Needs review'},'Expected DNS'=>WatchQueries::observed($w->expected_dns)];
        if($summary){$facts['Registration / DNS']=array_map(fn($k)=>$summary->labels[$k]??'Details unavailable',[$summary->registrationKey,...$summary->dnsKeys,...$summary->stateKeys]);
            $facts['Public registry expiry']=$summary->expiry?\Carbon\CarbonImmutable::parse($summary->expiry)->format('M j, Y'):'Unknown';
            foreach($summary->freshness as $family=>$f){$facts[match($family){'delegation'=>'Nameservers','points'=>'Website / email pointers',default=>'Public registry'}]=[!$f['checkedAt']?'Not checked':($f['stale']?'Out of date':($f['partial']?'Partial':'Current')),'Checked: '.self::date($f['checkedAt']),...($inactive?[]:['Next attempt: '.self::date($f['nextCheck'])])];}
        }
        $facts['Scheduler']=$inactive?[$stopped]:($w->work()->get()->map(fn($r)=>self::kind($r->kind).' · '.($r->lease_until>time()?'In progress':($r->backoff_until>time()?'Waiting until '.self::date($r->backoff_until):'Scheduled')).' · Next attempt '.self::date($r->due_at).self::code($r->safe_code))->all()?:['Not scheduled']);
        $sources=$w->sources()->where('user_id',$w->user_id)->where('state','watch')->limit(26)->get();$facts['Source services']=$sources->take(25)->map(function($s){$label='Service #'.$s->service_id;return Staff::can('admin.services.update')?new HtmlString('<a class="fi-link" style="text-decoration:underline;outline-offset:3px" href="'.e(\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$s->service_id])).'">'.e($label).'</a>'):$label;})->all()?:['None currently linked'];
        $p=WatchNoticePreferences::read((int)$w->user_id,$w->canonical_name);$facts['Email preferences']=($p['account']['enabled']&&$p['target']['enabled'])?'Email enabled when eligible':'Email off';
        $facts['Notification activity']=DB::table('domains_watch_deliveries')->where('user_id',$w->user_id)->whereIn('notice_id',DB::table('domains_watch_notices')->where('scope',WatchNoticePreferences::scope((int)$w->user_id,$w->canonical_name))->select('id'))->orderByDesc('id')->limit(10)->get()->map(fn($r)=>match($r->state){'accepted'=>'Accepted by the email channel','reserved'=>'Waiting for the email channel','render_failed'=>'Email channel unavailable',default=>'Delivery needs review'})->all()?:['No channel acceptance recorded'];
        $facts['Audit']=WatchAudit::where('subject_type','watch')->where('subject_id',$w->id)->whereIn('event',['staff_pause','staff_resume','staff_profile','staff_retire','staff_read_requested','staff_note'])->orderByDesc('id')->limit(20)->get()->map(fn($a)=>self::date($a->created_at->getTimestamp()).' · '.match($a->event){'staff_pause'=>'Watch paused','staff_resume'=>'Watch resumed','staff_profile'=>'Profile changed','staff_retire'=>'Watch ended','staff_read_requested'=>'Scheduled check requested',default=>'Staff note'}.($a->reason?' · '.$a->reason:''))->all()?:['No staff changes recorded'];
        return $facts;
    }
    public static function preview(WatchProductMap $m): array
    {
        self::gate();$psl=app(PublicSuffixList::class);$current=$psl->current();if(!$current||!$psl->parserUsable($current)){return ['Preview unavailable until the local public suffix list is usable.'];}
        try{$snapshot=new PslSnapshot(PublicSuffixList::body($current),new PslValidator());}catch(\Throwable){return ['Preview unavailable until the local public suffix list is usable.'];}
        $services=\App\Models\Service::where('product_id',$m->product_id)->with('order')->orderBy('id')->limit(25)->get();
        $props=DB::table('properties')->where('model_type',(new \App\Models\Service())->getMorphClass())->whereIn('model_id',$services->pluck('id'))->where('key',$m->property_key)->get()->groupBy('model_id');$out=[];
        foreach($services as $s){$values=$props[$s->id]??collect();$label='Service #'.$s->id.' · ';
            if($s->status!=='active'||!$s->order||(int)$s->order->user_id!==(int)$s->user_id){$out[]=$label.'Held: inactive service or owner changed';continue;}
            if($values->count()!==1){$out[]=$label.'Held: primary property missing or ambiguous';continue;}
            try{$result=(new RegistrableNameResolver())->resolve(\Paymenter\Extensions\Servers\Domains\Dns\DnsQuery::hostname((string)$values->first()->value),$snapshot);$out[]=$label.($result instanceof Registrable?$result->name:'Held: primary domain could not be safely derived');}catch(\Throwable){$out[]=$label.'Held: primary domain could not be safely derived';}
        }
        return $out?:['No services available to preview.'];
    }
    public static function impact(int $id): array
    { self::gate();return ['Watches: '.WatchName::where('ns_profile_id',$id)->count(),'Mappings: '.WatchProductMap::where('expected_ns_profile_id',$id)->count(),'Managed targets: '.\Paymenter\Extensions\Servers\Domains\Models\ManagedPublicConfig::where('profile_id',$id)->count(),'Before editing, open referencing product mappings and choose Edit (saves disabled). The profile then saves disabled; enable it again after review.']; }
    private static function kind(string $kind): string{return match($kind){'delegation'=>'Nameservers','points'=>'Website / email pointers',default=>'Public registry'};}
    private static function code(?string $code): string{return match($code){null,'ready','rotation'=>'','no_coverage'=>' · Public expiry lookup is not available for this domain','not_registered'=>' · The public registry did not return a registration','rate_limited','budget_exhausted'=>' · Waiting for the next allowed check','target_changed'=>' · Saved details changed; waiting for review',default=>' · Public lookup unavailable'};}
    public static function date(?int $at): string{return $at?gmdate('M j, Y H:i',$at).' UTC':'Not scheduled';}
}
