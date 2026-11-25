<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Storefront\Search;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class DnsQuotaSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_public_dns_pool' => static function (Blueprint $t): void {
                $t->unsignedTinyInteger('id')->primary();
                $t->unsignedBigInteger('window_at');
                $t->unsignedInteger('used')->default(0);
                $t->json('active');
            },
        ];
    }
}
