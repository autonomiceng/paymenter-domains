<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\DnsQuery;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicConfig,WatchSource,WatchProductMap,WatchProfile};
/** Capture structural authority only; account processing/credential gates are deliberately absent. */
final class ManagedPublicTarget
{
    public static function valid(array $l,int $now): bool
    {
        $d=$l['domain'];
        return in_array($d->status,['active','grace'],true) && $l['service']->status==='active'
            && $d->provider_expires_at!==null && $d->provider_expires_at->format('Y-m-d')>gmdate('Y-m-d',$now);
    }
    public function capture(int $id,int $owner,int $now,int $cursor=0,bool $lock=true): ?array
    {
        $seed=Domain::whereKey($id)->where('user_id',$owner)->first(); if (!$seed) { return null; }
        [$peek,$overflow]=$this->sources($id,$cursor,false);
        try { $l=HoldingLocks::take($seed,advisory:!$lock,additionalServices:$peek->pluck('service_id')->all()); HoldingAnchor::resolve($l); }
        catch (\LogicException|\RuntimeException) { return null; }
        if (!self::valid($l,$now)) { return null; } $d=$l['domain'];
        $maps=WatchProductMap::whereKey($peek->pluck('map_id'))->orderBy('id')->when($lock,fn($q)=>$q->lockForUpdate())->get()->keyBy('id');
        $config=ManagedPublicConfig::whereKey($id)->when($lock,fn($q)=>$q->lockForUpdate())->first();
        if (!$config || (int)$config->user_id!==$owner) { return null; }
        $profile=WatchProfile::whereKey($config->profile_id)->when($lock,fn($q)=>$q->lockForUpdate())->first();
        [$sources,$more]=$this->sources($id,$cursor,$lock);
        if ($more!==$overflow || $peek->pluck('id')->all()!==$sources->pluck('id')->all()) { return null; }
        // Agreement covers the entire source set, including cohorts not selected this turn.
        $conflict=DB::table('domains_watch_sources as s')->join('domains_watch_product_maps as m','m.id','=','s.map_id')
            ->join('services as h','h.id','=','s.service_id')->join('orders as o','o.id','=','h.order_id')->join('products as hp','hp.id','=','h.product_id')
            ->where('s.user_id',$owner)->where('s.registrable_name',$d->domain)
            ->where(fn($q)=>$q->where(fn($q)=>$q->where('s.managed_domain_id',$id)->where('s.state','managed_link'))->orWhere('s.state','watch'))
            ->where('h.user_id',$owner)->where('o.user_id',$owner)->where('h.status','active')->where('m.enabled',true)
            ->whereColumn('h.product_id','m.product_id')->whereColumn('s.map_revision','m.revision')->whereNotNull('m.expected_ns_profile_id')
            ->when($profile,fn($q)=>$q->where('m.expected_ns_profile_id','<>',$profile->id))
            ->whereRaw('(select count(*) from properties p where p.model_id=h.id and p.model_type=? and '.$this->propertyKey().'=m.property_key)=1',[(new \App\Models\Service())->getMorphClass()])
            ->whereExists(fn($q)=>$q->selectRaw('1')->from('properties as p')->whereColumn('p.model_id','h.id')
                ->where('p.model_type',(new \App\Models\Service())->getMorphClass())->whereColumn('p.key','m.property_key')
                ->whereRaw("lower(case when substr(p.value,-1)='.' then substr(p.value,1,length(p.value)-1) else p.value end)=s.hostname"))->exists();
        $pending=WatchSource::where('user_id',$owner)->where('registrable_name',$d->domain)->where('state','watch')->exists();
        $hosts=[$d->domain];$evidence=[];$omitted=$pending;
        foreach($sources as $source) {
            $s=$l['services']->firstWhere('id',$source->service_id);$m=$maps[$source->map_id]??null;
            $o=$l['orders']->firstWhere('id',$s?->order_id);$p=$l['products']->firstWhere('id',$s?->product_id);
            $props=$l['relatedProperties']->where('model_id',$s?->id)->where('key',$m?->property_key);
            $evidence[]=[$source->getRawOriginal(),$m?->getRawOriginal(),$s?->only(['id','user_id','order_id','product_id','status']),$o?->user_id,$p?->server_id,$props->pluck('value')->all()];
            if (!$s || !$o || !$p || !$m || !$m->enabled || $s->status!=='active' || (int)$s->user_id!==$owner
                || (int)$o->user_id!==$owner || (int)$source->user_id!==$owner || (int)$s->product_id!==(int)$m->product_id
                || (int)$source->map_revision!==(int)$m->revision || $source->registrable_name!==$d->domain
                || ($source->hostname!==$d->domain && !str_ends_with($source->hostname,'.'.$d->domain)) || $props->count()!==1) { $omitted=true;continue; }
            try { if (DnsQuery::hostname($props->sole()->value)!==$source->hostname) { $omitted=true;continue; } }
            catch (\InvalidArgumentException|\TypeError) { $omitted=true;continue; }
            $hosts[]=$source->hostname;
        }
        $stamp=hash('sha256',json_encode([$d->only(['id','domain','user_id','service_id','server_id','registrar_key','account_fingerprint','authority_generation','confirmed_revision','status']),
            $d->provider_expires_at->format('Y-m-d'),$l['identity_source'],$d->renewal_policy['billing_anchor']??null,$l['service']->status,
            $config->getRawOriginal(),$profile?->getRawOriginal(),$conflict,$evidence,$overflow,$pending],JSON_THROW_ON_ERROR));
        $hosts=array_values(array_unique($hosts));
        return ['domain'=>$d,'config'=>$config,'profile'=>$profile?->enabled && !$conflict?$profile:null,'stamp'=>$stamp,
            'hosts'=>$hosts,'overflow'=>$overflow || $omitted,'next_source_cursor'=>$overflow?(int)$sources->first()->id:0];
    }
    private function propertyKey(): string { return DB::connection()->getQueryGrammar()->wrap('p.key'); }
    private function sources(int $id,int $cursor,bool $lock): array
    {
        $base=WatchSource::where('managed_domain_id',$id)->where('state','managed_link');
        $prefix=(clone $base)->orderBy('id')->limit(101)->get();$overflow=$prefix->count()>100;
        if (!$overflow) { return [$lock?(clone $base)->orderBy('id')->limit(101)->lockForUpdate()->get():$prefix,false]; }
        $next=(clone $base)->where('id','>',$cursor)->orderBy('id')->first();$key=$next?->id??$prefix->first()->id;
        return [(clone $base)->whereKey($key)->when($lock,fn($q)=>$q->lockForUpdate())->get(),true];
    }
}
