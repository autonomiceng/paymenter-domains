<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class WatchScheduleSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_watch_registry' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('watch_id');
                $t->unsignedBigInteger('user_id');
                $t->string('stamp', 64);
                $t->unsignedBigInteger('source_cursor')->default(0);
                $t->string('bootstrap_revision', 64);
                $t->unsignedBigInteger('started_at');
                $t->unsignedBigInteger('completed_at');
                $t->unsignedBigInteger('valid_until')->nullable();
                $t->string('result', 16);
                $t->string('reason', 32);
                $t->string('safe_code', 64)->nullable();
                $t->longText('facts');
                $t->index(['user_id', 'id']);
                $t->index(['watch_id', 'completed_at']);
            },
            'domains_public_rdap_pool' => static function (Blueprint $t): void {
                $t->string('scope', 64)->primary();
                $t->unsignedBigInteger('window_at');
                $t->unsignedInteger('used')->default(0);
                $t->string('claim', 64)->nullable();
                $t->unsignedBigInteger('lease_until')->nullable();
                $t->unsignedInteger('failures')->default(0);
                $t->unsignedBigInteger('backoff_until')->default(0);
            },
            'domains_watch_schedule' => static function (Blueprint $t): void {
                $t->string('scope', 64)->primary();
                $t->unsignedBigInteger('due_at')->default(0);
                $t->unsignedBigInteger('cursor')->default(0);
                $t->unsignedBigInteger('progress_at')->nullable();
                $t->unsignedBigInteger('alert_at')->nullable();
                $t->longText('data')->nullable();
            },
            'domains_watch_discovery' => static function (Blueprint $t): void {
                $t->unsignedBigInteger('product_id')->primary();
                $t->unsignedBigInteger('cursor')->default(0);
                $t->unsignedBigInteger('turn')->default(0);
                $t->unsignedBigInteger('due_at')->default(0);
                $t->boolean('hint')->default(false);
            },
            'domains_public_evidence_refs' => static function (Blueprint $t): void {
                $t->string('family', 16);
                $t->unsignedBigInteger('observation_id');
                $t->primary(['family', 'observation_id']);
            },
        ];
    }
}
