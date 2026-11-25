<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class DnsTemplatesSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_dns_templates' => static function (Blueprint $t): void {
                $t->id();
                $t->string('name', 128);
                $t->json('nameservers');
                $t->unsignedBigInteger('revision')->default(1);
                $t->boolean('enabled')->default(false);
                $t->unsignedBigInteger('edited_by');
                $t->text('reason');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            },
        ];
    }
}
