<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class ReminderSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_reminder_schedules' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id')->unique();
                $t->string('account_fingerprint', 64);
                $t->boolean('enabled')->default(false);
                $t->text('before_days');
                $t->text('after_days');
                $t->unsignedBigInteger('revision')->default(1);
                $t->unsignedBigInteger('cursor')->default(0);
                $t->unsignedBigInteger('turn')->default(0);
                $t->unsignedBigInteger('due_at')->default(0);
            },
            'domains_reminder_deliveries' => static function (Blueprint $t): void {
                $t->id();
                $t->string('delivery_key', 64)->unique();
                $t->unsignedBigInteger('domain_id')->index();
                $t->string('template', 64);
                $t->string('state', 24);
                $t->unsignedBigInteger('created_at');
                $t->unsignedBigInteger('accepted_at')->nullable();
            },
            'domains_obligation_alerts' => static function (Blueprint $t): void {
                $t->id();
                $t->string('alert_key', 64)->unique();
                $t->unsignedBigInteger('domain_id')->index();
                $t->unsignedBigInteger('generation');
                $t->string('code', 40);
                $t->unsignedBigInteger('operation_id')->nullable();
                $t->unsignedBigInteger('first_seen');
                $t->unsignedBigInteger('last_seen');
                $t->unsignedBigInteger('resolved_at')->nullable();
            },
        ];
    }
}
