<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Retained dormant queue/budgets. Install and verify after Migrator at gate39. */
final class ObservationSchema
{
    private static function definitions(): array
    {
        return [
            'domains_read_budgets' => static function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('server_id')->unique(); $t->string('account_fingerprint',64);
                $t->unsignedBigInteger('window_at'); $t->unsignedInteger('used')->default(0);
                $t->unsignedBigInteger('next_api_at')->default(0); $t->text('leases');
            },
            'domains_observation_queues' => static function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('server_id')->unique(); $t->string('account_fingerprint',64);
                $t->unsignedBigInteger('catalogue_cursor')->default(0); $t->unsignedBigInteger('catalogue_due')->default(0);
                $t->string('catalogue_claim',32)->nullable(); $t->unsignedBigInteger('catalogue_lease')->default(0);
                $t->string('catalogue_hold',32)->nullable(); $t->unsignedInteger('catalogue_resumes')->default(0); $t->text('catalogue_audit')->nullable();
                $t->unsignedBigInteger('cursor')->default(0); $t->unsignedBigInteger('turn')->default(0);
                foreach (['refund','timeout'] as $kind) { $t->unsignedBigInteger($kind.'_cursor')->default(0); $t->unsignedBigInteger($kind.'_turn')->default(0); }
            },
            'domains_observation_work' => static function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('server_id'); $t->string('account_fingerprint',64);
                $t->unsignedBigInteger('domain_id'); $t->unsignedBigInteger('generation');
                $t->unsignedBigInteger('due_at')->index(); $t->unsignedInteger('tries')->default(0);
                $t->index(['server_id','account_fingerprint','due_at'], $t->getTable().'_account_due_index');
                $t->unsignedInteger('resumes')->default(0); $t->string('claim',32)->nullable();
                $t->unsignedBigInteger('lease_until')->default(0); $t->string('safe_code',32)->nullable();
                $t->string('phase',64)->default('holding'); $t->unique(['domain_id','generation','phase']);
            },
        ];
    }
    public static function install(): void
    {
        $present=array_filter(array_keys(self::definitions()),fn($name)=>Schema::hasTable($name));
        if ($present && count($present)!==count(self::definitions())) { throw new \RuntimeException('Partial retained observation schema.'); }
        foreach (self::definitions() as $name=>$definition) {
            if (Schema::hasTable($name)) {
                if ($name === 'domains_observation_work' && !Schema::hasIndex($name, $name.'_account_due_index')) {
                    Schema::table($name, static fn (Blueprint $t) => $t->index(['server_id','account_fingerprint','due_at'], $t->getTable().'_account_due_index'));
                }
                SchemaVerifier::verify($name,$definition);
            }
            else { Schema::create($name,$definition); }
        }
    }
    public static function verify(): void
    {
        foreach (self::definitions() as $name=>$definition) {
            if (!Schema::hasTable($name)) { throw new \RuntimeException('Missing retained observation schema.'); }
            SchemaVerifier::verify($name,$definition);
        }
    }
}
