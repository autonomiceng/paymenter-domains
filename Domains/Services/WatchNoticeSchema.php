<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
/** Retained public-notice authority, separate from managed renewal delivery. */
final class WatchNoticeSchema
{
    public const MIGRATION='2026_10_20_000032_create_watch_notices';
    public const OPT_IN_MIGRATION='2026_10_31_000046_require_watch_notice_opt_in';
    public static function tables(): array { return ['domains_watch_notice_state','domains_watch_notices','domains_watch_deliveries','domains_watch_preferences','domains_watch_mail_days']; }
    private static function state(Blueprint $t): void
    { $t->string('scope',64)->primary();$t->unsignedBigInteger('user_id');$t->longText('data');$t->unsignedBigInteger('updated_at'); }
    private static function notices(Blueprint $t): void
    {
        $t->id();$t->string('notice_key',64)->unique();$t->string('scope',64)->index();$t->string('target_kind',8);
        $t->unsignedBigInteger('watch_id')->nullable();$t->unsignedBigInteger('managed_domain_id')->nullable();$t->unsignedBigInteger('user_id');
        $t->string('kind',24);$t->string('episode_key',64);$t->integer('threshold')->nullable();$t->string('family',16);$t->unsignedBigInteger('observation_id');
        $t->string('state',16);$t->unsignedBigInteger('first_seen_at');$t->unsignedBigInteger('last_seen_at');$t->unsignedBigInteger('resolved_at')->nullable();$t->longText('data');
        $t->index(['user_id','state']);
    }
    private static function deliveries(Blueprint $t): void
    {
        $t->id();$t->unsignedBigInteger('notice_id');$t->unsignedBigInteger('user_id');$t->string('channel',8);$t->string('delivery_key',64)->unique();
        $t->string('template_key',64);$t->string('state',16);$t->unsignedInteger('attempts')->default(1);$t->unsignedBigInteger('reserved_at');
        $t->unsignedBigInteger('accepted_at')->nullable();$t->string('safe_code',64)->nullable();$t->unsignedBigInteger('native_id')->nullable();
        $t->unsignedBigInteger('resend_of')->nullable();$t->unsignedBigInteger('actor_id')->nullable();$t->string('reason',500)->nullable();
        $t->index(['notice_id','channel']);
    }
    private static function preferences(Blueprint $t,bool $legacy=false): void
    { $t->string('scope',64)->primary();$t->unsignedBigInteger('user_id')->index();$t->boolean('enabled')->default($legacy);$t->unsignedBigInteger('revision')->default(1); }
    private static function days(Blueprint $t): void
    { $t->string('scope',64)->primary();$t->unsignedBigInteger('delivery_id');$t->unsignedBigInteger('created_at'); }
    public static function install(): void
    {
        if (array_filter(array_map(Schema::hasTable(...),self::tables())) && !FreshInstallation::recovering()) { self::verify();return; }
        foreach(array_combine(self::tables(),['state','notices','deliveries','preferences','days']) as $table=>$method) { if (!Schema::hasTable($table)) { Schema::create($table,self::$method(...)); } }self::verify();
    }
    public static function verify(bool $legacy=false): void
    { foreach(array_combine(self::tables(),['state','notices','deliveries','preferences','days']) as $table=>$method) { SchemaVerifier::verify($table,$method==='preferences'?static fn(Blueprint $t)=>self::preferences($t,$legacy):self::$method(...)); } }
    public static function verifyForUpgrade(): void
    {
        try { self::verify(); }
        catch (\RuntimeException $error) {
            if (DB::table('migrations')->where('migration',self::OPT_IN_MIGRATION)->exists()) { throw $error; }
            self::verify(true);
        }
    }
    public static function requireOptIn(): void
    {
        self::verifyForUpgrade();
        Schema::table('domains_watch_preferences',static fn(Blueprint $t)=>$t->boolean('enabled')->default(false)->change());
        // Only a preference save advances revision. Old lock-insert defaults are not consent.
        DB::table('domains_watch_preferences')->where('revision',1)->where('enabled',true)->update(['enabled'=>false]);
        self::verify();
    }
}
