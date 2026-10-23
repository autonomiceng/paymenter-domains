<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicConfig,WatchName,WatchSource,WatchProfile,WatchProductMap,WatchAudit};
/** One local relink transaction. Works for acquired and adopted holdings, independent of A3's marker. */
final class ManagedPublicEnrollment
{
    public function reconcile(int $id,int $owner,int $now,?int $defaultProfile=null): ?bool
    {
        $seed=Domain::whereKey($id)->where('user_id',$owner)->first();if (!$seed) { return null; }
        $peek=WatchSource::where('user_id',$owner)->where('registrable_name',$seed->domain)->where('state','watch')->orderBy('id')->limit(100)->get();
        return DB::transaction(function()use($seed,$peek,$id,$owner,$now,$defaultProfile):?bool {
            $changed=false;
            try { $l=HoldingLocks::take($seed,additionalServices:$peek->pluck('service_id')->all());HoldingAnchor::resolve($l); }
            catch (\DomainException) { return null; }
            if (!ManagedPublicTarget::valid($l,$now)) { return null; }$d=$l['domain'];
            $maps=WatchProductMap::whereKey($peek->pluck('map_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $candidate=WatchName::where('user_id',$owner)->where('canonical_name',$d->domain)->first();
            $config=ManagedPublicConfig::whereKey($id)->lockForUpdate()->first();
            if ($config && (int)$config->user_id!==$owner) { return null; }
            $profileId=$config?$config->profile_id:($candidate?->ns_profile_id??$defaultProfile);
            $profile=$profileId?WatchProfile::whereKey($profileId)->lockForUpdate()->first():null;
            $watch=$candidate?WatchName::whereKey($candidate->id)->lockForUpdate()->firstOrFail():null;
            if ($watch && $watch->ns_profile_id!==$candidate->ns_profile_id) { throw new \DomainException('Managed predecessor changed.'); }
            $sources=WatchSource::where('user_id',$owner)->where('registrable_name',$d->domain)->where('state','watch')->orderBy('id')->limit(100)->lockForUpdate()->get();
            if ($sources->pluck('id')->all()!==$peek->pluck('id')->all()) { throw new \DomainException('Managed sources changed.'); }
            foreach($sources as $source) {
                $s=$l['services']->firstWhere('id',$source->service_id);$map=$maps[$source->map_id]??null;$o=$l['orders']->firstWhere('id',$s?->order_id);
                $props=$l['relatedProperties']->where('model_id',$s?->id)->where('key',$map?->property_key);
                $valid=$s && $o && $map && $watch && (int)$source->watch_id===(int)$watch->id && (in_array($watch->state,['active','paused'],true) || ($watch->state==='linked' && (int)$watch->managed_domain_id===$id))
                    && (int)$s->user_id===$owner && (int)$o->user_id===$owner && $s->status==='active' && $map->enabled
                    && (int)$s->product_id===(int)$map->product_id && (int)$map->revision===(int)$source->map_revision && $props->count()===1;
                try { $valid=$valid && \Paymenter\Extensions\Servers\Domains\Dns\DnsQuery::hostname($props->sole()->value)===$source->hostname; }
                catch (\InvalidArgumentException|\TypeError) { $valid=false; }
                $changed=true;
                if (!$valid) { $source->retire('managed_source_changed');continue; }
                $attrs=$source->only(['user_id','service_id','map_id','map_revision','hostname','registrable_name','last_seen_at']);
                $source->retire('registration_managed');
                WatchSource::create([...$attrs,'state'=>'managed_link','managed_domain_id'=>$id,'source_digest'=>AdoptionMapping::stamp([$source->id,$id,$d->authority_generation])]);
            }
            if (!$config) {
                $changed=true;
                $config=ManagedPublicConfig::create(['domain_id'=>$id,'user_id'=>$owner,'profile_id'=>$profile?->id,'expected_dns'=>$watch?->expected_dns,
                    'notice_preferences'=>array_diff_key($watch?->notice_preferences??[],['expiry'=>true]),'origin_watch_id'=>$watch?->id,
                    'dns_episode'=>$watch?['predecessor_watch_id'=>$watch->id,'predecessor_observation_id'=>$watch->last_observation_id]:null,
                    'reason'=>'managed_enrollment']);
            }
            if ($watch && in_array($watch->state,['active','paused'],true)) {
                $changed=true;$before=$watch->revision;
                $watch->fill(['state'=>'linked','registration'=>'platform','managed_domain_id'=>$id,'notice_preferences'=>[...($watch->notice_preferences??[]),'expiry'=>false],'revision'=>$before+1])->save();
                WatchAudit::create(['subject_type'=>'watch','subject_id'=>$watch->id,'user_id'=>$owner,'event'=>'registration_managed','reason'=>'registration_managed',
                    'before_revision'=>(string)$before,'after_revision'=>(string)$watch->revision,'data'=>['domain_id'=>$id,'predecessor_observation_id'=>$watch->last_observation_id],'created_at'=>now()]);
            }
            if ($watch) {
                DB::table('domains_watch_work')->where('watch_id',$watch->id)->update(['claim'=>null,'lease_until'=>null,'safe_code'=>'registration_managed']);
                // W4 owns delivery execution. Suppress only definitely unsent rows if its schema is installed.
                if (Schema::hasTable('domains_watch_notices')) {
                    $ids=DB::table('domains_watch_notices')->where('watch_id',$watch->id)->where('state','open')->orderBy('id')->lockForUpdate()->pluck('id');
                    if (Schema::hasTable('domains_watch_deliveries')) { DB::table('domains_watch_deliveries')->whereIn('notice_id',$ids)->whereIn('state',['reserved','render_failed'])->update(['state'=>'suppressed','safe_code'=>'registration_managed']); }
                    DB::table('domains_watch_notices')->whereIn('id',$ids)->update(['state'=>'suppressed','resolved_at'=>$now]);
                }
            }
            $retired=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('generation','<>',$d->authority_generation)->whereNull('retired_at')->update(['retired_at'=>$now,'claim'=>null,'lease_until'=>null,'safe_code'=>'target_changed']);
            $changed=$changed || $retired>0;
            foreach(['delegation','points']as$kind) {
                $q=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('generation',$d->authority_generation)->where('kind',$kind);
                $inserted=$q->insertOrIgnore(['domain_id'=>$id,'user_id'=>$owner,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint,'kind'=>$kind,
                    'due_at'=>$now,'target_stamp'=>str_repeat('0',64),'origin_watch_id'=>$watch?->id,'origin_observation_id'=>$watch?->last_observation_id]);
                $changed=$changed || $inserted>0;
                $w=$q->lockForUpdate()->first();
                if ((int)$w->user_id!==$owner || $w->account_fingerprint!==$d->account_fingerprint) { throw new ManagedPublicIdentityChanged(); }
                if ($w->safe_code==='awaiting_acceptance' || $w->retired_at!==null) {
                    $changed=true;$q->update(['safe_code'=>'ready','retired_at'=>null,'due_at'=>$now,'domain_revision'=>$d->confirmed_revision,'config_revision'=>$config->revision,
                        'profile_id'=>$profile?->id,'profile_revision'=>$profile?->revision,'claim'=>null,'lease_until'=>null]);
                }
            }
            return $changed;
        });
    }
}
