<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Delivery retries never change the retained balance or provider read schedule. */
final class BalanceAlertSchema
{
    public const TABLE='domains_balance_alerts';
    public const MIGRATION='2026_10_26_000041_create_balance_alerts';
    private static function definition(Blueprint $t): void
    {
        $t->id();$t->unsignedBigInteger('server_id');$t->string('account_fingerprint',64);
        $t->string('pending_day',10)->nullable();$t->unsignedBigInteger('retry_at')->default(0);
        $t->unsignedSmallInteger('attempts')->default(0);$t->string('claim',32)->nullable();
        $t->unique(['server_id','account_fingerprint']);
    }
    public static function install(): void
    {if(!Schema::hasTable(self::TABLE)){Schema::create(self::TABLE,self::definition(...));}self::verify();}
    public static function verify(): void {SchemaVerifier::verify(self::TABLE,self::definition(...));}
}
