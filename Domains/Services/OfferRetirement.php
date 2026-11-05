<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;

/** Forward-only removal of obsolete current state, after checked bridge activation.
 * The separate begin migration is the durable DDL intent, including on MariaDB.
 */
final class OfferRetirement
{
    public const BEGIN = '2026_11_04_000052_begin_offer_retirement';
    public const FINISH = '2026_11_04_000053_retire_offer_stores';
    public const TABLES = ['domains_sell_rows','domains_sell_fills'];
    public const COLUMNS = [
        'domains_tlds'=>['cost_register_terms','sale_holds','renew_price','cost_renew','cost_transfer','costs_synced_at'],
        'domains_prices'=>['eligibility','hold_reason'],
        'domains_observation_queues'=>['offer_conversion_cursor','offer_conversion_source','offer_conversion_complete'],
    ];
    private static function recorded(string $migration): bool
    { return Schema::hasTable('migrations') && DB::table('migrations')->where('migration',$migration)->exists(); }
    public static function started(): bool { return self::recorded(self::BEGIN); }
    public static function finished(): bool { return self::recorded(self::FINISH); }

    /** During an owned interrupted drop, tolerate only the missing retired columns.
     * After completion, reintroduced columns must fail exact schema verification.
     */
    public static function omitted(string $table,string $column): bool
    {
        return in_array($column,self::COLUMNS[$table]??[],true)
            && (self::finished() || self::started() && !Schema::hasColumn($table,$column));
    }
    public static function begin(): void
    {
        if (UpgradeGate::initializationState()['state'] !== 'active' && !FreshInstallation::recovering()) {
            throw new \DomainException(OfferUpgrade::MESSAGE);
        }
        FoundationSchema::verify(); OfferSchema::verify(); self::verifyLegacyStores();
    }
    /** This declaration is used only to reject drift before deleting bridge stores. */
    public static function verifyLegacyStores(): void
    {
        $definitions = [
            'domains_sell_rows'=>static function(Blueprint $t):void {
                $t->id();$t->unsignedBigInteger('server_id');$t->string('account_fingerprint',64);$t->string('tld',255);
                $t->string('choice',8)->nullable();$t->unsignedTinyInteger('longest')->nullable();$t->boolean('transfer')->nullable();
                foreach(['register_cost','renew_cost','transfer_cost','high_cost'] as $cost){$t->decimal($cost,14,4)->nullable();}
                $t->json('prices')->nullable();$t->unsignedBigInteger('read_at')->nullable();
                $t->unique(['server_id','account_fingerprint','tld']);$t->index(['server_id','account_fingerprint','high_cost']);
            },
            'domains_sell_fills'=>static function(Blueprint $t):void {
                $t->unsignedBigInteger('server_id');$t->string('account_fingerprint',64);$t->unsignedBigInteger('filled_at')->nullable();
                $t->unsignedBigInteger('tried_at')->nullable();$t->unsignedBigInteger('failed_at')->nullable();$t->string('failure',64)->nullable();$t->unsignedBigInteger('run')->default(0);
                $t->primary(['server_id','account_fingerprint']);
            },
        ];
        foreach($definitions as $table=>$definition){
            if(Schema::hasTable($table)){SchemaVerifier::verify($table,$definition);}
            elseif(!self::started() && !FreshInstallation::recovering()){throw new \RuntimeException('Missing retained bridge store: '.$table);}
        }
    }
    public static function install(): void
    {
        if(!self::started()){throw new \LogicException('Recorded offer retirement intent required.');}
        if(UpgradeGate::initializationState()['state']!=='active' && !FreshInstallation::recovering()){throw new \DomainException(OfferUpgrade::MESSAGE);}
        // Every statement is independently resumable; no native/history table is touched.
        foreach(self::TABLES as $table){Schema::dropIfExists($table);}
        foreach(self::COLUMNS as $table=>$columns){
            foreach($columns as $column){if(Schema::hasColumn($table,$column)){Schema::table($table,static fn(Blueprint $t)=>$t->dropColumn($column));}}
        }
        // Current policy settings only. Immutable staff audit rows are deliberately retained.
        DB::table('settings')->whereIn('key',['domains_catalogue_acceptance','domains_sell_rule'])->delete();
        self::verify();
    }
    public static function verify(): void
    {
        foreach(self::TABLES as $table){if(Schema::hasTable($table)){throw new \RuntimeException('Obsolete offer store remains: '.$table);}}
        foreach(self::COLUMNS as $table=>$columns){foreach($columns as $column){if(Schema::hasColumn($table,$column)){throw new \RuntimeException('Obsolete offer mirror remains: '.$column);}}}
    }
}
