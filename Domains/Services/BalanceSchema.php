<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class BalanceSchema
{
    public const TABLE = 'domains_registrar_balances';
    private static function definition(Blueprint $t): void
    {
        $t->id(); $t->unsignedBigInteger('server_id'); $t->string('account_fingerprint',64);
        $t->string('amount',25)->nullable(); $t->string('currency',3)->nullable();
        $t->unsignedBigInteger('read_at')->nullable(); $t->string('last_error_code',32)->nullable();
        $t->string('source',12)->nullable(); $t->unsignedBigInteger('attempted_at')->default(0);
        $t->unsignedBigInteger('daily_due_at')->default(0); $t->unsignedBigInteger('manual_due_at')->default(0);
        $t->string('notified_on',10)->nullable(); $t->unique(['server_id','account_fingerprint']);
    }
    public static function install(): void
    {
        if (!Schema::hasTable(self::TABLE)) { Schema::create(self::TABLE,self::definition(...)); }
        self::verify();
    }
    public static function verify(): void { SchemaVerifier::verify(self::TABLE,self::definition(...)); }
}
