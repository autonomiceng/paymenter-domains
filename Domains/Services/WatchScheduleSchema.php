<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Independent registry facts/quota; DNS retains the shared W2 pool and work ledger. */
final class WatchScheduleSchema
{
    public const MIGRATION = '2026_10_20_000031_create_watch_schedule';
    public static function tables(): array { return ['domains_watch_registry', 'domains_public_rdap_pool', 'domains_watch_schedule', 'domains_watch_discovery','domains_public_evidence_refs']; }
    private static function observations(Blueprint $t): void
    {
        $t->id(); $t->unsignedBigInteger('watch_id'); $t->unsignedBigInteger('user_id'); $t->string('stamp',64);
        $t->unsignedBigInteger('source_cursor')->default(0); $t->string('bootstrap_revision',64);
        $t->unsignedBigInteger('started_at'); $t->unsignedBigInteger('completed_at'); $t->unsignedBigInteger('valid_until')->nullable();
        $t->string('result',16); $t->string('reason',32); $t->string('safe_code',64)->nullable(); $t->longText('facts');
        $t->index(['watch_id','completed_at']);
    }
    private static function quota(Blueprint $t): void
    {
        $t->string('scope',64)->primary(); $t->unsignedBigInteger('window_at'); $t->unsignedInteger('used')->default(0);
        $t->string('claim',64)->nullable(); $t->unsignedBigInteger('lease_until')->nullable();
        $t->unsignedInteger('failures')->default(0); $t->unsignedBigInteger('backoff_until')->default(0);
    }
    private static function schedule(Blueprint $t): void
    {
        $t->string('scope',64)->primary(); $t->unsignedBigInteger('due_at')->default(0); $t->unsignedBigInteger('cursor')->default(0);
        $t->unsignedBigInteger('progress_at')->nullable(); $t->unsignedBigInteger('alert_at')->nullable(); $t->longText('data')->nullable();
    }
    private static function discovery(Blueprint $t): void
    {
        $t->unsignedBigInteger('product_id')->primary(); $t->unsignedBigInteger('cursor')->default(0);
        $t->unsignedBigInteger('turn')->default(0); $t->unsignedBigInteger('due_at')->default(0); $t->boolean('hint')->default(false);
    }
    private static function references(Blueprint $t): void { $t->string('family',16); $t->unsignedBigInteger('observation_id'); $t->primary(['family','observation_id']); }
    public static function install(): void
    {
        if (array_filter(array_map(Schema::hasTable(...),self::tables()))) { self::verify(); return; }
        foreach (array_combine(self::tables(),['observations','quota','schedule','discovery','references']) as $table=>$method) { Schema::create($table,self::$method(...)); }
        self::verify();
    }
    public static function verify(): void
    {
        foreach (array_combine(self::tables(),['observations','quota','schedule','discovery','references']) as $table=>$method) { SchemaVerifier::verify($table,self::$method(...)); }
    }
}
