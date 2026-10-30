<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Immutable evidence and debt are independent of a run's releasable credits. */
final class EnomTestLegacyBudgetSchema
{
    public const TABLE='domains_enom_test_legacy_budget';
    public const MIGRATION='2026_10_28_000043_create_enom_test_legacy_budget';
    private static function definition(Blueprint $t): void {
        $t->unsignedInteger('id')->primary();
        $t->string('run_id',48)->unique();
        $t->string('proof_hash',64);
        $t->longText('proof');
        $t->unsignedBigInteger('debt');
        $t->unsignedBigInteger('credits');
    }
    public static function install(): void {
        if(!Schema::hasTable(self::TABLE)){Schema::create(self::TABLE,self::definition(...));}
        self::verify();
    }
    public static function verify(): void { SchemaVerifier::verify(self::TABLE,self::definition(...)); }
}
