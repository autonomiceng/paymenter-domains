<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class RdapQuotaSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_public_rdap_pool' => static function (Blueprint $t): void {
                $t->string('scope', 64)->primary();
                $t->unsignedBigInteger('window_at');
                $t->unsignedInteger('used')->default(0);
                $t->string('claim', 64)->nullable();
                $t->unsignedBigInteger('lease_until')->nullable();
                $t->unsignedInteger('failures')->default(0);
                $t->unsignedBigInteger('backoff_until')->default(0);
            },
        ];
    }
}
