<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class DnsTemplateClaimsSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_dns_template_claims' => static function (Blueprint $t): void {
                $t->string('claim', 64)->primary();
            },
        ];
    }
}
