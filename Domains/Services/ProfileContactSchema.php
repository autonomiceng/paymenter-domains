<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Separate from domain operations: profile work cannot become a sibling acquisition obligation. */
final class ProfileContactSchema
{
    public const TABLE='domains_profile_operations';
    public const MIGRATION='2026_11_02_000049_create_profile_operations';
    private static function definition(Blueprint $t): void
    {
        $t->id();$t->unsignedBigInteger('operation_id')->unique();$t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('server_id');$t->string('account_fingerprint',64);$t->unsignedBigInteger('authority_generation');
        $t->string('payload_digest',64);$t->string('state',24);$t->longText('attempt');$t->timestamps();
        $t->index(['server_id','account_fingerprint'],$t->getTable().'_account_i');
    }
    public static function install(): void
    {if(!Schema::hasTable(self::TABLE)){Schema::create(self::TABLE,self::definition(...));}self::verify();}
    public static function verify(): void
    {if(!Schema::hasTable(self::TABLE)){throw new \RuntimeException('Missing retained profile operations.');}SchemaVerifier::verify(self::TABLE,self::definition(...));}
}
