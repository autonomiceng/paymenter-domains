<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class HoldingAdoptionSchema
{
    public static function install(): void
    {
        if (!Schema::hasColumn('domains_domains', 'adoption_id')) {
            Schema::table('domains_domains', fn (Blueprint $t) => $t->unsignedBigInteger('adoption_id')->nullable());
        }
        self::verify();
    }
    public static function verify(): void
    {
        if (!Schema::hasColumn('domains_domains', 'adoption_id')) { throw new \RuntimeException('Holding adoption migration required.'); }
        FoundationSchema::verify();
    }
}
