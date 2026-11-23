<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class RetentionSchema extends TableSchema
{
    public const COLD='domains_retention_history',KEYS='domains_retention_keys',SCANS='domains_retention_scans',MARKS='domains_retention_marks';

    protected static function definitions(): array
    {
        return [
            'domains_retention_history' => static function (Blueprint $t): void {
                $t->id();
                $t->string('family', 32);
                $t->unsignedBigInteger('source_id');
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->unsignedBigInteger('occurred_at');
                $t->unsignedBigInteger('delete_after')->index();
                $t->longText('payload');
                $t->unique(['family', 'source_id']);
            },
            'domains_retention_keys' => static function (Blueprint $t): void {
                $t->string('family', 32);
                $t->string('replay_key', 64);
                $t->primary(['family', 'replay_key']);
            },
            'domains_retention_scans' => static function (Blueprint $t): void {
                $t->string('family', 32)->primary();
                $t->unsignedBigInteger('cursor')->default(0);
            },
            'domains_retention_marks' => static function (Blueprint $t): void {
                $t->string('family', 32);
                $t->unsignedBigInteger('source_id');
                $t->unsignedBigInteger('recorded_at')->nullable();
                $t->string('reason', 64)->nullable();
                $t->primary(['family', 'source_id']);
            },
        ];
    }
}
