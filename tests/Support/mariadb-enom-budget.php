<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/mariadb-bootstrap.php';
use Illuminate\Support\Facades\{Artisan,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,Installation,EnomTestRunBudget as Budget,EnomTestCallLimit,EnomTestCallSchema,EnomTestRunBudgetSchema as Tables};
$phase=getenv('BUDGET_PHASE');$root=getenv('BUDGET_BARRIERS');$prefix=(string)getenv('SCHEMA_PREFIX');
if($phase==='seed'){
    Artisan::call('migrate',['--force'=>true]);
    if($prefix!==''){
        foreach(Schema::getTableListing(DB::connection()->getDatabaseName(),false) as $table){Schema::rename($table,$prefix.$table);}
    }
}
config(['database.connections.mysql.prefix'=>$prefix]);DB::purge('mysql');Schema::clearResolvedInstance('db.schema');
Runtime::register();
if($phase==='seed'){
    $seedNow=(int)(microtime(true)*1000000)-1000000;
    $seedDay=gmdate('Y-m-d',intdiv($seedNow,1000000));
    // Keep both synthetic attempts inside the native row's UTC day, even at its first microsecond.
    $seedEarlier=max($seedNow-1,intdiv($seedNow,86400000000)*86400000000);
    (new Installation)->install();
    App\Models\Server::create(['id'=>1,'type'=>'server','name'=>'Synthetic budget','extension'=>'Domains','enabled'=>true]);
    Account::withVerifiedBindingWrite(static fn()=>Account::create(['server_id'=>1,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic','processing_enabled'=>false]));
    DB::table(EnomTestCallSchema::TABLE)->insert(['day'=>$seedDay,'used'=>3]);
}
$account=Account::where('server_id',1)->sole();
$policy=['version'=>2,'run_id'=>'mariadb_retained','generation'=>'mariadb_retained','cap'=>30,'rolling_cap'=>30,
    'ledger_dev'=>1,'ledger_ino'=>2,'ledger_uid'=>1000,'ledger_gid'=>1000,'account_fingerprint'=>$account->account_fingerprint,'configuration_intent'=>'synthetic'];
ksort($policy);$policy['policy_hash']=hash('sha256',json_encode($policy,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
config(['domains.enom_test_run_policy'=>$policy]);
if($phase==='seed'){
    file_put_contents($root.'/history',json_encode(['native'=>[['day'=>$seedDay,'used'=>3]],'attempts'=>[$seedNow,$seedEarlier],'undated_debt'=>0,'source_hash'=>str_repeat('a',64)]));
}elseif($phase==='migrate'||$phase==='recover'){
    $history=json_decode(file_get_contents($root.'/history'),true,32,JSON_THROW_ON_ERROR);
    Budget::migrateLegacy($policy,$history,0);
    if($phase==='migrate'){
        file_put_contents($root.'/native-committed','ready');echo "native_committed\n";fflush(STDOUT);fgets(STDIN);
    }
    Budget::allocate($policy,20,'retained_main');Budget::activate($policy);
}elseif($phase==='allocate'){
    // All contenders use different IDs: exactly one additional seven-unit slice fits.
    try{Budget::allocate($policy,7,'rival_'.getenv('BUDGET_WORKER'));$outcome='allocated';}catch(EnomTestCallLimit){$outcome='refused';}
    echo json_encode(['outcome'=>$outcome])."\n";
}elseif($phase==='reserve'){
    $permit=Budget::reserve($account->account_fingerprint);file_put_contents($root.'/permit',$permit);
}elseif($phase==='consume'){
    try{Budget::consume(trim(file_get_contents($root.'/permit')),$account->account_fingerprint);$outcome='consumed';}catch(EnomTestCallLimit){$outcome='refused';}
    echo json_encode(['outcome'=>$outcome])."\n";
}elseif($phase==='verify'){
    $s=Budget::status($policy);
    same(4,$s['used'],'Three dated legacy native units plus one physical attempt');
    same(26,$s['remaining'],'Exactly seven admitted credits, one consumed');
    same(27,(int)DB::table(Tables::ALLOCATIONS)->sum('credits'),'Outstanding credits conserved');
    same(3,(int)DB::table(Tables::PERMITS)->whereNotNull('attempt_time')->count(),'Two exact imported attempts and one new attempt');
    same(3,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Legacy native history untouched');
    foreach(['invoices','invoice_transactions','credits'] as $table){same(0,DB::table($table)->count(),'No money rows');}
    echo json_encode(['complete'=>true,'prefix'=>$prefix,'retained_v1'=>true,'competing_admission'=>true,'exactly_once_consume'=>true,'outstanding_conserved'=>true,'crashed_native_publication_recovered'=>true,'real_provider_calls'=>0])."\n";
}
$nativeSuccessful=true;
