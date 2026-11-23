<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class ContactProfileSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_contact_profiles' => static function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('server_id');
                $t->string('account_fingerprint', 64);
                $t->string('profile_fingerprint', 64);
                $t->longText('details');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['server_id', 'profile_fingerprint']);
            },
        ];
    }
}
