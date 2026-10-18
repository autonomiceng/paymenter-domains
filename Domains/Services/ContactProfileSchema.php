<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class ContactProfileSchema
{
    public static function install(): void
    {
        $definition = static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id')->index(); $table->unsignedBigInteger('server_id');
            $table->string('account_fingerprint', 64); $table->string('profile_fingerprint', 64);
            $table->longText('details'); $table->timestamps();
            // A provider profile can never belong to two local customers in the same account.
            $table->unique(['server_id', 'profile_fingerprint']);
        };
        if (Schema::hasTable('domains_contact_profiles')) { SchemaVerifier::verify('domains_contact_profiles', $definition); }
        else { Schema::create('domains_contact_profiles', $definition); }
    }
    public static function verify(): void
    {
        if (!Schema::hasTable('domains_contact_profiles')) { throw new \RuntimeException('Missing retained contact profiles.'); }
        self::install();
    }
}
