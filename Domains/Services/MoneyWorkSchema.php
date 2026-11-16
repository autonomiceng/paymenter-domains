<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Money decisions deferred by database contention. Identifiers only; the decision rereads everything under its locks. */
final class MoneyWorkSchema
{
    public const TABLE='domains_money_work';
    public const MIGRATION='2026_11_15_000069_create_money_work';
    private static function definition(Blueprint $t): void
    {
        $t->id();$t->unsignedBigInteger('server_id');$t->string('account_fingerprint',64);$t->string('kind',32);
        $t->unsignedBigInteger('subject_id');$t->unsignedBigInteger('revision');$t->unsignedBigInteger('turn');
        $t->unique(['server_id','kind','subject_id'],$t->getTable().'_subject_u');$t->index(['server_id','turn'],$t->getTable().'_turn_i');
    }
    public static function install(): void
    {if(!Schema::hasTable(self::TABLE)){Schema::create(self::TABLE,self::definition(...));}self::verify();}
    public static function verify(): void
    {if(!Schema::hasTable(self::TABLE)){throw new \RuntimeException('Missing retained money work.');}SchemaVerifier::verify(self::TABLE,self::definition(...));}
}
