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
    (new Installation)->install();
}elseif($phase==='admit'){
    // InnoDB's row lock wait counter, read before the barrier and after the send check, shows the parent whether any
    // racer waited on the account row; a round whose racers ran one after another proves nothing about the lock.
    $waits=static fn():int=>(int)DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_row_lock_waits'")->Value;$before=$waits();
    // Each worker reports ready here, then blocks until the parent, having seen every worker ready, closes the release
    // pipe for all of them at once; a late worker would admit alone and hide a missing lock.
    $ready=fopen('php://fd/'.(int)getenv('LIMIT_READY_FD'),'wb');$release=fopen('php://fd/'.(int)getenv('LIMIT_RELEASE_FD'),'rb');
    expect($ready!==false&&$release!==false,'Barrier pipes required');
    same(1,fwrite($ready,(string)getenv('LIMIT_WORKER')),'Worker reported ready');fclose($ready);
    same('',fread($release,1),'Worker released only by the closed barrier');fclose($release);
    // The refusal reason tells a counted refusal (over_limit) from a database error (unreadable).
    try{EnomTestSendLimit::admit(Environment::Test,getenv('LIMIT_UID')?:'Synthetic-UID','GetBalance');$outcome='admitted';}catch(EnomTestCallLimit $refusal){$outcome=$refusal->reason;}
    echo json_encode(['outcome'=>$outcome,'row_lock_waits'=>[$before,$waits()]])."\n";
}elseif($phase==='verify'){
    Tables::verify();(new Installation)->install();
    same(9,DB::table(Tables::SENDS)->count(),'Exactly the limit was recorded in every round');
    same(2,DB::table(Tables::ACCOUNTS)->count(),'Concurrent first use created one row per login');
    echo json_encode(['complete'=>true,'prefix'=>$prefix,'concurrent_first_use'=>true,'concurrent_existing_login'=>true,'concurrent_minute_limit'=>true,'real_provider_calls'=>0])."\n";
}else{throw new LogicException('Unknown fixture phase');}
$nativeSuccessful=true;
