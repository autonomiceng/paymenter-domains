<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class ManagedPublicWorkSchema extends TableSchema
{
    public const TABLE = 'domains_managed_public_work';

    protected static function definitions(): array
    {
        return [
            'domains_managed_public_work' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('domain_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('generation');
                $t->string('account_fingerprint', 64);
                $t->string('kind', 16);
                $t->unsignedBigInteger('due_at');
                $t->unsignedBigInteger('profile_id')->nullable();
                $t->unsignedBigInteger('profile_revision')->nullable();
                $t->unsignedBigInteger('origin_watch_id')->nullable();
                $t->unsignedBigInteger('origin_observation_id')->nullable();
                $t->string('target_stamp', 64);
                $t->string('safe_code', 32)->default('awaiting_acceptance');
                $t->unsignedBigInteger('domain_revision')->nullable();
                $t->unsignedBigInteger('config_revision')->nullable();
                $t->string('claim', 64)->nullable();
                $t->unsignedBigInteger('lease_until')->nullable();
                $t->unsignedInteger('attempts')->default(0);
                $t->unsignedBigInteger('last_success_at')->nullable();
                $t->unsignedBigInteger('backoff_until')->nullable();
                $t->unsignedBigInteger('cursor')->default(0);
                $t->unsignedBigInteger('source_cursor')->default(0);
                $t->unsignedBigInteger('retired_at')->nullable();
                $t->unique(['domain_id', 'generation', 'kind']);
                $t->index(['due_at', 'retired_at']);
            },
        ];
    }
}
