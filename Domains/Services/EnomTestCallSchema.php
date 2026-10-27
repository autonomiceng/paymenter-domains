<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class EnomTestCallSchema
{
    public const TABLE='domains_enom_test_calls';
    public const MIGRATION='2026_10_21_000035_create_enom_test_calls';
    private static function definition(Blueprint $t): void { $t->string('day',10)->primary();$t->unsignedBigInteger('used')->default(0); }
    public static function install(): void
    { if (!Schema::hasTable(self::TABLE)) { Schema::create(self::TABLE,self::definition(...)); }self::verify(); }
    public static function verify(): void { SchemaVerifier::verify(self::TABLE,self::definition(...)); }
}
