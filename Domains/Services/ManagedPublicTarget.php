<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
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
        $profile=ManagedDnsSetup::profile($d,$config,$lock);
        $config->expected_dns=ManagedDnsSetup::expected($config,$profile,$d,...ManagedDnsSetup::choices($d,$l['operations']));
        [$sources,$more]=$this->sources($id,$cursor,$lock);
        if ($more!==$overflow || $peek->pluck('id')->all()!==$sources->pluck('id')->all()) { return null; }
        $pending=WatchSource::where('user_id',$owner)->where('registrable_name',$d->domain)->where('state','watch')->exists();
        $hosts=[$d->domain];$evidence=[];$omitted=$pending;$checkRecords=false;
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
            if($p->server_id!==null){$checkRecords=true;$hosts[]=$source->hostname;}
        }
        $stamp=hash('sha256',json_encode([$d->only(['id','domain','user_id','service_id','server_id','registrar_key','account_fingerprint','authority_generation','confirmed_revision','status']),
            $d->provider_expires_at->format('Y-m-d'),$l['identity_source'],$d->renewal_policy['billing_anchor']??null,$l['service']->status,
            $config->getRawOriginal(),$profile?->getRawOriginal(),$config->expected_dns,$evidence,$overflow,$pending],JSON_THROW_ON_ERROR));
        $hosts=array_values(array_unique($hosts));
        return ['domain'=>$d,'config'=>$config,'profile'=>$profile?->enabled?$profile:null,'stamp'=>$stamp,
            'hosts'=>$hosts,'check_records'=>$checkRecords,'overflow'=>$overflow || $omitted,'next_source_cursor'=>$overflow?(int)$sources->first()->id:0];
    }
    private function sources(int $id,int $cursor,bool $lock): array
    {
        // Suspended hosting is never checked, so it never takes a place in the bounded cohort or its rotation.
        $base=WatchSource::where('managed_domain_id',$id)->where('state','managed_link')
            ->whereExists(fn($q)=>$q->selectRaw('1')->from('services')->whereColumn('services.id','domains_watch_sources.service_id')->where('services.status','active'));
        $prefix=(clone $base)->orderBy('id')->limit(101)->get();$overflow=$prefix->count()>100;
        if (!$overflow) { return [$lock?(clone $base)->orderBy('id')->limit(101)->lockForUpdate()->get():$prefix,false]; }
        $next=(clone $base)->where('id','>',$cursor)->orderBy('id')->first();$key=$next?->id??$prefix->first()->id;
        return [(clone $base)->whereKey($key)->when($lock,fn($q)=>$q->lockForUpdate())->get(),true];
    }
}
