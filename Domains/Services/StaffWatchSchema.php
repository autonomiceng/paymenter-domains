<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class StaffWatchSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_watch_staff_claims' => static function (Blueprint $t): void {
                $t->string('claim', 64)->primary();
                $t->unsignedBigInteger('subject_id');
                $t->string('action', 16);
                $t->unsignedBigInteger('created_at');
                $t->index(['subject_id', 'action', 'created_at'], 'dws_'.substr(hash('sha256', $t->getTable()), 0, 16));
            },
        ];
    }
}
