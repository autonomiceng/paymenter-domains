<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/** One row per TLD and account: what staff chose and the last prices read. One state row per account for the fill. */
final class SellTableSchema
{
    public const ROWS='domains_sell_rows';
    public const FILLS='domains_sell_fills';
    public const MIGRATION='2026_10_30_000045_create_sell_table';
    public static function tables(): array { return [self::ROWS,self::FILLS]; }
    private static function rows(Blueprint $t): void
    {
        $t->id();$t->unsignedBigInteger('server_id');$t->string('account_fingerprint',64);$t->string('tld',255);
        $t->string('choice',8)->nullable();$t->unsignedTinyInteger('longest')->nullable();$t->boolean('transfer')->nullable();
        foreach(['register_cost','renew_cost','transfer_cost','high_cost'] as $cost){$t->decimal($cost,14,4)->nullable();}
        $t->json('prices')->nullable();$t->unsignedBigInteger('read_at')->nullable();
        $t->unique(['server_id','account_fingerprint','tld']);
        $t->index(['server_id','account_fingerprint','high_cost']);
    }
    private static function fills(Blueprint $t): void
    {
        $t->unsignedBigInteger('server_id');$t->string('account_fingerprint',64);$t->unsignedBigInteger('filled_at')->nullable();
        $t->unsignedBigInteger('tried_at')->nullable();$t->unsignedBigInteger('failed_at')->nullable();$t->string('failure',64)->nullable();$t->unsignedBigInteger('run')->default(0);
        $t->primary(['server_id','account_fingerprint']);
    }
    public static function install(): void
    {
        if(!array_filter(array_map(Schema::hasTable(...),self::tables()))){
            Schema::create(self::ROWS,self::rows(...));Schema::create(self::FILLS,self::fills(...));
        }
        self::verify();
    }
    public static function verify(): void
    { SchemaVerifier::verify(self::ROWS,self::rows(...));SchemaVerifier::verify(self::FILLS,self::fills(...)); }
}
