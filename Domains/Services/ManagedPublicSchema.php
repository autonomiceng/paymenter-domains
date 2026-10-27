<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Additive evolution of the original dormant enrollment; no replacement work ledger. */
final class ManagedPublicSchema
{
    public static function tables(): array
    { return ['domains_managed_public_config', 'domains_managed_public_observations', 'domains_public_dns_pool']; }
    public static function workColumns(Blueprint $t): void
    {
        $t->unsignedBigInteger('domain_revision')->nullable(); $t->unsignedBigInteger('config_revision')->nullable();
        $t->string('claim', 64)->nullable(); $t->unsignedBigInteger('lease_until')->nullable();
        $t->unsignedInteger('attempts')->default(0); $t->unsignedBigInteger('last_success_at')->nullable();
        $t->unsignedBigInteger('backoff_until')->nullable(); $t->unsignedBigInteger('cursor')->default(0);
        $t->unsignedBigInteger('source_cursor')->default(0); $t->unsignedBigInteger('retired_at')->nullable();
        $t->index(['due_at', 'retired_at']);
    }
    private static function config(Blueprint $t): void
    {
        $t->unsignedBigInteger('domain_id')->primary(); $t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('profile_id')->nullable(); $t->string('expected_dns', 16)->nullable();
        $t->unsignedBigInteger('revision')->default(1); $t->json('notice_preferences');
        $t->unsignedBigInteger('origin_watch_id')->nullable(); $t->json('dns_episode')->nullable();
        $t->unsignedBigInteger('edited_by')->nullable(); $t->text('reason')->nullable(); $t->timestamps();
    }
    private static function observations(Blueprint $t): void
    {
        $t->id(); $t->unsignedBigInteger('domain_id'); $t->unsignedBigInteger('user_id');
        $t->string('account_fingerprint', 64); $t->unsignedBigInteger('generation');
        $t->unsignedBigInteger('domain_revision'); $t->unsignedBigInteger('config_revision');
        $t->unsignedBigInteger('profile_id')->nullable(); $t->unsignedBigInteger('profile_revision')->nullable();
        $t->string('source_digest', 64); $t->unsignedBigInteger('source_cursor')->default(0);
        $t->string('check_kind', 16); $t->unsignedBigInteger('started_at'); $t->unsignedBigInteger('completed_at');
        $t->unsignedBigInteger('valid_until')->nullable(); $t->string('result', 16);
        $t->string('dns', 16)->nullable(); $t->boolean('website')->nullable(); $t->boolean('email')->nullable();
        $t->boolean('mixed'); $t->boolean('partial'); $t->boolean('ns_confirmed')->default(false);
        $t->string('safe_code', 64)->nullable(); $t->longText('facts'); $t->string('digest', 64);
        $t->index(['domain_id', 'check_kind', 'completed_at'], $t->getTable().'_facts_idx');
    }
    private static function pool(Blueprint $t): void
    {
        $t->unsignedTinyInteger('id')->primary(); $t->unsignedBigInteger('window_at');
        $t->unsignedInteger('used')->default(0); $t->json('active');
        $t->unsignedBigInteger('dispatch_cursor')->default(0); $t->unsignedBigInteger('sweep_cursor')->default(0); $t->unsignedBigInteger('sweep_due_at')->default(0);
    }
    public static function install(): void
    {
        if (array_filter(array_map(Schema::hasTable(...), self::tables())) || Schema::hasColumn(ManagedPublicWorkSchema::TABLE, 'claim')) {
            self::verify(); return;
        }
        ManagedPublicWorkSchema::verify();
        Schema::table(ManagedPublicWorkSchema::TABLE, self::workColumns(...));
        foreach (array_combine(self::tables(), ['config', 'observations', 'pool']) as $table => $method) {
            Schema::create($table, self::$method(...));
        }
        self::verify();
    }
    public static function verify(): void
    {
        if (!Schema::hasColumn(ManagedPublicWorkSchema::TABLE, 'claim')) { throw new \RuntimeException('Managed public work upgrade is missing.'); }
        ManagedPublicWorkSchema::verify();
        foreach (array_combine(self::tables(), ['config', 'observations', 'pool']) as $table => $method) {
            SchemaVerifier::verify($table, self::$method(...));
        }
    }
}
