<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class SalesPolicySchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_sales_policies' => static function (Blueprint $t): void {
                $t->id();
                $t->string('tld', 63)->unique();
                $t->unsignedBigInteger('server_id')->nullable();
                $t->string('registrar_key', 32)->nullable();
                $t->string('account_fingerprint', 64)->nullable();
                $t->unsignedBigInteger('revision')->default(1);
                $t->boolean('register_enabled')->default(false);
                $t->boolean('transfer_enabled')->default(false);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            },
        ];
    }
}
