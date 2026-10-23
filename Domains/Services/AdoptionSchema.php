<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Storage only: a saved adoption never grants holding, funding or dispatch authority. */
final class AdoptionSchema
{
    private static function definition(Blueprint $t, bool $legacyDates = false): void
    {
        $t->id(); $t->string('request_key', 128)->unique(); $t->string('request_digest', 64);
        $t->unsignedBigInteger('domain_id')->nullable(); $t->unsignedBigInteger('service_id');
        $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('server_id');
        $t->string('registrar_key', 32); $t->string('environment', 16); $t->string('account_fingerprint', 64);
        $t->string('state', 16); $t->string('origin', 32); $t->longText('evidence');
        $t->unsignedBigInteger('actor_id'); $t->text('reason'); $t->boolean('do_not_renew');
        $t->unsignedBigInteger('cancellation_id')->nullable();
        ($legacyDates ? $t->timestamp('observed_at') : $t->dateTime('observed_at')); ($legacyDates ? $t->timestamp('valid_until') : $t->dateTime('valid_until')); $t->timestamp('committed_at')->nullable();
        // Only committed rows populate this projection; previews keep their proposed generation in evidence.
        $t->unsignedBigInteger('authority_generation')->nullable(); $t->timestamps();
        $t->unique(['domain_id', 'authority_generation']);
    }
    public static function install(): void
    {
        if (!Schema::hasTable('domains_adoptions')) { Schema::create('domains_adoptions', self::definition(...)); }
        self::verify();
    }
    public static function verify(bool $legacyDates = false): void { SchemaVerifier::verify('domains_adoptions', static fn (Blueprint $t) => self::definition($t, $legacyDates)); }
}
