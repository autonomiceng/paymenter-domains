<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Order,Plan,Product,Property,Service,ServiceConfig,Setting};
use Illuminate\Database\Eloquent\Model;

/** Stable model-event refusals. Bulk, event-suppressed and raw maintenance remain privileged. */
final class NativeIdentity
{
    private const SERVICE=['id','user_id','order_id','product_id','plan_id','quantity','currency_code','coupon_id'];
    private const PRODUCT=['id','server_id','slug','hidden','allow_quantity'];
    private const PLAN=['id','priceable_type','priceable_id','type','billing_unit','billing_period'];
    private const MARKERS=['domains_catalogue','domains_tld_id','domains_action','domains_registrar'];
    public static function boot(): void
    {
        static $booted=false;if($booted){return;}$booted=true;NativeExpiry::boot();NativeStock::boot();
        Order::updating(static function(Order $o):void {
            if(self::dirty($o,['id','user_id','currency_code'])){self::order($o);}
        });
        Order::deleting(self::order(...));
        Service::creating(static function(Service $s):void {
            if(self::routed($s)){if(self::dirty($s,[...self::SERVICE,'expires_at'],true)){self::refuse();}if(!app()->bound(InvoiceBinding::class)){self::refuse();}app(InvoiceBinding::class)->admitIdentity($s);}
        });
        Service::updating(static function(Service $s):void {
            if((self::dirty($s,self::SERVICE)||self::dirty($s,['price'],persisted:false))&&!NativeDomainGuard::identityProjection($s)){self::service($s,'save');}
        });
        Service::deleting(static fn(Service $s)=>self::service($s,'delete'));
        ServiceConfig::saving(self::config(...));ServiceConfig::updating(self::config(...));ServiceConfig::deleting(self::config(...));
        Property::saving(self::property(...));Property::updating(self::property(...));Property::deleting(static fn(Property $p)=>self::property($p,true));
        foreach(['updating','deleting'] as $event){
            Product::$event(static function(Product $p)use($event):void {
                if(($event==='deleting'||self::dirty($p,self::PRODUCT))&&(self::productRows(self::rows($p))||self::references('product_id',self::rows($p)))){self::refuse();}
            });
            Plan::$event(static function(Plan $p)use($event):void {
                if($event!=='deleting'&&!self::dirty($p,self::PLAN)){return;}
                foreach(self::rows($p) as $r){if(($r['priceable_type']??null)===(new Product)->getMorphClass()&&self::product((int)$r['priceable_id'])){self::refuse();}}
                if(self::references('plan_id',self::rows($p))){self::refuse();}
            });
        }
        foreach(['saving','updating','deleting'] as $event){Setting::$event(static function(Setting $s)use($event):void {
            if($event!=='deleting'&&$s->exists&&!self::dirty($s,['id','key','value','settingable_id','settingable_type','type','encrypted'])){return;}
            foreach(self::rows($s) as $r){
                if(($r['settingable_type']??null)!==(new Product)->getMorphClass()||!in_array($r['key']??null,self::MARKERS,true)){continue;}
                // New catalogue markers may describe an already dedicated product, never convert hosting.
                if(!$s->exists&&Product::whereKey($r['settingable_id'])->whereHas('server',fn($q)=>$q->where('extension','Domains'))->exists()
                    &&!Service::where('product_id',$r['settingable_id'])->exists()
                    &&!Plan::where('priceable_type',(new Product)->getMorphClass())->where('priceable_id',$r['settingable_id'])->exists()
                    &&!Setting::where('settingable_type',$r['settingable_type'])->where('settingable_id',$r['settingable_id'])->where('key',$r['key'])->exists()){continue;}
                self::refuse();
            }
        });}
        NativeIdentityParents::boot();NativeIdentityDiscrepancies::boot();
    }
    public static function refuse(): never
    {
        logger()->warning('Managed domain native identity change refused.');
        throw new \DomainException('This change affects managed domain identity and was refused.');
    }
    /** Include persisted identity: native select projections may omit route or morph fields. */
    public static function rows(Model $m): array
    {
        $rows=[$m->getRawOriginal(),$m->getAttributes()];
        if($m->exists){
            $key=$m->getRawOriginal($m->getKeyName())??$m->getKey();
            $stored=$m->newQuery()->find($key)?->getAttributes();
            if($stored!==null){$rows=[$stored,array_replace($stored,$rows[0]),array_replace($stored,$rows[1])];}
        }
        return array_map(self::columns(...),$rows);
    }
    /** Compare persisted/proposed values too: numeric SQL extras can be clean in a projected model. */
    public static function dirty(Model $m,array $columns,bool $aliasesOnly=false,bool $persisted=true): bool
    {
        if(!$aliasesOnly){
            $probe=$m->newInstance([],true);$probe->setRawAttributes(self::bindings($m->getRawOriginal()),true);
            $probe->setRawAttributes(self::bindings($m->getAttributes()));
        }
        foreach(array_keys($aliasesOnly?$m->getAttributes():$probe->getDirty()) as $key){
            $column=self::column($key);if(in_array($column,$columns,true)&&(!$aliasesOnly||$key!==$column)){return true;}
        }
        if(!$aliasesOnly&&$persisted&&$m->exists){
            $rows=self::rows($m);$probe=$m->newInstance([],true);
            $probe->setRawAttributes(self::bindings($rows[0]),true);$probe->setRawAttributes(self::bindings($rows[array_key_last($rows)]));
            return $probe->isDirty($columns);
        }return false;
    }
    /** Native query bindings store booleans as integers, including uncast Product.hidden. */
    private static function bindings(array $row): array
    {return array_map(static fn($value)=>is_bool($value)?(int)$value:$value,$row);}
    private static function columns(array $row): array
    {$canonical=[];foreach($row as $key=>$value){$canonical[self::column($key)]=$value;}return $canonical;}
    private static function column(string $key): string
    {$parts=explode('.',explode('->',$key,2)[0]);return strtolower(end($parts));}
    public static function product(int $id): bool
    {
        $p=Product::find($id);return $p!==null&&self::productRows([$p->getAttributes()]);
    }
    /** Parent retention includes mismatched product/plan pairs; a native FK can null either side. */
    public static function retainedProduct(int $id): bool
    {return self::product($id)||self::references('product_id',[['id'=>$id]]);}
    private static function references(string $column,array $rows): bool
    {
        foreach(Service::whereIn($column,array_filter(array_column($rows,'id')))->lazyById(100) as $s){if(self::routed($s)){return true;}}
        return false;
    }
    /** Any route signal protects malformed rows too; protection never depends on ledger presence. */
    public static function productRows(array $rows): bool
    {
        foreach($rows as $r){
            if(isset($r['server_id'])&&\App\Models\Server::whereKey($r['server_id'])->where('extension','Domains')->exists()){return true;}
            if(preg_match('/\Adomains-(?:enom|namesilo)-s[1-9][0-9]*-.+-(?:registration|transfer)\z/D',(string)($r['slug']??''))){return true;}
            if(isset($r['id'])&&Setting::where('settingable_type',(new Product)->getMorphClass())->where('settingable_id',$r['id'])->whereIn('key',self::MARKERS)->exists()){return true;}
        }return false;
    }
    public static function routed(Service $s): bool
    {
        $rows=self::rows($s);
        foreach(array_unique(array_filter(array_column($rows,'product_id'))) as $id){if(self::product((int)$id)){return true;}}
        foreach(array_unique(array_filter(array_column($rows,'plan_id'))) as $id){
            $plan=Plan::find($id);
            if($plan?->priceable_type===(new Product)->getMorphClass()&&self::product((int)$plan->priceable_id)){return true;}
        }return false;
    }
    private static function service(Service $s,?string $operation=null): void
    {if(self::routed($s)){if($operation!==null){NativeServiceRefusal::notify($s,$operation,self::dirty($s,self::SERVICE)?'identity':'price');}self::refuse();}}
    private static function order(Order $o): void
    {
        foreach(Service::whereIn('order_id',array_filter(array_column(self::rows($o),'id')))->lazyById(100) as $s){self::service($s);}
    }
    private static function config(ServiceConfig $config): void
    {
        foreach(self::rows($config) as $r){
            if(($r['configurable_type']??null)!==(new Service)->getMorphClass()){continue;}
            $s=Service::find($r['configurable_id']??0);if($s&&self::routed($s)){self::refuse();}
        }
    }
    private static function property(Property $p,bool $deleting=false): void
    {
        if(!$deleting&&$p->exists&&!self::dirty($p,['id','key','value','model_type','model_id','custom_property_id'])){return;}
        $rows=self::rows($p);if(!in_array('domain',array_column($rows,'key'),true)){return;}
        foreach($rows as $r){
            if(($r['model_type']??null)!==(new Service)->getMorphClass()){continue;}
            $s=Service::find($r['model_id']??0);
            if(!$s||!self::routed($s)){continue;}
            if(!$p->exists&&self::dirty($p,['id','key','value','model_type','model_id','custom_property_id'],true)){self::refuse();}
            if(!$p->exists&&app()->bound(InvoiceBinding::class)&&app(InvoiceBinding::class)->identityProperty($p)){return;}
            self::refuse();
        }
    }
}
