<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class ProfileContactSchema extends TableSchema
{
    public const TABLE='domains_profile_operations';

    protected static function definitions(): array
    {
        return [
            'domains_profile_operations' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('operation_id')->unique();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('server_id');
                $t->string('account_fingerprint', 64);
                $t->unsignedBigInteger('authority_generation');
                $t->string('payload_digest', 64);
                $t->string('state', 24);
                $t->longText('attempt');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->index(['server_id', 'account_fingerprint'], $t->getTable().'_account_i');
            },
        ];
    }
}
