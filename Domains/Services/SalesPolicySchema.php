<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SalesPolicySchema
{
    public static function install(): void
    {
        if (Schema::hasTable('domains_sales_policies')) { self::verify(); return; }
        Schema::create('domains_sales_policies', self::definition(...));
    }

    /** Slice 39 must call both retained schema verifiers after Migrator, even on migration skip. */
    public static function verify(): void
    {
        if (!Schema::hasTable('domains_sales_policies')) { throw new \RuntimeException('Missing retained seller policies.'); }
        SchemaVerifier::verify('domains_sales_policies', self::definition(...));
    }

    private static function definition(Blueprint $table): void
    {
        $table->id(); $table->string('tld', 63)->unique();
        $table->unsignedBigInteger('server_id')->nullable();
        $table->string('registrar_key', 32)->nullable(); $table->string('account_fingerprint', 64)->nullable();
        $table->unsignedBigInteger('revision')->default(1);
        $table->boolean('register_enabled')->default(false); $table->boolean('transfer_enabled')->default(false);
        $table->timestamps();
    }
}
