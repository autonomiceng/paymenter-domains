<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

final class StaffAuditSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_staff_review_keys' => static function (Blueprint $t): void {
                $t->unsignedBigInteger('server_id');
                $t->string('review_key', 32);
                $t->unsignedBigInteger('expires_at');
                $t->primary(['server_id', 'review_key']);
            },
            'domains_audit_archives' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('server_id');
                $t->string('family', 32);
                $t->text('payload');
                $t->string('event_key', 64)->nullable();
                $t->index(['server_id', 'family', 'id']);
                $t->unique(['event_key']);
            },
        ];
    }

    public static function verify(): void
    {
        parent::verify();
        if (DB::table('domains_audit_archives')->whereNull('event_key')->exists()) {
            throw new \RuntimeException('Retained Domains audit event has no identity.');
        }
    }
}
