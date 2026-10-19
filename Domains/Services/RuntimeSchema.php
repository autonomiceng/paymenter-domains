<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class RuntimeSchema
{
    private static function definition(Blueprint $t): void
    {
        $t->id();$t->unsignedBigInteger('server_id')->unique();$t->string('account_fingerprint',64);
        foreach(['turn','due_at','operation_cursor','domain_cursor','tld_cursor','finished_at'] as $field){$t->unsignedBigInteger($field)->default(0);}
    }
    private static function operation(Blueprint $t): void
    {$t->id();$t->unsignedBigInteger('operation_id')->unique();$t->unsignedBigInteger('due_at')->default(0);$t->unsignedInteger('attempts')->default(0);}
    public static function install(): void
    {if(!Schema::hasTable('domains_runtime_turns')){Schema::create('domains_runtime_turns',self::definition(...));}if(!Schema::hasTable('domains_runtime_operations')){Schema::create('domains_runtime_operations',self::operation(...));}self::verify();}
    public static function verify(): void {SchemaVerifier::verify('domains_runtime_turns',self::definition(...));SchemaVerifier::verify('domains_runtime_operations',self::operation(...));}
}
