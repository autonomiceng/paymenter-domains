<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Category,ConfigOption,Coupon,CustomProperty,Extension,Product,Service,User};

/** Retains currently routed children. User deletion racing new checkout remains an adoption blocker. */
final class NativeIdentityParents
{
    public static function boot(): void
    {
        foreach([\App\Models\Server::class,Extension::class] as $class){foreach(['updating','deleting'] as $event){
            $class::$event(static function($s)use($event):void {
                if($event==='updating'&&!NativeIdentity::dirty($s,['id','type','extension'])){return;}
                foreach(Product::whereIn('server_id',[$s->getRawOriginal('id'),$s->id])->pluck('id') as $id){if(NativeIdentity::retainedProduct((int)$id)){NativeIdentity::refuse();}}
                foreach(NativeIdentity::rows($s) as $r){
                    if(($r['extension']??null)==='Domains'){NativeIdentity::refuse();}
                }
            });
        }}
        foreach(['updating','deleting'] as $event){
            User::$event(static function(User $u)use($event):void {
                if($event==='updating'&&!NativeIdentity::dirty($u,['id'])){return;}
                $ids=array_filter([$u->getRawOriginal('id'),$u->id]);
                self::services(Service::where(fn($q)=>$q->whereIn('user_id',$ids)->orWhereHas('order',fn($q)=>$q->whereIn('user_id',$ids)))->lazyById(100));
            });
            Category::$event(static function(Category $c)use($event):void {
                if($event==='updating'&&!NativeIdentity::dirty($c,['id','slug'])){return;}
                // Publication uses this reserved category before any product/service exists.
                foreach(NativeIdentity::rows($c) as $r){if(($r['slug']??null)==='domains'){NativeIdentity::refuse();}}
                foreach(Product::whereIn('category_id',[$c->getRawOriginal('id'),$c->id])->pluck('id') as $id){if(NativeIdentity::retainedProduct((int)$id)){NativeIdentity::refuse();}}
            });
            CustomProperty::$event(static function(CustomProperty $p)use($event):void {
                if($event==='updating'&&!NativeIdentity::dirty($p,['id'])){return;}
                $ids=\App\Models\Property::whereIn('custom_property_id',[$p->getRawOriginal('id'),$p->id])
                    ->where('key','domain')->where('model_type',(new Service)->getMorphClass())->pluck('model_id');
                self::services(Service::whereKey($ids)->lazyById(100));
            });
            Coupon::$event(static function(Coupon $c)use($event):void {
                if($event==='updating'&&!NativeIdentity::dirty($c,['id'])){return;}
                self::services(Service::whereIn('coupon_id',[$c->getRawOriginal('id'),$c->id])->lazyById(100));
            });
            ConfigOption::$event(static function(ConfigOption $c)use($event):void {
                if($event==='updating'&&!NativeIdentity::dirty($c,['id','parent_id'])){return;}
                $ids=[$c->getRawOriginal('id'),$c->id];$seen=[];
                while($next=array_values(array_diff($ids,$seen))){$seen=[...$seen,...$next];$ids=ConfigOption::whereIn('parent_id',$next)->pluck('id')->all();}
                $ids=\App\Models\ServiceConfig::where('configurable_type',(new Service)->getMorphClass())
                    ->where(fn($q)=>$q->whereIn('config_option_id',$seen)->orWhereIn('config_value_id',$seen))->pluck('configurable_id');
                self::services(Service::whereKey($ids)->lazyById(100));
            });
        }
    }
    private static function services(iterable $rows): void {foreach($rows as $s){if(NativeIdentity::routed($s)){NativeIdentity::refuse();}}}
}
