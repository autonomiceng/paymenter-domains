<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Dns\DnsQuery;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicConfig,WatchProfile,WatchAudit};
/** Local comparison configuration only. A template never writes customer DNS or registrar defaults. */
final class ManagedDnsSetup
{
    public static function eligible(int $now): Builder
    {
        return Domain::whereIn('domains_domains.status',['active','grace'])->where('billing_managed',true)
            ->whereColumn('reservation_name','domain')->where('authority_generation','>',0)
            ->where(fn($q)=>$q->whereNull('provenance')->orWhere('provenance','<>','adoption_pending'))
            ->whereHas('server',fn($q)=>$q->where('extension','Domains'))
            ->whereDate('provider_expires_at','>',gmdate('Y-m-d',$now))
            ->whereHas('service',fn($q)=>$q->where('status','active')->whereColumn('services.user_id','domains_domains.user_id')
                ->whereHas('order',fn($q)=>$q->whereColumn('orders.user_id','services.user_id')))
            ->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_accounts as a')->whereColumn('a.server_id','domains_domains.server_id')
                ->whereColumn('a.account_fingerprint','domains_domains.account_fingerprint')->whereColumn('a.registrar_key','domains_domains.registrar_key'));
    }
    /** Decode retained enabled templates once per query, not once per managed domain. */
    private static function unreadableProfileIds(): array
    { return WatchProfile::where('enabled',true)->get()->filter(fn($p)=>!self::usable($p))->modelKeys(); }
    public static function configured($q,string $domainColumn,string $ownerColumn): mixed
    {
        return $q->whereExists(fn($c)=>$c->selectRaw('1')->from('domains_managed_public_config as c')
            ->join('domains_watch_profiles as p','p.id','=','c.profile_id')->where('p.enabled',true)->whereNotIn('p.id',self::unreadableProfileIds())
            ->whereColumn('c.domain_id',$domainColumn)->whereColumn('c.user_id',$ownerColumn));
    }
    public static function affected(int $now): Builder
    {
        if(!Schema::hasTable('domains_managed_public_config') || !Schema::hasTable('domains_watch_profiles')){return self::eligible($now)->whereRaw('1=0');}
        return self::eligible($now)->whereNotExists(fn($c)=>$c->selectRaw('1')->from('domains_managed_public_config as c')
            ->join('domains_watch_profiles as p','p.id','=','c.profile_id')->where('p.enabled',true)->whereNotIn('p.id',self::unreadableProfileIds())
            ->whereColumn('c.domain_id','domains_domains.id')->whereColumn('c.user_id','domains_domains.user_id'));
    }
    public static function usable(?WatchProfile $profile): bool
    { return $profile?->enabled && $profile->readProfile()!==null; }
    /** Locks and native ownership were captured by enrollment. Missing or conflicting mappings require staff intent. */
    public static function proven(array $l,?object $watch,?ManagedPublicConfig $config,$sources,$maps): array
    {
        $d=$l['domain'];
        $current=$watch && in_array($watch->state,['active','paused','linked'],true)
            && ($watch->state!=='linked' || (int)$watch->managed_domain_id===(int)$d->id);
        if($current) {
            $profile=WatchProfile::whereKey($watch->ns_profile_id)->lockForUpdate()->first();
            if(self::usable($profile)){return [$profile,$watch->expected_dns,'predecessor_watch'];}
        }
        if($config?->expected_dns==='external' || ($current && $watch->expected_dns==='external') || $sources->isEmpty() || $sources->count()>=100){return [null,null,null];}
        $ids=[];
        foreach($sources as $source){
            $s=$l['services']->firstWhere('id',$source->service_id);$m=$maps[$source->map_id]??null;
            $o=$l['orders']->firstWhere('id',$s?->order_id);$p=$l['products']->firstWhere('id',$s?->product_id);
            $props=$l['relatedProperties']->where('model_id',$s?->id)->where('key',$m?->property_key);
            if(!$s || !$o || !$p || !$m || !$m->enabled || !$m->expected_ns_profile_id || $p->server_id===null || !in_array($source->state,['watch','managed_link'],true)
                || (int)$source->user_id!==(int)$d->user_id || ($source->state==='managed_link' && (int)$source->managed_domain_id!==(int)$d->id)
                || (int)$s->user_id!==(int)$d->user_id || (int)$o->user_id!==(int)$d->user_id || $s->status!=='active'
                || (int)$s->product_id!==(int)$m->product_id || (int)$source->map_revision!==(int)$m->revision
                || $source->registrable_name!==$d->domain || ($source->hostname!==$d->domain && !str_ends_with($source->hostname,'.'.$d->domain)) || $props->count()!==1){return [null,null,null];}
            try{if(DnsQuery::hostname($props->sole()->value)!==$source->hostname){return [null,null,null];}}
            catch(\InvalidArgumentException|\TypeError){return [null,null,null];}
            $ids[]=(int)$m->expected_ns_profile_id;
        }
        $ids=array_values(array_unique($ids));if(count($ids)!==1){return [null,null,null];}
        $profile=WatchProfile::whereKey($ids[0])->lockForUpdate()->first();
        return self::usable($profile)?[$profile,'platform','hosting_product']:[null,null,null];
    }
    public static function assign(ManagedPublicConfig $config,WatchProfile $profile,?string $expected,?int $actor,string $source,int $now): void
    {
        $before=$config->revision;
        $config->fill(['profile_id'=>$profile->id,'expected_dns'=>$expected,'revision'=>$before+1,'edited_by'=>$actor,'reason'=>'DNS template assigned'])->save();
        WatchAudit::create(['subject_type'=>'managed_dns','subject_id'=>$config->domain_id,'user_id'=>$config->user_id,'actor_id'=>$actor,
            'event'=>'dns_template_assigned','reason'=>'DNS template assigned','before_revision'=>(string)$before,'after_revision'=>(string)$config->revision,
            'data'=>['profile_id'=>$profile->id,'source'=>$source],'created_at'=>now()]);
        // Invalidate an old read claim. A returned read can no longer commit against the new template.
        DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$config->domain_id)->whereNull('retired_at')
            ->update(['claim'=>null,'lease_until'=>null,'safe_code'=>DB::raw("CASE WHEN safe_code = 'awaiting_acceptance' THEN 'awaiting_acceptance' ELSE 'dns_not_configured' END"),'attempts'=>0,'backoff_until'=>null,'due_at'=>$now]);
        // A newly configured family gets a fresh first-check window. Other pending domains keep their outage clock.
        if(Schema::hasTable('domains_watch_schedule')){
            $others=ManagedPublicReconciliation::eligible()->where('domain_id','<>',$config->domain_id)
                ->where(fn($q)=>$q->where('due_at','<=',$now)->orWhere('attempts','>',0)->orWhereNotNull('claim'))->selectRaw('1');
            DB::table('domains_watch_schedule')->where('scope','health_managed_dns')->whereNotExists($others)->update(['cursor'=>$now]);
        }
    }
}
