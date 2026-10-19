<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class HoldingProvenanceSchema
{
    public static function install(): void
    {
        if (!Schema::hasColumn('domains_domains', 'provenance')) {
            Schema::table('domains_domains', fn (Blueprint $t) => $t->string('provenance', 32)->nullable());
        }
        self::verify();
    }
    public static function verify(): void
    {
        if (!Schema::hasColumn('domains_domains', 'provenance')) { throw new \RuntimeException('Holding provenance migration required.'); }
        FoundationSchema::verify();
    }
}
