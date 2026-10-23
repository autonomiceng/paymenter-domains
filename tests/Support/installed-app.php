<?php
// Native package fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Deliberately no tests/bootstrap.php: its source-tree autoloader would mask missing ZIP files.
function expect(bool $ok,string $message): void {if(!$ok){throw new RuntimeException($message);}}
function same(mixed $expected,mixed $actual,string $message): void {expect($expected===$actual,$message);}
$nativeSuccessful=false;
register_shutdown_function(static function()use(&$nativeSuccessful):void {if(!$nativeSuccessful){exit(1);}});
$fixture=(string)getenv('PAYMENTER_TEST_FIXTURE');
expect($fixture!==''&&realpath($fixture)===$fixture&&is_file($fixture.'/.domains-fixture.json')&&!file_exists($fixture.'/.env'),'Disposable pristine fixture required');
$marker=json_decode(file_get_contents($fixture.'/.domains-fixture.json'),true,8,JSON_THROW_ON_ERROR);
same('ee729f7d99ac18942625b79f3f93d8d8b21255e8',$marker['commit']??null,'Pinned Paymenter source');
same('fd6bdeb5557b3af0976d5688d92081abc6f7c3235806a6c4b77f4b8bca9186be',hash_file('sha256',$fixture.'/composer.lock'),'Pinned native dependency lock');
foreach(['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(str_repeat('t',32)),
    'APP_URL'=>'http://domains-tests.invalid','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>getenv('PACKAGE_DATABASE'),
    'CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync','SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','TELEMETRY_ENABLED'=>'false'] as $key=>$value){
    putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;
}
$loader=require $fixture.'/vendor/autoload.php';
$loader->addPsr4('App\\',$fixture.'/app/',true);
$loader->addPsr4('Paymenter\\Extensions\\',$fixture.'/extensions/',true);
$app=require $fixture.'/bootstrap/app.php';
if(in_array(getenv('PACKAGE_PHASE'),['enom-test-transfer','enom-test-transfer-restart','enom-test-management','install-enom-test-management','enom-test-management-restart','enom-test-registration','enom-test-renewal','enom-test-renewal-restart','enom-test-renewal-race-issue','enom-test-renewal-race-admit','enom-test-renewal-race-verify'],true)){
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        static fn()=>config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>in_array(getenv('PACKAGE_PHASE'),['enom-test-transfer','enom-test-management','install-enom-test-management','enom-test-registration','enom-test-renewal'],true),'domains.enom_writes.transfer'=>in_array(getenv('PACKAGE_PHASE'),['enom-test-transfer','enom-test-transfer-restart'],true),'domains.enom_writes.management'=>getenv('PACKAGE_PHASE')==='enom-test-management-restart','domains.enom_writes.renewal'=>str_starts_with((string)getenv('PACKAGE_PHASE'),'enom-test-renewal-')]));
}
if(in_array(getenv('PACKAGE_PHASE'), ['identity','catalogue','catalogue-retained'], true)){
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        static fn()=>config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>getenv('PACKAGE_PHASE')!=='identity']));
}
if(getenv('PACKAGE_PHASE')==='enom-live'){
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,static fn()=>config(['domains.enom_identity.environment'=>'live','app.url'=>'https://my.becotech.com']));
}
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['hashing.bcrypt.rounds'=>4,'audit.enabled'=>false,'settings.mail_disabled'=>true,
    'settings.default_currency'=>'USD','settings.tax_enabled'=>false,'settings.theme'=>'default']);
same('sqlite',config('database.default'),'No database sockets');
same(getenv('PACKAGE_DATABASE'),config('database.connections.sqlite.database'),'Scratch database only');
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
