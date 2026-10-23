<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** Retain deletion intent without changing native tables or relying on cascades. */
final class UserDeletionSchema
{
    public const TABLE='domains_user_deletions';
    public const MIGRATION='2026_10_22_000036_create_user_deletions';
    private static function definition(Blueprint $t, bool $legacyDates = false): void
    {$t->unsignedBigInteger('user_id')->primary();($legacyDates ? $t->timestamp('created_at') : $t->dateTime('created_at'));}
    public static function install(): void
    {if(!Schema::hasTable(self::TABLE)){Schema::create(self::TABLE,self::definition(...));}self::verify();}
    public static function verify(bool $legacyDates = false): void {SchemaVerifier::verify(self::TABLE,static fn (Blueprint $t) => self::definition($t, $legacyDates));}
}
