<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Register each owned callback group once on its dispatcher or render manager. */
final class HookRegistration
{
    private static ?\WeakMap $registrations=null;
    public static function claim(?object $dispatcher,string $owner): bool
    {
        if($dispatcher===null){return false;}
        self::$registrations??=new \WeakMap;
        $owners=self::$registrations[$dispatcher]??[];
        if(isset($owners[$owner])){return false;}
        $owners[$owner]=true;
        self::$registrations[$dispatcher]=$owners;
        return true;
    }
}
