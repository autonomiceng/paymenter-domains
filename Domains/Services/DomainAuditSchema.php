<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class DomainAuditSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_audit' => static function (Blueprint $t): void {
                $t->id();
                $t->string('subject_type', 40);
                $t->unsignedBigInteger('subject_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('event', 64);
                $t->text('reason')->nullable();
                $t->string('before_revision', 64)->nullable();
                $t->string('after_revision', 64);
                $t->longText('data');
                $t->dateTime('created_at');
                $t->string('time_zone', 64)->nullable();
                $t->index(['user_id', 'id']);
                $t->index(['subject_type', 'subject_id', 'event', 'id']);
            },
        ];
    }
}
