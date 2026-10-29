<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** One lock row per Enom TEST login and one permanent row per send attempt. */
final class EnomTestSendLimitSchema
{
    public const ACCOUNTS='domains_enom_test_send_accounts';
    public const SENDS='domains_enom_test_sends';
    public const MIGRATION='2026_10_28_000044_create_enom_test_send_limit';
    /** The old daily counter and run budget tables, dropped by MIGRATION. */
    public const RETIRED=['domains_enom_test_calls','domains_enom_test_budget','domains_enom_test_runs',
        'domains_enom_test_permits','domains_enom_test_allocations','domains_enom_test_legacy_budget'];
    public static function tables(): array { return [self::ACCOUNTS,self::SENDS]; }
    private static function accounts(Blueprint $t): void { $t->string('account',64)->primary(); }
    private static function sends(Blueprint $t): void
    { $t->id();$t->string('account',64);$t->string('command',32);$t->unsignedBigInteger('sent_at');$t->index(['account','sent_at']); }
    public static function install(): void
    {
        foreach(self::RETIRED as $table){Schema::dropIfExists($table);}
        if(!array_filter(array_map(Schema::hasTable(...),self::tables()))){
            Schema::create(self::ACCOUNTS,self::accounts(...));Schema::create(self::SENDS,self::sends(...));
        }
        self::verify();
    }
    public static function verify(): void
    { SchemaVerifier::verify(self::ACCOUNTS,self::accounts(...));SchemaVerifier::verify(self::SENDS,self::sends(...)); }
}
