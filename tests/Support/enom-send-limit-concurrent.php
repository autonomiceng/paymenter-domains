<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/FakeRegistrar.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestSendLimit,EnomTestSendLimitSchema};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$path=getenv('SEND_LIMIT_DB');
expect(is_string($path)&&str_starts_with($path,dirname(__DIR__,2).'/.cache/')&&is_file($path),'Private synthetic SQLite database');
// Real independent connections are needed to exercise the lock, but full app boot/migrations
// in each of six workers only create unrelated in-memory databases that are then discarded.
// Use the pinned native Setting model and Laravel database services without booting the UI/jobs.
$fixture=(string)getenv('PAYMENTER_TEST_FIXTURE');
expect($fixture!==''&&realpath($fixture)===$fixture&&!file_exists($fixture.'/.env')
    &&!file_exists($fixture.'/bootstrap/cache/config.php'),'Runner-created clean fixture required');
$marker=json_decode(file_get_contents($fixture.'/.domains-fixture.json'),true,8,JSON_THROW_ON_ERROR);
same('ee729f7d99ac18942625b79f3f93d8d8b21255e8',$marker['commit']??null,'Pinned Paymenter source');
same('fd6bdeb5557b3af0976d5688d92081abc6f7c3235806a6c4b77f4b8bca9186be',
    hash_file('sha256',$fixture.'/composer.lock'),'Pinned native dependency lock');
$loader=require $fixture.'/vendor/autoload.php';
$loader->addPsr4('App\\',$fixture.'/app/',true);
$app=new Illuminate\Foundation\Application($fixture);
$app->instance('config',new Illuminate\Config\Repository([
    'audit'=>['enabled'=>false],
    'database'=>['default'=>'sqlite','connections'=>['sqlite'=>[
        'driver'=>'sqlite','database'=>$path,'prefix'=>'','busy_timeout'=>15000,
    ]]],
]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(Illuminate\Events\EventServiceProvider::class);
$app->register(Illuminate\Database\DatabaseServiceProvider::class);
$app->boot();
// Every worker sees the same instant, so only the lock keeps the count exact.
app()->instance(Clock::class,new FakeClock(new DateTimeImmutable('2026-10-28T12:00:00Z')));
config(['domains.enom_test_sends_per_day'=>(int)(getenv('LIMIT_DAY')?:3),'domains.enom_test_sends_per_minute'=>(int)(getenv('LIMIT_MINUTE')?:100)]);
$mode=$argv[1];
if($mode==='setup'){
    // The login limit reads native settings; a missing table correctly fails closed.
    (require $fixture.'/database/migrations/2024_02_15_122225_create_settings_table.php')->up();
    EnomTestSendLimitSchema::install();echo "OK\n";
}
elseif($mode==='admit'){
    // The parent releases all six ready connections together, without a fixed wall-clock delay.
    echo "READY\n";fflush(STDOUT);
    same("GO\n",fgets(STDIN),'Every contender waits for the shared start');
    try{EnomTestSendLimit::admit(Environment::Test,getenv('LIMIT_UID')?:'Synthetic-UID','GetBalance');echo "ADMITTED\n";}catch(EnomTestCallLimit){echo "REFUSED\n";}
}elseif($mode==='count'){echo 'SENDS='.DB::table(EnomTestSendLimitSchema::SENDS)->count().' ACCOUNTS='.DB::table(EnomTestSendLimitSchema::ACCOUNTS)->count()."\n";}
else{throw new LogicException('Unknown fixture mode');}
