<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Product,Service};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\DnsQuery;
use Paymenter\Extensions\Servers\Domains\Models\WatchProductMap;
/** Finds the domain field of each hosting product from what its services saved, so no hosting API is called. It never guesses: when saved values cannot settle it, the product shows No domain found. */
final class WatchProducts
{
    private const NAMES=['domain','hostname'];
    private const MOST_ROWS=1000;
    /** @return Builder<Product> */
    private static function hosting(): Builder
    {return Product::whereHas('server',fn($q)=>$q->where('extension','<>','Domains'))->whereDoesntHave('settings',fn($q)=>$q->where('key','domains_catalogue'));}
    /** The next products with something to read. Rows only exist once decided, so this skips every product staff switched off. */
    public static function unmapped(int $after,int $limit): Collection
    {return self::hosting()->has('services')->whereNotIn('id',WatchProductMap::select('product_id'))->where('id','>',$after)->orderBy('id')->limit($limit)->get();}
    /** One line per hosting product for the Domain monitoring page. A line can be re-picked once it is off; with no services there is nothing to pick from. */
    public static function lines(): array
    {
        $maps=WatchProductMap::get(['id','product_id','enabled','revision'])->keyBy('product_id');
        return self::hosting()->select(['id','name'])->withExists('services')->orderBy('name')->orderBy('id')->get()->map(function($product)use($maps):array{$map=$maps[$product->id]??null;
            return ['product'=>(int)$product->id,'name'=>$product->name,'map'=>$map?(int)$map->id:null,'revision'=>(int)$map?->revision,'on'=>(bool)$map?->enabled,'pick'=>!$map?->enabled&&$product->services_exists,
                'state'=>$map===null?($product->services_exists?'No domain found':'No services yet'):($map->enabled?'Monitored':'Not monitored')];})->all();
    }
    public static function adopt(Product $product): void
    {if(($key=self::found($product))!==null){(new WatchMappings())->adopt((int)$product->id,$key);}}
    /** The one field that fits, or null. A field named domain or hostname comes first, so a named field holding something else is a contradiction for a person to settle. */
    public static function found(Product $product): ?string
    {
        $saved=self::values($product);
        if($saved===null){return null;}
        $named=$saved->filter(fn($rows,$key)=>in_array(strtolower($key),self::NAMES,true)||$rows->contains(fn($row)=>in_array(strtolower(trim((string)$row->name)),self::NAMES,true)))->keys();
        $fits=$saved->filter(fn($rows)=>$rows->every(fn($row)=>self::looksLikeDomain((string)$row->value)))->keys();
        $pick=$named->isEmpty()?$fits:$fits->intersect($named);
        return $pick->count()===1?$pick->first():null;
    }
    /** Saved rows of these services. Checkout saves every field here, and discovery reads the same rows. */
    private static function rows(Builder $services): QueryBuilder
    {return DB::table('properties')->where('model_type',(new Service())->getMorphClass())->whereIn('model_id',$services->select('id'))->where('value','<>','');}
    /** Field rows by key, from active services if the product has any: a cancelled service says nothing about the field in use. Null past the read limit, because a partial read can't prove a field fits or is the only one. Values stay here: they are never shown or stored. */
    private static function values(Product $product): ?Collection
    {
        $services=Service::where('product_id',$product->id);$active=(clone $services)->where('status','active');
        $rows=self::rows($active->exists()?$active:$services)->limit(self::MOST_ROWS+1)->get(['key','name','value']);
        return $rows->count()>self::MOST_ROWS?null:$rows->filter(fn($row)=>WatchProductMap::validPropertyKey($row->key))->groupBy('key');
    }
    /** A real name ends in a word: 8.2 is valid hostname syntax but no domain. */
    private static function looksLikeDomain(string $value): bool
    {
        try{$name=DnsQuery::hostname(trim($value));}catch(\InvalidArgumentException){return false;}
        return preg_match('/[a-z]/',substr((string)strrchr($name,'.'),1))===1;
    }
    /** Every field any of the product's services saved, key to label. One row per key, so no read limit applies. */
    public static function fields(Product $product): array
    {
        return self::rows(Service::where('product_id',$product->id))->groupBy('key')->orderBy('key')->get(['key',DB::raw('max(name) as name')])
            ->filter(fn($row)=>WatchProductMap::validPropertyKey($row->key))->mapWithKeys(fn($row)=>[$row->key=>$row->name?:$row->key])->all();
    }
}
