<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\{Cache,Log};
/** A menu hint may lag one minute. Access is checked by the item before reading the cache. */
final class NavigationCount
{
    private static function key(string $item): string
    { return 'domains.menu:'.hash('sha256',(string)config('app.key')."\0".(string)config('app.url')).':'.$item; }
    /** A page that shows the same count drops the cached one, so the menu and the page say one number. */
    public static function forget(string $item): void
    { try { Cache::forget(self::key($item)); } catch (\Throwable) { self::failed($item); } }
    public static function read(string $item, \Closure $count): ?string
    {
        $key=self::key($item);
        try {
            $n=Cache::remember($key,60,static function()use($count,$item):int {
                try{return (int)$count();}catch(\Throwable){self::failed($item);return 0;}
            });
            return $n>0?(string)$n:null;
        }catch(\Throwable){self::failed($item);return null;}
    }
    private static function failed(string $item): void
    {
        try {
            $attributes=request()->attributes;$key='domains.menu.failed.'.$item;
            if(!$attributes->has($key)){$attributes->set($key,true);Log::warning('Menu count could not be read.',['item'=>$item]);}
        }catch(\Throwable){} // Logging must not break navigation either.
    }
}
