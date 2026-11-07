<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\Log;
/** Read the current list count. Access is checked by the item before counting. */
final class NavigationCount
{
    public static function read(string $item, \Closure $count): ?string
    {
        try {
            $n=(int)$count();
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
