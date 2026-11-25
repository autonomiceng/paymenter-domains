<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class ComplimentaryAccountSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_complimentary_accounts' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->boolean('enabled');
                $t->unsignedBigInteger('actor_id');
                $t->string('reason', 500);
                $t->dateTime('created_at');
                $t->string('time_zone', 64)->nullable();
                $t->index(['user_id', 'id']);
            },
            'domains_complimentary_approvals' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('operation_id');
                $t->string('amount', 32);
                $t->unsignedBigInteger('actor_id');
                $t->string('reason', 500);
                $t->dateTime('created_at');
                $t->string('time_zone', 64)->nullable();
                $t->index(['operation_id', 'id']);
            },
        ];
    }
}
