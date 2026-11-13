<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Order,Product,Service,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Held,PslSnapshot,Registrable};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,WatchAudit,WatchName,WatchProductMap,WatchSource};
/** Local discovery only. Native rows and their saved primary property are the source, never ownership proof. */
final class WatchDiscovery
{
    private ?PslSnapshot $parsed=null;
    public function __construct(private PublicSuffixList $psl,private PslValidator $validator,private Clock $clock) {}
    public function discover(int $id,int $productId): void
    {
        OperationClaims::outside();$now=$this->clock->now()->getTimestamp();if($id<1 || $productId<1 || $now<0){return;}
        $peek=Service::find($id);
        DB::transaction(function()use($peek,$id,$productId,$now):void {
            // Match the native-before-watch order used by the observation and notice guards.
            if($peek){$owner=User::whereKey($peek->user_id)->lockForUpdate()->first();if(!$owner||DB::table(UserDeletionSchema::TABLE)->where('user_id',$owner->id)->exists()){return;}Order::whereKey($peek->order_id)->lockForUpdate()->first();}
            $service=Service::whereKey($id)->lockForUpdate()->first();
            if($service && (!$peek || (int)$service->user_id!==(int)$peek->user_id || (int)$service->order_id!==(int)$peek->order_id)){throw new \DomainException('Discovery native identity changed.');}
            $product=Product::whereKey($service?->product_id??$productId)->lockForUpdate()->first();
            $map=WatchProductMap::where('product_id',$productId)->lockForUpdate()->first();
            $old=WatchSource::where('service_id',$id)->lockForUpdate()->first();
            // An old product turn must never remove a new product's already reconciled source.
            if((int)$service?->product_id!==$productId && (!$old || (int)$old->map_id!==(int)$map?->id)){return;}
            $eligible=$service && $product && $map && $map->enabled && (int)$service->product_id===$productId
                && $product->server && $product->server->extension!=='Domains'
                && !$product->settings()->where('key','domains_catalogue')->exists()
                && User::whereKey($service->user_id)->exists()
                && Order::whereKey($service->order_id)->where('user_id',$service->user_id)->exists()
                && in_array($service->status,['active','suspended'],true);
            if(!$eligible){$this->remove($old,'hosting_unavailable',$now);return;}
            $values=$service->properties()->where('key',$map->property_key)->lockForUpdate()->pluck('value')->all();
            $digest=$this->digest($service,$map,$values);
            $same=$old && (int)$old->user_id===(int)$service->user_id && (int)$old->map_id===(int)$map->id
                && (int)$old->map_revision===(int)$map->revision && hash_equals($old->source_digest,$digest);
            $snapshot=DB::table('domains_psl_snapshots')->where('state','current')->lockForUpdate()->first();
            $usable=$snapshot && $now>=(int)$snapshot->validated_at && $now<=(int)$snapshot->validated_at+PublicSuffixList::MAX_AGE_DAYS*86400 && $this->psl->parserUsable($snapshot);
            // A stale list stops new discovery, while retained identities and lifecycle cleanup still work.
            $known=!$usable && $same && $old->state!=='held'?WatchName::where('user_id',$service->user_id)->where('canonical_name',$old->registrable_name)->lockForUpdate()->first():null;
            // A retained managed link rechecks its managed target with the name it already validated.
            $retainedLink=!$usable && $same && $old->state==='managed_link' && !$known;
            if(!$usable && $same && $old->state!=='held' && !$known && !$retainedLink){$this->seen($old,$now);$this->sync($old->watch_id,$now);return;}
            $result=$known||$retainedLink?new Registrable($old->hostname,$old->registrable_name,$known?->psl_revision??''):new Held('psl_unavailable',$snapshot?->revision??str_repeat('0',64));
            if($usable){
                if($this->parsed?->revision!==$snapshot->revision){$this->parsed=new PslSnapshot(PublicSuffixList::body($snapshot),$this->validator);}
                $value=count($values)===1 && is_string($values[0])?$values[0]:null;
                $result=$value===null?new Held('source_invalid',$snapshot->revision):(new RegistrableNameResolver)->resolve($value,$this->parsed);
                if($result instanceof Registrable && preg_match('/(?:^|\.)xn--/i',$result->hostname)){$result=new Held('idn_held',$snapshot->revision);}
            }
            if($result instanceof Held){
                $this->held($old,$service,$map,$digest,$result->safe_code,$now);return;
            }
            $owner=(int)$service->user_id;$name=$result->name;
            // The ledger and its native service must BOTH still belong to this customer. Foreign matches are invisible.
            $domain=Domain::where('user_id',$owner)->where('reservation_name',$name)->whereIn('status',[...WatchManagedTarget::MANAGED,...WatchManagedTarget::PROGRESS])
                ->where(fn($q)=>$q->whereNull('provenance')->orWhere('provenance','<>','adoption_pending'))
                ->whereHas('service',fn($q)=>$q->where('user_id',$owner))->lockForUpdate()->first();
            if($domain){
                $native=Service::whereKey($domain->service_id)->lockForUpdate()->first();
                if(!$native || (int)$native->user_id!==$owner){throw new \DomainException('Managed discovery owner changed.');}
                $watch=WatchName::where('user_id',$owner)->where('canonical_name',$name)->lockForUpdate()->first();
                $attrs=['user_id'=>$owner,'service_id'=>$id,'map_id'=>$map->id,'map_revision'=>$map->revision,'hostname'=>$result->hostname,'registrable_name'=>$name,
                    'state'=>'managed_link','managed_domain_id'=>$domain->id,'source_digest'=>$digest,'last_seen_at'=>$now];
                // The watch is linked below, so replacing its last source must not retire it first.
                $this->source($old,$attrs,$now,$watch?->id);
                if($watch){$watch->refresh();$this->change($watch,['state'=>'linked','managed_domain_id'=>$domain->id,'registration'=>in_array($domain->status,WatchManagedTarget::MANAGED,true)?'platform':null],'registration_managed',$now);$this->stop($watch,'registration_managed',$now);}
                DB::afterCommit(fn()=>app(WatchWork::class)->enrollManaged((int)$domain->id,$owner));
            }elseif($retainedLink){
                // Without a current suffix list, a link that lost its managed target waits rather than starting a new watch.
                $this->held($old,$service,$map,$digest,'psl_unavailable',$now);return;
            }else{
                $watch=WatchName::where('user_id',$owner)->where('canonical_name',$name)->lockForUpdate()->first();
                if($service->status==='suspended' && (!$same || $old->state!=='watch')){$this->held($old,$service,$map,$digest,'service_suspended',$now);return;}
                if(!$watch){$watch=WatchName::create(['user_id'=>$owner,'canonical_name'=>$name,'state'=>'active','psl_revision'=>$result->psl_revision,'notice_preferences'=>[]]);$this->audit($watch,'discovery_added',0,$now);}
                if(in_array($watch->state,['retired','linked'],true)){$this->change($watch,['state'=>self::staffPaused($watch)?'paused':'active','managed_domain_id'=>null,'registration'=>null,'registration_evidence'=>null,'psl_revision'=>$result->psl_revision],'discovery_returned',$now);}
                elseif($watch->psl_revision!==$result->psl_revision){$this->change($watch,['psl_revision'=>$result->psl_revision],'discovery_suffix_changed',$now);}
                $this->source($old,['user_id'=>$owner,'service_id'=>$id,'map_id'=>$map->id,'map_revision'=>$map->revision,'hostname'=>$result->hostname,'registrable_name'=>$name,
                    'state'=>'watch','watch_id'=>$watch->id,'source_digest'=>$digest,'last_seen_at'=>$now],$now);
                $this->sync((int)$watch->id,$now);
            }
            // Property writers participate in the native transaction; reject reentrant stale-value writes too.
            $current=$service->fresh();$currentMap=$map->fresh();
            if(!$current || !$currentMap?->enabled || $current->status!==$service->status || $snapshot?->revision!==$this->psl->revision()
                || $digest!==$this->digest($current,$currentMap,$service->properties()->where('key',$currentMap->property_key)->pluck('value')->all())){throw new \DomainException('Discovery source changed.');}
        },3);
    }
    private function digest(Service $s,WatchProductMap $map,array $values): string
    {return hash('sha256',json_encode([$s->id,$s->user_id,$s->order_id,$s->product_id,$map->id,$map->revision,$map->property_key,$values],JSON_THROW_ON_ERROR));}
    private function seen(WatchSource $source,int $now): void
    {
        // A discovery heartbeat is not a change of source identity or public evidence.
        DB::table('domains_watch_sources')->where('id',$source->id)->update(['last_seen_at'=>$now]);
    }
    private function source(?WatchSource $old,array $attrs,int $now,?int $linking=null): void
    {
        $identity=array_diff_key($attrs,['last_seen_at'=>true]);
        if($old && $old->only(array_keys($identity))==$identity){$this->seen($old,$now);return;}
        $previous=$old?->watch_id;$old?->retire('hosting_source_changed');
        WatchSource::create($attrs);
        if($previous && $previous!==($attrs['watch_id']??null) && (int)$previous!==$linking){$this->sync((int)$previous,$now);}
    }
    private function held(?WatchSource $old,Service $s,WatchProductMap $map,string $digest,string $code,int $now): void
    {
        $this->source($old,['user_id'=>$s->user_id,'service_id'=>$s->id,'map_id'=>$map->id,'map_revision'=>$map->revision,'hostname'=>null,'registrable_name'=>null,
            'state'=>'held','safe_code'=>$code,'source_digest'=>$digest,'last_seen_at'=>$now],$now);
    }
    private function remove(?WatchSource $old,string $reason,int $now): void
    {if($old){$watch=$old->watch_id;$old->retire($reason);$this->sync($watch,$now);}}
    /** Whether staff intent is paused: the latest staff pause or resume, or a pause saved before staff writes were recorded. */
    public static function staffPaused(WatchName $watch): bool
    {
        $last=WatchAudit::where('subject_type','watch')->where('subject_id',$watch->id)->whereIn('event',['staff_pause','staff_resume'])->orderByDesc('id')->value('event');
        return $last==='staff_pause' || ($last===null && $watch->state==='paused' && !WatchAudit::where('subject_type','watch')->where('subject_id',$watch->id)->where('event','discovery_paused')->exists());
    }
    private function sync(?int $id,int $now): void
    {
        if(!$id){return;}$watch=WatchName::whereKey($id)->lockForUpdate()->first();if(!$watch || $watch->state==='linked'){return;}
        $sources=WatchSource::where('watch_id',$id)->where('state','watch');
        $state=!$sources->exists()?'retired':(!self::hostingActive($id) || self::staffPaused($watch)?'paused':'active');
        $this->change($watch,['state'=>$state],'discovery_'.$state,$now);
        if($state!=='active'){$this->stop($watch,'discovery_'.$state,$now);}
        else{DB::afterCommit(fn()=>app(WatchWork::class)->enrollWatch($id,(int)$watch->user_id));}
    }
    /** Whether Resume can still change a paused watch: staff paused it, or an active hosting service can run it now. */
    public static function canResume(WatchName $watch): bool
    {return $watch->state==='paused' && (self::staffPaused($watch) || self::hostingActive((int)$watch->id));}
    /** Whether an active hosting service on an enabled product line still names this watch. Without one, monitoring stays paused. */
    public static function hostingActive(int $id): bool
    {
        return WatchSource::where('watch_id',$id)->where('state','watch')->whereExists(fn($q)=>$q->selectRaw('1')->from('services as s')->join('orders as o','o.id','=','s.order_id')->join('domains_watch_product_maps as m','m.product_id','=','s.product_id')
            ->whereColumn('s.id','domains_watch_sources.service_id')->whereColumn('s.user_id','domains_watch_sources.user_id')->whereColumn('o.user_id','s.user_id')
            ->whereColumn('m.id','domains_watch_sources.map_id')->whereColumn('m.revision','domains_watch_sources.map_revision')->where('m.enabled',true)->where('s.status','active'))->exists();
    }
    private function change(WatchName $watch,array $attrs,string $event,int $now): void
    {
        $watch->fill($attrs);if(!$watch->isDirty()){return;}$before=$watch->revision;$watch->revision=$before+1;$watch->save();$this->audit($watch,$event,$before,$now);
    }
    private function audit(WatchName $watch,string $event,int $before,int $now): void
    {WatchAudit::create(['subject_type'=>'watch','subject_id'=>$watch->id,'user_id'=>$watch->user_id,'event'=>$event,'before_revision'=>(string)$before,'after_revision'=>(string)$watch->revision,'data'=>[],'created_at'=>new \DateTimeImmutable('@'.$now)]);}
    private function stop(WatchName $watch,string $reason,int $now): void
    {
        DB::table('domains_watch_work')->where('watch_id',$watch->id)->update(['claim'=>null,'lease_until'=>null,'safe_code'=>$reason]);
        $ids=DB::table('domains_watch_notices')->where('watch_id',$watch->id)->where('state','open')->lockForUpdate()->pluck('id');
        DB::table('domains_watch_deliveries')->whereIn('notice_id',$ids)->whereIn('state',['reserved','render_failed'])->update(['state'=>'suppressed','safe_code'=>$reason]);
        $handed=DB::table('domains_watch_deliveries')->whereIn('notice_id',$ids)->whereNotIn('state',['reserved','render_failed','suppressed'])->pluck('notice_id');
        DB::table('domains_watch_notices')->whereIn('id',$ids)->whereIn('id',$handed)->update(['state'=>'resolved','resolved_at'=>$now]);
        DB::table('domains_watch_notices')->whereIn('id',$ids)->whereNotIn('id',$handed)->update(['state'=>'suppressed','resolved_at'=>$now]);
    }
}
