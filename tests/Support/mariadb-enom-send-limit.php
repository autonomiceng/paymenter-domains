<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/mariadb-bootstrap.php';
require_once __DIR__.'/FakeRegistrar.php';
use Illuminate\Support\Facades\{Artisan,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestSendLimit,EnomTestSendLimitSchema as Tables,Installation,Runtime};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$phase=getenv('LIMIT_PHASE');$prefix=(string)getenv('SCHEMA_PREFIX');
if($phase==='seed'){
    Artisan::call('migrate',['--force'=>true]);
    if($prefix!==''){
        foreach(Schema::getTableListing(DB::connection()->getDatabaseName(),false) as $table){Schema::rename($table,$prefix.$table);}
    }
}
config(['database.connections.mysql.prefix'=>$prefix]);DB::purge('mysql');Schema::clearResolvedInstance('db.schema');
Runtime::register();
// Every worker sees the same instant, so only the row lock keeps the count exact.
app()->instance(Clock::class,new FakeClock(new DateTimeImmutable('2026-10-28T12:00:00Z')));
config(['domains.enom_test_sends_per_day'=>(int)(getenv('LIMIT_DAY')?:3),'domains.enom_test_sends_per_minute'=>(int)(getenv('LIMIT_MINUTE')?:100)]);
if($phase==='seed'){
    // A retained install from before the limiter: no limiter tables or record, and an old counter table with a row.
    (new Installation)->install();
    foreach(Tables::tables() as $table){Schema::drop($table);}
    DB::table('migrations')->where('migration',Tables::MIGRATION)->delete();
    Schema::create('domains_enom_test_budget',static function($t){$t->id();$t->unsignedInteger('used');});
    DB::table('domains_enom_test_budget')->insert(['used'=>7]);
    (new Installation)->install();
    foreach(Tables::RETIRED as $table){expect(!Schema::hasTable($table),'Install drops the retired table '.$table);}
}elseif($phase==='admit'){
    // All workers admit at one shared moment; a late one would admit alone and hide a missing lock.
    expect(microtime(true)<(float)getenv('LIMIT_START'),'Worker was ready before the shared start');
    while(microtime(true)<(float)getenv('LIMIT_START')){usleep(1000);}
    try{EnomTestSendLimit::admit(Environment::Test,getenv('LIMIT_UID')?:'Synthetic-UID','GetBalance');$outcome='admitted';}catch(EnomTestCallLimit){$outcome='refused';}
    echo json_encode(['outcome'=>$outcome])."\n";
}elseif($phase==='verify'){
    Tables::verify();(new Installation)->install();
    same(9,DB::table(Tables::SENDS)->count(),'Exactly the limit was recorded in every round');
    same(2,DB::table(Tables::ACCOUNTS)->count(),'Concurrent first use created one row per login');
    echo json_encode(['complete'=>true,'prefix'=>$prefix,'retired_tables_dropped'=>true,'concurrent_first_use'=>true,'concurrent_existing_login'=>true,'concurrent_minute_limit'=>true,'real_provider_calls'=>0])."\n";
}else{throw new LogicException('Unknown fixture phase');}
$nativeSuccessful=true;
