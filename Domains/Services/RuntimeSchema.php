<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class RuntimeSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_runtime_turns' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id')->unique();
                $t->string('account_fingerprint', 64);
                $t->unsignedBigInteger('turn')->default(0);
                $t->unsignedBigInteger('due_at')->default(0);
                $t->unsignedBigInteger('operation_cursor')->default(0);
                $t->unsignedBigInteger('domain_cursor')->default(0);
                $t->unsignedBigInteger('tld_cursor')->default(0);
                $t->unsignedBigInteger('finished_at')->default(0);
            },
            'domains_runtime_operations' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('operation_id')->unique();
                $t->unsignedBigInteger('due_at')->default(0);
                $t->unsignedInteger('attempts')->default(0);
            },
        ];
    }
}
