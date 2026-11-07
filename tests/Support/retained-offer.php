<?php
// Synthetic bridge database captured after checked activation on 891124421645.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,UpgradeGate};

/** Native-only fixture: replay persisted bytes, never load a retired converter into the current runtime. */
function retainedOfferDatabase(): array
{
    same('sqlite',DB::connection()->getDriverName(),'Bridge fixture is SQLite only');
    $database=config('database.connections.sqlite.database');
    if($database!==':memory:'){
        $fixture=(string)getenv('PAYMENTER_TEST_FIXTURE');$expected=(string)getenv('PACKAGE_DATABASE');
        expect(getenv('PACKAGE_PHASE')==='bridge-seed'&&is_file($fixture.'/.domains-fixture.json')
            &&$expected!==''&&$database===$expected&&is_file($expected)
            &&realpath(dirname($expected))===realpath(dirname($fixture)),'Bridge replay owns only the runner-created installed scratch database');
    }
    $saved=json_decode(file_get_contents(__DIR__.'/../fixtures/retained-offer-bridge.json'),true,512,JSON_THROW_ON_ERROR);
    same('89112442164558c4ab61ff32f210d1b102605112',$saved['source'],'Exact source bridge is pinned');
    DB::statement('PRAGMA foreign_keys = OFF');
    foreach(Schema::getTableListing(null,false) as $table){if(!str_starts_with($table,'sqlite_')){Schema::drop($table);}}
    foreach($saved['objects'] as $object){if($object['type']==='table'){DB::statement($object['sql']);}}
    foreach($saved['tables'] as $table=>$rows){foreach(array_chunk($rows,50) as $page){DB::table($table)->insert($page);}}
    foreach($saved['objects'] as $object){if($object['type']!=='table'){DB::statement($object['sql']);}}
    DB::statement('PRAGMA foreign_keys = ON');CallerRouting::reset();
    same($saved['receipt'],UpgradeGate::routeState(),'Replayed database has the actual checked active receipt');
    return $saved;
}

/** Rows that neither catalogue upgrades nor schema cleanup may alter. A retail upgrade republishes native plan
 * amounts, so its callers pass $nativeAmounts=false and check them with retainedNativeRetailMismatches(). */
function retainedOfferObligations(bool $nativeAmounts=true): string
{
    $rows=[];
    foreach(['domains_domains','domains_operations','services','orders','invoices','invoice_items','invoice_transactions',
        'credits','products','plans','prices','users','roles'] as $table){
        $rows[$table]=DB::table($table)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all();
    }
    if(!$nativeAmounts){$rows['prices']=array_map(static function(array $row):array{unset($row['price']);return $row;},$rows['prices']);}
    $rows['accounts']=DB::table('domains_accounts')->orderBy('id')->get(['id','server_id','registrar_key','account_fingerprint','stable_identity'])->all();
    $rows['offers']=DB::table('domains_tlds')->orderBy('id')->get(['id','server_id','account_fingerprint','tld','catalogue_intent','catalogue_retained_annual_retail'])->all();
    $rows['sellers']=DB::table('domains_sales_policies')->orderBy('id')->get()->all();
    return serialize($rows);
}

/** Native plan prices that differ from the published Domains retail of their TLD, action and term. */
function retainedNativeRetailMismatches(): array
{
    $setting=static fn(int $product,string $key)=>DB::table('settings')->where('settingable_type',App\Models\Product::class)
        ->where('settingable_id',$product)->where('key',$key)->value('value');
    $wrong=[];
    foreach(DB::table('prices')->join('plans','plans.id','=','prices.plan_id')->orderBy('prices.id')
        ->get(['prices.id','prices.price','plans.priceable_id','plans.billing_period','plans.billing_unit']) as $row){
        same('year',$row->billing_unit,'Domain plans bill in years');
        $tld=DB::table('domains_tlds')->find((int)$setting((int)$row->priceable_id,'domains_tld_id'));
        $retail=DB::table('domains_prices')->where('server_id',$tld->server_id)->where('tld',$tld->tld)
            ->where('action',$setting((int)$row->priceable_id,'domains_action'))->where('term',$row->billing_period)->value('retail');
        if($retail===null||round((float)$retail,2)!==round((float)$row->price,2)){$wrong[$row->id]=[$row->price,$retail];}
    }
    return $wrong;
}

/** Test-only exact database snapshot, independent of retired upgrade comparison policy. */
function retainedOfferSnapshot(): string
{
    $tables=Illuminate\Support\Facades\Schema::getTableListing();sort($tables);$rows=[];
    foreach($tables as $table){
        $values=Illuminate\Support\Facades\DB::table($table)->get()->map(static fn($row)=>(array)$row)->all();
        usort($values,static fn($a,$b)=>json_encode($a)<=>json_encode($b));$rows[$table]=$values;
    }
    return serialize($rows);
}
