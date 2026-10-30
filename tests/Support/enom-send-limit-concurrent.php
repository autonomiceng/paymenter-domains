<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require_once __DIR__.'/FakeRegistrar.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestSendLimit,EnomTestSendLimitSchema};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$path=getenv('SEND_LIMIT_DB');
expect(is_string($path)&&str_starts_with($path,dirname(__DIR__,2).'/.cache/')&&is_file($path),'Private synthetic SQLite database');
DB::disconnect();config(['database.connections.sqlite.database'=>$path,'database.connections.sqlite.busy_timeout'=>15000]);DB::purge();
// Every worker sees the same instant, so only the lock keeps the count exact.
app()->instance(Clock::class,new FakeClock(new DateTimeImmutable('2026-10-28T12:00:00Z')));
config(['domains.enom_test_sends_per_day'=>(int)(getenv('LIMIT_DAY')?:3),'domains.enom_test_sends_per_minute'=>(int)(getenv('LIMIT_MINUTE')?:100)]);
$mode=$argv[1];
if($mode==='setup'){EnomTestSendLimitSchema::install();echo "OK\n";}
elseif($mode==='admit'){
    // All workers admit at one shared moment; a late one would admit alone and hide a missing lock.
    expect(microtime(true)<(float)getenv('LIMIT_START'),'Worker was ready before the shared start');
    while(microtime(true)<(float)getenv('LIMIT_START')){usleep(1000);}
    try{EnomTestSendLimit::admit(Environment::Test,getenv('LIMIT_UID')?:'Synthetic-UID','GetBalance');echo "ADMITTED\n";}catch(EnomTestCallLimit){echo "REFUSED\n";}
}elseif($mode==='count'){echo 'SENDS='.DB::table(EnomTestSendLimitSchema::SENDS)->count().' ACCOUNTS='.DB::table(EnomTestSendLimitSchema::ACCOUNTS)->count()."\n";}
else{throw new LogicException('Unknown fixture mode');}
$nativeSuccessful=true;
