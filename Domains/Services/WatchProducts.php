<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Product,Service};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Paymenter\Extensions\Servers\Domains\Models\WatchProductMap;
/** Hosting products and their monitoring (Ilya, Nov 15, 2026). A product is in scope when its orders carry a domain,
 * with or without a server; domain registrations never are. Every one is monitored, new ones too: staff only turn
 * products off, so the turned-off maps are the only choice stored. */
final class WatchProducts
{
    /** The order field that holds the domain: domain, else hostname. No other field is ever read. */
    public const KEYS=['domain','hostname'];
    /** @return Builder<Product> Every product but a domain registration. */
    private static function hosting(): Builder
    {return Product::whereDoesntHave('server',fn($q)=>$q->where('extension','Domains'))->whereDoesntHave('settings',fn($q)=>$q->where('key','domains_catalogue'));}
    /** Services that saved a value in one of these order fields. */
    private static function saved(string ...$keys): \Closure { return fn($s)=>$s->whereHas('properties',fn($p)=>$p->whereIn('key',$keys)->where('value','<>','')); }
    private static function carries(string ...$keys): \Closure { return fn($q)=>$q->whereHas('services',self::saved(...$keys)); }
    /** @return Builder<Product> Hosting products whose orders carry a domain. */
    public static function inScope(): Builder { return self::hosting()->where(self::carries(...self::KEYS)); }
    /** One service's domain values: its domain, else its hostname. Each service reads its own, so a product with mixed
     * orders, or old services with another field, never drops a monitored domain. $props are that service's properties. */
    public static function field(Collection $props): Collection
    {$domain=$props->where('key','domain')->filter(fn($p)=>trim((string)$p->value)!=='');return $domain->isNotEmpty()?$domain:$props->where('key','hostname');}
    /** The next products the tick maps or corrects: in scope with no map yet, or a map on a field other than the convention. */
    public static function unmapped(int $after,int $limit): Collection
    {
        $maps=fn()=>WatchProductMap::select('product_id');
        return self::hosting()->where(fn($q)=>$q->where(fn($q)=>$q->where(self::carries(...self::KEYS))->whereNotIn('id',$maps()))
            ->orWhereIn('id',$maps()->whereNotIn('property_key',self::KEYS)))
            ->where('id','>',$after)->orderBy('id')->limit($limit)->get();
    }
    public static function adopt(Product $product): void { (new WatchMappings())->adopt((int)$product->id,self::key((int)$product->id)); }
    /** The key a new or corrected map stores. Reads go per service through field(), so it only marks the convention. */
    public static function key(int $product): string
    {$has=fn(string $key)=>Service::where('product_id',$product)->where(self::saved($key))->exists();return $has('domain')||!$has('hostname')?'domain':'hostname';}
    /** The Hosting products section: products to choose (name, hidden, on, active services) and the names of the rest. */
    public static function lines(): array
    {
        $off=WatchProductMap::where('enabled',false)->pluck('product_id')->flip();
        $all=self::hosting()->select(['id','name','hidden'])->withExists(['services as carries'=>self::saved(...self::KEYS)])->orderBy('name')->orderBy('id')->get();
        [$in,$out]=$all->partition(fn($p)=>$p->carries);
        return ['products'=>$in->map(fn($p)=>['id'=>(int)$p->id,'name'=>$p->name,'hidden'=>(bool)$p->hidden,'on'=>!$off->has($p->id)])->values()->all(),'unlisted'=>$out->pluck('name')->values()->all()];
    }
    /** Active services per product, read apart from the list so a slow count leaves the choices usable. @return array<int,int> */
    public static function active(array $ids): array
    {return Service::whereIn('product_id',$ids)->where('status','active')->groupBy('product_id')->selectRaw('product_id, count(*) as n')->pluck('n','product_id')->map(fn($n)=>(int)$n)->all();}
}
