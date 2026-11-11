<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Cold payloads have no normal-page reader. Replay identities are independent of payload expiry. */
final class RetentionSchema
{
    public const MIGRATION='2026_11_11_000067_create_retention_history';
    public const COLD='domains_retention_history',KEYS='domains_retention_keys',SCANS='domains_retention_scans',MARKS='domains_retention_marks';
    public static function tables(): array {return [self::COLD,self::KEYS,self::SCANS,self::MARKS];}
    private static function cold(Blueprint $t): void
    {
        $t->id();$t->string('family',32);$t->unsignedBigInteger('source_id');$t->unsignedBigInteger('user_id')->nullable()->index();
        $t->unsignedBigInteger('occurred_at');$t->unsignedBigInteger('delete_after')->index();$t->longText('payload');$t->unique(['family','source_id']);
    }
    private static function keys(Blueprint $t): void {$t->string('family',32);$t->string('replay_key',64);$t->primary(['family','replay_key']);}
    private static function scans(Blueprint $t): void {$t->string('family',32)->primary();$t->unsignedBigInteger('cursor')->default(0);}
    private static function marks(Blueprint $t): void
    {$t->string('family',32);$t->unsignedBigInteger('source_id');$t->unsignedBigInteger('recorded_at')->nullable();$t->string('reason',64)->nullable();$t->primary(['family','source_id']);}
    public static function install(): void
    {
        $present=array_filter(array_map(Schema::hasTable(...),self::tables()));
        if($present&&!FreshInstallation::recovering()){self::verify();return;}
        foreach(array_combine(self::tables(),['cold','keys','scans','marks']) as $table=>$method){if(!Schema::hasTable($table)){Schema::create($table,self::$method(...));}}
        self::verify();
    }
    public static function verify(): void
    {foreach(array_combine(self::tables(),['cold','keys','scans','marks']) as $table=>$method){SchemaVerifier::verify($table,self::$method(...));}}
}
