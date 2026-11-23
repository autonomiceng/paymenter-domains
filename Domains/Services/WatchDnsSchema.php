<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class WatchDnsSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_watch_profiles' => static function (Blueprint $t): void {
                $t->id();
                $t->string('name', 128);
                $t->json('nameservers');
                $t->json('website_endpoints');
                $t->json('email_endpoints');
                $t->unsignedBigInteger('revision')->default(1);
                $t->boolean('enabled')->default(false);
                $t->unsignedBigInteger('edited_by');
                $t->text('reason');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            },
            'domains_watch_observations' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('watch_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('watch_revision');
                $t->unsignedBigInteger('profile_id');
                $t->unsignedBigInteger('profile_revision');
                $t->string('psl_revision', 64);
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
                $t->string('safe_code', 64)->nullable();
                $t->longText('facts');
                $t->string('digest', 64);
                $t->index(['user_id', 'id']);
                $t->index(['watch_id', 'check_kind', 'completed_at'], $t->getTable().'_facts_idx');
            },
            'domains_watch_work' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('watch_id');
                $t->string('kind', 16);
                $t->unsignedBigInteger('due_at');
                $t->string('claim', 64)->nullable();
                $t->unsignedBigInteger('lease_until')->nullable();
                $t->unsignedInteger('attempts')->default(0);
                $t->unsignedBigInteger('last_success_at')->nullable();
                $t->unsignedBigInteger('backoff_until')->nullable();
                $t->string('safe_code', 64)->nullable();
                $t->unsignedBigInteger('watch_revision');
                $t->unsignedBigInteger('cursor')->default(0);
                $t->unsignedBigInteger('source_cursor')->default(0);
                $t->unique(['watch_id', 'kind']);
            },
        ];
    }
}
