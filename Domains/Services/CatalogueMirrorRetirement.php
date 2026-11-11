<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,UpgradeGate};
/** The recorded begin migration owns resumable DDL. Current intent and costs already own every reader. */
final class CatalogueMirrorRetirement
{
    public const BEGIN='2026_11_11_000065_begin_catalogue_mirror_retirement';
    public const FINISH='2026_11_11_000066_retire_catalogue_mirrors';
    public const COLUMNS=['domains_tlds'=>['enabled','register_enabled','renew_enabled','transfer_enabled','min_years','max_years'],
        'domains_prices'=>['catalogue_observation']];
    private static function recorded(string $name): bool
    {return Schema::hasTable('migrations')&&DB::table('migrations')->where('migration',$name)->exists();}
    public static function omitted(string $table,string $column): bool
    {
        return in_array($column,self::COLUMNS[$table]??[],true)
            &&(self::recorded(self::FINISH)||self::recorded(self::BEGIN)&&!Schema::hasColumn($table,$column));
    }
    public static function begin(): void
    {
        if(UpgradeGate::initializationState()['state']!=='active'&&!FreshInstallation::recovering()){throw new \DomainException(OfferUpgrade::MESSAGE);}
        CurrentCostSchema::verify();
        if(DB::table('domains_prices')->whereNull('cost_source_unit')->exists()){throw new \DomainException('Current cost conversion is incomplete.');}
        // Unconverted intent is not inferred from old flags. Retain the old values explicitly for a person.
        DB::table('domains_tlds')->where('catalogue_converted',false)->orderBy('id')->chunkById(100,static function($rows):void {
            foreach($rows as $row){
                $issues=OfferState::decode($row->catalogue_issues);
                $issues['legacy_intent_review']=array_intersect_key((array)$row,array_flip(self::COLUMNS['domains_tlds']));
                DB::table('domains_tlds')->where('id',$row->id)->update(['catalogue_issues'=>OfferState::json($issues)]);
            }
        });
    }
    public static function install(): void
    {
        if(!self::recorded(self::BEGIN)){throw new \LogicException('Recorded catalogue retirement intent required.');}
        CurrentCostSchema::verify();
        foreach(self::COLUMNS as $table=>$columns){foreach($columns as $column){
            if(Schema::hasColumn($table,$column)){Schema::table($table,static fn(Blueprint $t)=>$t->dropColumn($column));}
        }}
        self::verify();
    }
    public static function verify(): void
    {
        foreach(self::COLUMNS as $table=>$columns){foreach($columns as $column){
            if(Schema::hasColumn($table,$column)){throw new \RuntimeException('Obsolete catalogue mirror remains: '.$column);}
        }}
        CurrentCostSchema::verify();
    }
}
