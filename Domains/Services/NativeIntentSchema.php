<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Retain a cancellation decision even if a subsequent native SQL statement fails. */
final class NativeIntentSchema
{
    private static function definition(Blueprint $t): void
    { $t->unsignedBigInteger('service_id')->primary();$t->string('kind',32);$t->timestamp('created_at'); }
    public static function install(): void
    { if(!Schema::hasTable('domains_native_intents')){Schema::create('domains_native_intents',self::definition(...));}self::verify(); }
    public static function verify(): void { SchemaVerifier::verify('domains_native_intents',self::definition(...)); }
}
