<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
/** Customer cleanup must not lock another customer's history through an unindexed owner scan. */
final class RetentionOwnerIndex
{
    public const MIGRATION='2026_11_11_000068_index_retention_owners';
    public const TABLES=['domains_watch_sources','domains_watch_observations','domains_watch_registry','domains_watch_audit','domains_watch_deliveries'];
    public const COLUMNS=['user_id','id'];
    public static function define(Blueprint $t,string $table): void
    {if(!Schema::hasTable($table)||Schema::hasIndex($table,self::COLUMNS)){$t->index(self::COLUMNS);}}
    public static function install(): void
    {
        foreach(self::TABLES as $table){if(!Schema::hasIndex($table,self::COLUMNS)){Schema::table($table,static fn(Blueprint $t)=>$t->index(self::COLUMNS));}}
    }
    public static function verify(): void
    {
        if(!DB::table('migrations')->where('migration',self::MIGRATION)->exists()){return;}
        foreach(self::TABLES as $table){if(!Schema::hasIndex($table,self::COLUMNS)){throw new \RuntimeException('Customer history owner index is missing.');}}
    }
}
