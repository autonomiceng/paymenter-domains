<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** New complimentary audit times are UTC and say so per row. Retained rows have no marker, stay store-zone wall time and are never rewritten. */
final class ComplimentaryTimeSchema
{
    public const MIGRATION='2026_11_09_000058_add_complimentary_time_zone';
    public const TABLES=['domains_complimentary_accounts','domains_complimentary_approvals'];
    public static function install(): void
    {
        ComplimentaryAccountSchema::verify();
        foreach(self::TABLES as $table){
            if(!Schema::hasColumn($table,'time_zone')){Schema::table($table,static fn(Blueprint $t)=>$t->string('time_zone',64)->nullable());}
        }
        self::verify();
    }
    /** Whether any retained table already has the marker column. */
    public static function present(): bool
    {
        foreach(self::TABLES as $table){if(Schema::hasTable($table)&&Schema::hasColumn($table,'time_zone')){return true;}}
        return false;
    }
    public static function verify(): void
    {
        foreach(self::TABLES as $table){if(!Schema::hasColumn($table,'time_zone')){throw new \RuntimeException('Retained complimentary time zones are missing.');}}
        ComplimentaryAccountSchema::verify();
    }
}
