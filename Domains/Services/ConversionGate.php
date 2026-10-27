<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
final class ConversionGate
{
    private static bool $complete = false;
    public static function requireComplete(): void
    {
        if(self::$complete){return;}
        if(!Schema::hasTable('domains_conversion_runs')&&!Schema::hasTable('domains_conversion_map')){return;}
        if(!Schema::hasTable('domains_conversion_runs')||!Schema::hasTable('domains_conversion_map')
            ||DB::table('domains_conversion_runs')->count()!==1||DB::table('domains_conversion_runs')->where('id',1)->value('state')!=='complete'){
            throw new \DomainException('Incomplete conversion; keep maintenance active and resume verified staging.');
        }
        self::$complete = true;
    }
}
