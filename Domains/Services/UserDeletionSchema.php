<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

final class UserDeletionSchema extends TableSchema
{
    public const TABLE='domains_user_deletions';

    protected static function definitions(): array
    {
        return [
            'domains_user_deletions' => static function (Blueprint $t): void {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    $t->bigIncrements('user_id');
                } else {
                    $t->unsignedBigInteger('user_id')->primary();
                }
                $t->dateTime('created_at');
            },
        ];
    }
}
