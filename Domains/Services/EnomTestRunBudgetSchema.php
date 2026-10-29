<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

final class EnomTestRunBudgetSchema
{
    public const LOCK = 'domains_enom_test_budget';
    public const RUNS = 'domains_enom_test_runs';
    public const PERMITS = 'domains_enom_test_permits';
    public const ALLOCATIONS = 'domains_enom_test_allocations';
    public const MIGRATION = '2026_10_27_000042_create_enom_test_run_budget';

    private static function definitions(): array
    {
        return [
            self::LOCK => static function (Blueprint $t): void {
                $t->unsignedInteger('id')->primary();
                $t->unsignedInteger('version')->default(0);
                $t->string('run_id',48)->nullable();
                $t->unsignedBigInteger('last_time')->default(0);
                $t->string('clock_id',64)->nullable();
                $t->unsignedBigInteger('monotonic_time')->default(0);
                $t->unsignedBigInteger('revision')->default(0);
            },
            self::RUNS => static function (Blueprint $t): void {
                $t->string('run_id',48)->primary();
                $t->string('generation',64)->unique();
                $t->text('policy');
                $t->string('phase',16);
                $t->string('account_fingerprint',64)->nullable();
                $t->unsignedBigInteger('used')->default(0);
                $t->unsignedBigInteger('remaining');
                $t->unsignedBigInteger('initial_credits');
            },
            self::PERMITS => static function (Blueprint $t): void {
                $t->string('permit_id',64)->primary();
                $t->string('run_id',48)->index();
                $t->string('account_fingerprint',64)->nullable();
                $t->string('action',64);
                $t->unsignedBigInteger('operation_id')->nullable();
                $t->unsignedBigInteger('attempt_time')->nullable()->index();
            },
            self::ALLOCATIONS => static function (Blueprint $t): void {
                $t->string('allocation_key',64)->primary();
                $t->string('run_id',48)->index();
                $t->text('allocation_id');
                $t->unsignedBigInteger('credits');
            },
        ];
    }
    public static function tables(): array { return array_keys(self::definitions()); }
    public static function install(): void
    {
        $present=array_filter(array_map(Schema::hasTable(...),self::tables()));
        if ($present) { self::verify(); return; }
        foreach (self::definitions() as $table=>$definition) { Schema::create($table,$definition); }
        DB::table(self::LOCK)->insert(['id'=>1,'version'=>0,'run_id'=>null,'last_time'=>0,'revision'=>0]);
        self::verify();
    }
    public static function verify(): void
    {
        foreach (self::definitions() as $table=>$definition) { SchemaVerifier::verify($table,$definition); }
        if (DB::table(self::LOCK)->count()!==1 || !DB::table(self::LOCK)->where('id',1)->exists()) { throw new \DomainException('Invalid TEST budget lock.'); }
    }
}
