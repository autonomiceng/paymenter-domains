<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Management\Records;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class DnsTemplateClaimsSchema extends TableSchema
{
    public const TABLE = 'domains_dns_template_claims';

    protected static function definitions(): array
    {
        return [
            self::TABLE => static function (Blueprint $t): void {
                $t->string('claim', 64)->primary();
            },
        ];
    }
}
