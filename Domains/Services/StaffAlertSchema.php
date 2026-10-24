<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Retained recipient receipts survive native inbox deletion and retry leases. */
final class StaffAlertSchema
{
    public const TABLE='domains_staff_alert_receipts';
    public const MIGRATION='2026_10_24_000039_create_staff_alert_receipts';
    private static function definition(Blueprint $t): void
    {$t->string('delivery_key',64)->primary();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('notification_id')->nullable();}
    public static function install(): void
    {if(!Schema::hasTable(self::TABLE)){Schema::create(self::TABLE,self::definition(...));}self::verify();}
    public static function verify(): void {SchemaVerifier::verify(self::TABLE,self::definition(...));}
}
