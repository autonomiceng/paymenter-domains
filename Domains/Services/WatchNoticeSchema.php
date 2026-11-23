<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class WatchNoticeSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_watch_notice_state' => static function (Blueprint $t): void {
                $t->string('scope', 64)->primary();
                $t->unsignedBigInteger('user_id');
                $t->longText('data');
                $t->unsignedBigInteger('updated_at');
            },
            'domains_watch_notices' => static function (Blueprint $t): void {
                $t->id();
                $t->string('notice_key', 64)->unique();
                $t->string('scope', 64)->index();
                $t->string('target_kind', 8);
                $t->unsignedBigInteger('watch_id')->nullable();
                $t->unsignedBigInteger('managed_domain_id')->nullable();
                $t->unsignedBigInteger('user_id');
                $t->string('kind', 24);
                $t->string('episode_key', 64);
                $t->integer('threshold')->nullable();
                $t->string('family', 16);
                $t->unsignedBigInteger('observation_id');
                $t->string('state', 16);
                $t->unsignedBigInteger('first_seen_at');
                $t->unsignedBigInteger('last_seen_at');
                $t->unsignedBigInteger('resolved_at')->nullable();
                $t->longText('data');
                $t->index(['user_id', 'state']);
            },
            'domains_watch_deliveries' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('notice_id');
                $t->unsignedBigInteger('user_id');
                $t->string('channel', 8);
                $t->string('delivery_key', 64)->unique();
                $t->string('template_key', 64);
                $t->string('state', 16);
                $t->unsignedInteger('attempts')->default(1);
                $t->unsignedBigInteger('reserved_at');
                $t->unsignedBigInteger('accepted_at')->nullable();
                $t->string('safe_code', 64)->nullable();
                $t->unsignedBigInteger('native_id')->nullable();
                $t->unsignedBigInteger('resend_of')->nullable();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('reason', 500)->nullable();
                $t->index(['user_id', 'id']);
                $t->index(['notice_id', 'channel']);
            },
            'domains_watch_preferences' => static function (Blueprint $t): void {
                $t->string('scope', 64)->primary();
                $t->unsignedBigInteger('user_id')->index();
                $t->boolean('enabled')->default(false);
                $t->unsignedBigInteger('revision')->default(1);
            },
            'domains_watch_mail_days' => static function (Blueprint $t): void {
                $t->string('scope', 64)->primary();
                $t->unsignedBigInteger('delivery_id');
                $t->unsignedBigInteger('created_at');
            },
        ];
    }
}
