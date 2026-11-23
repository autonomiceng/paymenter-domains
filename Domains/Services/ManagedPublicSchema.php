<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class ManagedPublicSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_managed_public_config' => static function (Blueprint $t): void {
                $t->unsignedBigInteger('domain_id')->primary();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('profile_id')->nullable();
                $t->string('expected_dns', 16)->nullable();
                $t->unsignedBigInteger('revision')->default(1);
                $t->json('notice_preferences');
                $t->unsignedBigInteger('origin_watch_id')->nullable();
                $t->json('dns_episode')->nullable();
                $t->unsignedBigInteger('edited_by')->nullable();
                $t->text('reason')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->index(['profile_id']);
            },
            'domains_managed_public_observations' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('domain_id');
                $t->unsignedBigInteger('user_id');
                $t->string('account_fingerprint', 64);
                $t->unsignedBigInteger('generation');
                $t->unsignedBigInteger('domain_revision');
                $t->unsignedBigInteger('config_revision');
                $t->unsignedBigInteger('profile_id')->nullable();
                $t->unsignedBigInteger('profile_revision')->nullable();
                $t->string('source_digest', 64);
                $t->unsignedBigInteger('source_cursor')->default(0);
                $t->string('check_kind', 16);
                $t->unsignedBigInteger('started_at');
                $t->unsignedBigInteger('completed_at');
                $t->unsignedBigInteger('valid_until')->nullable();
                $t->string('result', 16);
                $t->string('dns', 16)->nullable();
                $t->boolean('website')->nullable();
                $t->boolean('email')->nullable();
                $t->boolean('mixed');
                $t->boolean('partial');
                $t->boolean('ns_confirmed')->default(false);
                $t->string('safe_code', 64)->nullable();
                $t->longText('facts');
                $t->string('digest', 64);
                $t->index(['domain_id', 'check_kind', 'completed_at'], $t->getTable().'_facts_idx');
            },
            'domains_public_dns_pool' => static function (Blueprint $t): void {
                $t->unsignedTinyInteger('id')->primary();
                $t->unsignedBigInteger('window_at');
                $t->unsignedInteger('used')->default(0);
                $t->json('active');
                $t->unsignedBigInteger('dispatch_cursor')->default(0);
                $t->unsignedBigInteger('sweep_cursor')->default(0);
                $t->unsignedBigInteger('sweep_due_at')->default(0);
            },
        ];
    }
}
