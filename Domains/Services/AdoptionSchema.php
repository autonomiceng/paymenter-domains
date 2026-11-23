<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;

final class AdoptionSchema extends TableSchema
{
    protected static function definitions(): array
    {
        return [
            'domains_adoptions' => static function (Blueprint $t): void {
                $t->id();
                $t->string('request_key', 128)->unique();
                $t->string('request_digest', 64);
                $t->unsignedBigInteger('domain_id')->nullable();
                $t->unsignedBigInteger('service_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('server_id');
                $t->string('registrar_key', 32);
                $t->string('environment', 16);
                $t->string('account_fingerprint', 64);
                $t->string('state', 16);
                $t->string('origin', 32);
                $t->longText('evidence');
                $t->unsignedBigInteger('actor_id');
                $t->text('reason');
                $t->boolean('do_not_renew');
                $t->unsignedBigInteger('cancellation_id')->nullable();
                $t->dateTime('observed_at');
                $t->dateTime('valid_until');
                $t->timestamp('committed_at')->nullable();
                $t->unsignedBigInteger('authority_generation')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['domain_id', 'authority_generation']);
            },
        ];
    }
}
