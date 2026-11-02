<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Dns\DnsQuery;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation,ManagedPublicConfig,WatchProfile,WatchAudit};
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
    /** Same effective choice for dispatch, health and setup notices, including before the daily sweep. */
    public static function configured($q,string $domainColumn,string $ownerColumn,bool $missing=false,?int $profile=null): mixed
    {
        $usable=WatchProfile::where('enabled',true)->get()->filter(fn($p)=>self::usable($p))->modelKeys();
        $profiles=$profile===null?$usable:array_values(array_intersect($usable,[$profile]));
        $comparison=fn($c)=>$c->selectRaw('1')->from('domains_domains as configured_domain')
            ->leftJoin('domains_managed_public_config as c',fn($j)=>$j->on('c.domain_id','=','configured_domain.id')->on('c.user_id','=','configured_domain.user_id'))
            ->leftJoin('settings as dns_default',fn($j)=>$j->on('dns_default.settingable_id','=','configured_domain.server_id')
                ->where('dns_default.settingable_type',(new \App\Models\Server)->getMorphClass())->where('dns_default.key',NewDomainDns::KEY))
            ->whereColumn('configured_domain.id',$domainColumn)->whereColumn('configured_domain.user_id',$ownerColumn)
            ->where(fn($p)=>$p->whereIn('c.profile_id',$profiles)->orWhere(fn($d)=>$d->where(fn($c)=>$c->whereNull('c.profile_id')->orWhereNotIn('c.profile_id',$usable))->whereIn('dns_default.value',array_map('strval',$profiles))));
        return $missing?$q->whereNotExists($comparison):$q->whereExists($comparison);
    }
    public static function affected(int $now): Builder
    {
        if(!Schema::hasTable('domains_managed_public_config') || !Schema::hasTable('domains_watch_profiles')){return self::eligible($now)->whereRaw('1=0');}
        return self::configured(self::eligible($now),'domains_domains.id','domains_domains.user_id',true);
    }
    /** A usable per-domain assignment wins. Defaults remain live account choices. */
    public static function profile(Domain $domain,?ManagedPublicConfig $config,bool $lock=false): ?WatchProfile
    {
        $profile=$config?->profile_id?WatchProfile::whereKey($config->profile_id)->when($lock,fn($q)=>$q->lockForUpdate())->first():null;
        if(self::usable($profile)){return $profile;}
        $id=\App\Models\Server::find($domain->server_id)?->settings()->where('key',NewDomainDns::KEY)->first()?->value;
        $profile=$id?WatchProfile::whereKey($id)->when($lock,fn($q)=>$q->lockForUpdate())->first():null;
        return self::usable($profile)?$profile:null;
    }
    /** Caller holds the current holding lock. Reads establish missing import history once; choices always win. */
    public static function retainBaseline(Domain $domain,?ManagedPublicConfig $config,?NameserverEvidence $read=null,?iterable $operations=null): bool
    {
        try {
            if($config?->edited_by!==null || ObservationProjection::registrationNameservers($domain)){return false;}
            $operations??=$domain->operations()->where('authority_generation',$domain->authority_generation)->where('account_fingerprint',$domain->account_fingerprint)
                ->where('service_id',$domain->service_id)->where('server_id',$domain->server_id)->where('registrar_key',$domain->registrar_key)
                ->where('state','succeeded')->whereIn('action',['register','transfer','nameservers'])->get();
            foreach($operations as $op){
                if($op->state!=='succeeded' || $op->authority_generation!==$domain->authority_generation || $op->account_fingerprint!==$domain->account_fingerprint
                    || (int)$op->service_id!==(int)$domain->service_id || (int)$op->server_id!==(int)$domain->server_id || $op->registrar_key!==$domain->registrar_key){continue;}
                if(in_array($op->action,['register','transfer'],true) || ($op->action==='nameservers' && ManagementEvidence::resolved($op,$domain))){return false;}
            }
            // Prefer a previously stored identity-valid read on upgrade; never replace a valid baseline.
            $read=ObservationProjection::nameservers($domain)??$read;
            if(!$read || $read->state!=='observed'){return false;}
            Domain::withoutTimestamps(fn()=>$domain->update(['observation'=>ObservationProjection::recordNameservers($domain,$read)]));
            return true;
        }
        catch(\Illuminate\Contracts\Encryption\DecryptException){return false;}
    }
    /** Staff intent wins; otherwise use the newest retained choice before acquisition evidence or inherited defaults. */
    public static function expected(?ManagedPublicConfig $config,?WatchProfile $profile,Domain $domain,?Operation $acquisition,?Operation $change): ?string
    {
        if(!self::usable($profile)){return $config?->expected_dns;}
        // Staff > retained choices > acquisition baseline > automatic inheritance. Refreshes never choose intent.
        $intent=['order'=>[-1,0,0],'expected'=>null];
        $offer=static function(?string $expected,int $source,int $at,int $management=0)use(&$intent):void{
            if([$source,$at,$management]>$intent['order']){$intent=['order'=>[$source,$at,$management],'expected'=>$expected];}
        };
        // An automatic platform comparison is not a choice; missing legacy history must stay quiet.
        if($config && ($config->edited_by!==null || $config->expected_dns!=='platform')){$offer($config->expected_dns,$config->edited_by!==null?3:0,$config->updated_at?->getTimestamp()??0);}
        $nameservers=static function(?array $chosen,int $source,int $at,int $management=0)use($profile,$offer):void{
            if(!$chosen){return;}sort($chosen,SORT_STRING);
            $offer($chosen===$profile->profile()->nameservers?'platform':'external',$source,$at,$management);
        };
        foreach([$acquisition,$change]as$op){
            if(!$op){continue;}$at=($op->completed_at??$op->created_at)?->getTimestamp()??0;
            $management=$op->action==='nameservers'?1:0;
            try{
                if($management){
                    if(!ManagementEvidence::resolved($op,$domain)){continue;}
                }
                if($op->action==='transfer'){$offer('external',2,$at);}
                else{$nameservers($op->payload['nameservers']??null,2,$at,$management);}
            }
            catch(\Illuminate\Contracts\Encryption\DecryptException){$offer(null,2,$at,$management);}
        }
        try{
            // This retained baseline precedes management refreshes; current sections are observations only.
            $stored=ObservationProjection::registrationNameservers($domain);
            if($stored){
                $nameservers($stored->nameservers,1,(new \DateTimeImmutable($stored->at))->getTimestamp());
            }
        }
        catch(\Illuminate\Contracts\Encryption\DecryptException){$offer(null,1,PHP_INT_MAX);}
        return $intent['expected'];
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
