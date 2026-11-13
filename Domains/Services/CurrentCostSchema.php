<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CurrentCost,OfferState};
/** Never choose between conflicting observations. Retain both sources for a person. */
final class CurrentCostSchema
{
    public const MIGRATION='2026_11_11_000064_normalize_current_costs';
    public static function retained(Blueprint $t): void
    {
        if(Schema::hasColumn('domains_prices','cost_enabled')){$t->boolean('cost_enabled')->nullable();}
        if(Schema::hasColumn('domains_prices','cost_source_unit')){$t->string('cost_source_unit',32)->nullable();}
    }
    public static function install(): void
    {
        foreach(['cost_enabled','cost_source_unit'] as $column){
            if(!Schema::hasColumn('domains_prices',$column)){
                Schema::table('domains_prices',static function(Blueprint $t)use($column):void {
                    if($column==='cost_enabled'){$t->boolean($column)->nullable();}else{$t->string($column,32)->nullable();}
                });
            }
        }
        // A successful migration consumes legacy input only once. Later syncs own these columns.
        if(!DB::table('migrations')->where('migration',self::MIGRATION)->exists()){
            OfferState::query('domains_prices')->orderBy('id')->chunkById(100,static function($rows):void {
                foreach($rows as $p){DB::transaction(static function()use($p):void {
                    \App\Models\Server::whereKey($p->server_id)->lockForUpdate()->first();
                    $t=DB::table('domains_tlds')->where('server_id',$p->server_id)->where('tld',$p->tld)->lockForUpdate()->first();
                    $p=OfferState::query('domains_prices')->where('id',$p->id)->lockForUpdate()->sole();
                    if($p->cost_source_unit!==null){return;} // Already classified by an interrupted run.
                    if($p->catalogue_observation===null){DB::table('domains_prices')->where('id',$p->id)->update(['cost_source_unit'=>'unknown']);return;}
                    try{
                        $cost=OfferState::cost(OfferState::decode($p->catalogue_observation));
                        $values=CurrentCost::values($cost);
                        if($cost->action!==$p->action||$cost->term!==(int)$p->term||$cost->currency!==$p->currency||$p->unit!=='term_total'
                            ||($p->wholesale===null)!==($cost->total===null)||OfferState::money($p->wholesale,4)!==$cost->total||$p->source!==$cost->source
                            ||new \DateTimeImmutable($p->observed_at,new \DateTimeZone('UTC'))!=$cost->observedAt
                            ||!$p->fresh_until||new \DateTimeImmutable($p->fresh_until,new \DateTimeZone('UTC'))!=$cost->freshUntil){throw new \DomainException;}
                        DB::table('domains_prices')->where('id',$p->id)->update($values);
                    }catch(\Throwable $error){
                        if($error instanceof \Illuminate\Database\QueryException){throw $error;}
                        if(!$t){throw new \DomainException('Current cost has no retained offer.');}
                        $issues=OfferState::decode($t->catalogue_issues);
                        $issues['current_cost_review'][(string)$p->id]=['observation'=>$p->catalogue_observation,'columns'=>array_intersect_key((array)$p,array_flip(['wholesale','source','unit','observed_at','fresh_until']))];
                        DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_issues'=>OfferState::json($issues)]);
                        DB::table('domains_prices')->where('id',$p->id)->update(['cost_enabled'=>null,'cost_source_unit'=>'unknown']);
                    }
                });}
            });
        }
        self::verify();
    }
    public static function verify(): void
    {
        foreach(['cost_enabled','cost_source_unit'] as $column){if(!Schema::hasColumn('domains_prices',$column)){throw new \RuntimeException('Missing current cost schema.');}}
        FoundationSchema::verify();
    }
}
