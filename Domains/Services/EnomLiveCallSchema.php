<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
final class EnomLiveCallSchema
{
    public const TABLE='domains_enom_live_calls';
    public const MIGRATION='2026_10_22_000037_create_enom_live_calls';
    private static function definition(Blueprint $t): void
    {$t->string('account',64);$t->string('day',10);$t->unsignedBigInteger('used')->default(0);$t->unsignedBigInteger('spend_micros')->default(0);$t->primary(['account','day']);}
    public static function install(): void
    {if(!Schema::hasTable(self::TABLE)){Schema::create(self::TABLE,self::definition(...));}self::verify();}
    public static function verify(): void {SchemaVerifier::verify(self::TABLE,self::definition(...));}
}
