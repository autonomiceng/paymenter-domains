<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Service,Order,Product,Property};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\DnsQuery;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,WatchName,WatchSource,WatchProductMap,WatchProfile,ManagedPublicConfig};
/** Local display identities, loaded in batches. Digests grant no holding or write authority. */
final class DomainSummaryContext
{
    public array $domains=[],$watches=[],$sources=[],$services=[],$orders=[],$products=[],$maps=[],$profiles=[],$configs=[],$properties=[];
    public bool $staff=false;
    public function __construct(public int $owner,array $domainIds,array $watchIds)
    {
        if (!$domainIds && !$watchIds) { return; }
        $native=fn($q)=>$q->where('user_id',$owner)->whereHas('order',fn($q)=>$q->where('user_id',$owner));
        $this->domains=Domain::where('user_id',$owner)->whereIn('id',$domainIds)->whereHas('service',$native)->get()->keyBy('id')->all();
        $this->watches=WatchName::where('user_id',$owner)->whereIn('id',$watchIds)->whereIn('state',['active','paused','retired'])->whereNull('managed_domain_id')->get()->keyBy('id')->all();
        $names=array_map(fn($w)=>$w->canonical_name,$this->watches);
        // Pending reservations also suppress a duplicate name in the related section.
        $managed=Domain::where('user_id',$owner)->whereIn('domain',$names)->whereHas('service',$native)->pluck('domain')->all();
        $this->watches=array_filter($this->watches,fn($w)=>!in_array($w->canonical_name,$managed,true));
        $this->sources=WatchSource::where('user_id',$owner)->where(fn($q)=>$q->whereIn('watch_id',array_keys($this->watches))->orWhereIn('managed_domain_id',array_keys($this->domains))->orWhereIn('registrable_name',array_map(fn($d)=>$d->domain,$this->domains)))->whereIn('state',['watch','managed_link'])->whereIn('id',DB::query()->fromSub(DB::table('domains_watch_sources')->where('user_id',$owner)->where(fn($q)=>$q->whereIn('watch_id',array_keys($this->watches))->orWhereIn('managed_domain_id',array_keys($this->domains))->orWhereIn('registrable_name',array_map(fn($d)=>$d->domain,$this->domains)))->selectRaw('id, row_number() over (partition by watch_id,managed_domain_id order by id) as position'),'bounded')->where('position','<=',101)->select('id'))->orderBy('id')->get()->all();
        $ids=[...array_map(fn($d)=>$d->service_id,$this->domains),...array_map(fn($s)=>$s->service_id,$this->sources)];
        $this->services=Service::whereIn('id',$ids)->where('user_id',$owner)->get()->keyBy('id')->all();
        $this->orders=Order::whereIn('id',array_map(fn($s)=>$s->order_id,$this->services))->where('user_id',$owner)->get()->keyBy('id')->all();
        $this->products=Product::whereIn('id',array_map(fn($s)=>$s->product_id,$this->services))->get()->keyBy('id')->all();
        $this->maps=WatchProductMap::whereIn('id',array_map(fn($s)=>$s->map_id,$this->sources))->get()->keyBy('id')->all();
        $this->configs=ManagedPublicConfig::where('user_id',$owner)->whereIn('domain_id',array_keys($this->domains))->get()->keyBy('domain_id')->all();
        $this->profiles=WatchProfile::whereIn('id',[...array_map(fn($w)=>$w->ns_profile_id,$this->watches),...array_map(fn($c)=>$c->profile_id,$this->configs)])->get()->keyBy('id')->all();
        $this->properties=Property::where('model_type',(new Service)->getMorphClass())->whereIn('model_id',$ids)->whereIn('key',[...array_map(fn($m)=>$m->property_key,$this->maps),'domain'])->whereIn('id',DB::query()->fromSub(DB::table('properties')->where('model_type',(new Service)->getMorphClass())->whereIn('model_id',$ids)->selectRaw('id, row_number() over (partition by model_type,model_id,'.$this->propertyKey().' order by id) as position'),'bounded')->where('position','<=',2)->select('id'))->orderBy('id')->get()->groupBy('model_id')->all();
    }
    /** Staff-only watch context; every identity is still checked against its row's owner. */
    public static function staffWatches(array $ids): self
    {
        abort_unless(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can(StaffWatchWrites::VIEW),403);
        if (count($ids)>250) { throw new \InvalidArgumentException('At most 250 watches per batch.'); }
        $c=new self(0,[],[]);$c->staff=true;
        $c->watches=WatchName::whereIn('id',$ids)->whereIn('user_id',\App\Models\User::select('id'))->whereIn('state',['active','paused','retired'])->whereNull('managed_domain_id')->get()->keyBy('id')->all();
        $owners=array_unique(array_map(fn($w)=>$w->user_id,$c->watches));$names=array_map(fn($w)=>$w->canonical_name,$c->watches);
        $managed=Domain::whereIn('user_id',$owners)->whereIn('domain',$names)->whereHas('service',fn($q)=>$q->whereColumn('services.user_id','domains_domains.user_id')->whereHas('order',fn($q)=>$q->whereColumn('orders.user_id','services.user_id')))->get(['user_id','domain']);
        $suppressed=[];foreach ($managed as $d) { $suppressed[$d->user_id][$d->domain]=true; }
        $c->watches=array_filter($c->watches,fn($w)=>!isset($suppressed[$w->user_id][$w->canonical_name]));
        $sources=WatchSource::whereIn('watch_id',array_keys($c->watches))->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_watch_names as w')->whereColumn('w.id','domains_watch_sources.watch_id')->whereColumn('w.user_id','domains_watch_sources.user_id'));
        $bounded=DB::query()->fromSub((clone $sources)->selectRaw('domains_watch_sources.id, row_number() over (partition by watch_id,managed_domain_id order by domains_watch_sources.id) as position'),'bounded')->where('position','<=',101)->select('id');
        $c->sources=$sources->whereIn('state',['watch','managed_link'])->whereIn('id',$bounded)->orderBy('id')->get()->all();
        $serviceIds=array_map(fn($s)=>$s->service_id,$c->sources);
        $c->services=Service::whereIn('id',$serviceIds)->get()->keyBy('id')->all();
        $c->orders=Order::whereIn('id',array_map(fn($s)=>$s->order_id,$c->services))->get()->keyBy('id')->all();
        $c->products=Product::whereIn('id',array_map(fn($s)=>$s->product_id,$c->services))->get()->keyBy('id')->all();
        $c->maps=WatchProductMap::whereIn('id',array_map(fn($s)=>$s->map_id,$c->sources))->get()->keyBy('id')->all();
        $c->profiles=WatchProfile::whereIn('id',array_map(fn($w)=>$w->ns_profile_id,$c->watches))->get()->keyBy('id')->all();
        $keys=array_map(fn($m)=>$m->property_key,$c->maps);
        $c->properties=Property::where('model_type',(new Service)->getMorphClass())->whereIn('model_id',$serviceIds)->whereIn('key',[...$keys,'domain'])->whereIn('id',DB::query()->fromSub(DB::table('properties')->where('model_type',(new Service)->getMorphClass())->whereIn('model_id',$serviceIds)->selectRaw('id, row_number() over (partition by model_type,model_id,'.$c->propertyKey().' order by id) as position'),'bounded')->where('position','<=',2)->select('id'))->orderBy('id')->get()->groupBy('model_id')->all();
        return $c;
    }
    public function related(string $kind,int $id): array
    { return array_values(array_filter($this->sources,fn($s)=>$kind==='watch'?(int)$s->watch_id===$id:((int)$s->managed_domain_id===$id || $s->registrable_name===($this->domains[$id]->domain??null)))); }
    public function validSource(object $s): bool
    {
        $h=$this->services[$s->service_id]??null;$m=$this->maps[$s->map_id]??null;$o=$this->orders[$h?->order_id]??null;
        if (!$h || !$o || (int)$h->user_id!==(int)$s->user_id || (int)$o->user_id!==(int)$s->user_id || !$m || !$m->enabled || $h->status!=='active' || !isset($this->products[$h->product_id]) || (int)$h->product_id!==(int)$m->product_id || (int)$s->map_revision!==$m->revision) { return false; }
        $props=($this->properties[$h->id]??collect())->where('key',$m->property_key);
        try { return $props->count()===1 && DnsQuery::hostname($props->first()->value)===$s->hostname; } catch (\Throwable) { return false; }
    }
    private function propertyKey(): string { return DB::connection()->getQueryGrammar()->wrap('key'); }
    public function stamp(string $kind,int $id,string $family='dns'): ?string
    {
        $t=$kind==='watch'?($this->watches[$id]??null):($this->domains[$id]??null);if (!$t) { return null; }
        $sources=$this->related($kind,$id);$config=$kind==='managed'?($this->configs[$id]??null):null;
        $profile=$this->profiles[$kind==='watch'?$t->ns_profile_id:$config?->profile_id]??null;
        if ($kind==='watch' && ($t->state!=='active' || !$sources)) { return null; }
        $evidence=[];
        foreach ($sources as $s) {
            if ($kind==='watch' && (!$this->validSource($s) || $s->registrable_name!==$t->canonical_name)) { return null; }
            $h=$this->services[$s->service_id]??null;$m=$this->maps[$s->map_id]??null;
            $evidence[]=[$s->only(['id','user_id','service_id','map_id','map_revision','hostname','registrable_name','watch_id','managed_domain_id','state','source_digest','revision']),$h?->only(['id','user_id','order_id','product_id','status']),($this->orders[$h?->order_id]??null)?->user_id,$m?->getRawOriginal(),($this->properties[$h?->id]??collect())->where('key',$m?->property_key)->pluck('value')->all(),($this->products[$h?->product_id]??null)?->server_id];
        }
        $identity=$kind==='watch'?$t->only(['id','user_id','canonical_name','psl_revision','state']):$t->only(['id','user_id','domain','service_id','server_id','registrar_key','account_fingerprint','authority_generation','confirmed_revision','provider_expires_at','status']);
        if ($family!=='registry') { $identity['dns']=$kind==='watch'?$t->only(['expected_dns','ns_profile_id','profile_revision']):null; } else { $profile=null; }
        $native=$kind==='managed'?($this->services[$t->service_id]??null):null;
        return hash('sha256',json_encode([$identity,$native?->only(['id','user_id','order_id','product_id','plan_id','status']),$config?->getRawOriginal(),$profile?->getRawOriginal(),$evidence],JSON_THROW_ON_ERROR));
    }
    /** Captured by public observation producers before unlocked reads; original commit rechecks remain required. */
    public static function evidence(string $kind,int $id,int $owner,string $family='dns'): ?string
    { return (new self($owner,$kind==='managed'?[$id]:[],$kind==='watch'?[$id]:[]))->stamp($kind,$id,$family); }
}
