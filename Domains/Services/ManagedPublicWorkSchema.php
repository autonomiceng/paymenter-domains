<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Dormant managed DNS enrollment only. Public readers and notice execution need separate acceptance. */
final class ManagedPublicWorkSchema
{
    public const TABLE = 'domains_managed_public_work';
    private static function definition(Blueprint $t): void
    {
        $t->id(); $t->unsignedBigInteger('domain_id'); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('generation');
        $t->string('account_fingerprint', 64); $t->string('kind', 16); $t->unsignedBigInteger('due_at');
        $t->unsignedBigInteger('profile_id')->nullable(); $t->unsignedBigInteger('profile_revision')->nullable();
        $t->unsignedBigInteger('origin_watch_id')->nullable(); $t->unsignedBigInteger('origin_observation_id')->nullable();
        $t->string('target_stamp', 64); $t->string('safe_code', 32)->default('awaiting_acceptance');
        $t->unique(['domain_id', 'generation', 'kind']);
    }
    public static function install(): void
    {
        if (!Schema::hasTable(self::TABLE)) { Schema::create(self::TABLE, self::definition(...)); }
        self::verify();
    }
    public static function verify(): void { SchemaVerifier::verify(self::TABLE, self::definition(...)); }
}
