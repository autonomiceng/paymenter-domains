<?php
// Disposable socket-only native fixture. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
$nativeSuccessful=false;
register_shutdown_function(static function()use(&$nativeSuccessful):void{if(!$nativeSuccessful){exit(1);}});
$fixture=getenv('PAYMENTER_TEST_FIXTURE');
expect(is_string($fixture)&&realpath($fixture)===$fixture&&!file_exists($fixture.'/.env'),'Disposable pristine fixture required');
$loader=require $fixture.'/vendor/autoload.php';
$loader->addPsr4('App\\',$fixture.'/app/',true);
// Scoped engine tests install their own hooks after native boot, like native-app.php.
// Existing server rows must not also boot a second extension instance first.
$deferred=[];
if($deferDomainsBootstrap??false){
    foreach(spl_autoload_functions()as$callback){
        if($callback instanceof Closure&&(new ReflectionFunction($callback))->getFileName()===dirname(__DIR__).'/bootstrap.php'){
            spl_autoload_unregister($callback);$deferred[]=$callback;
        }
    }
}else{$loader->addPsr4('Paymenter\\Extensions\\Servers\\Domains\\',dirname(__DIR__,2).'/Domains/',true);}
$app=require $fixture.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach($deferred as$callback){spl_autoload_register($callback);}
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Domains\\',dirname(__DIR__,2).'/Domains/',true);
config(['hashing.bcrypt.rounds'=>4,'audit.enabled'=>false,'settings.mail_disabled'=>true,
    'settings.default_currency'=>'USD','settings.tax_enabled'=>false,'settings.theme'=>'default']);
same('mysql',config('database.default'),'Socket-only MariaDB connection');
expect(config('database.connections.mysql.unix_socket')!=='','Unix socket required');
Illuminate\Support\Facades\Http::preventStrayRequests();
if(getenv('CONVERSION_PHASE')==='seed'){
    Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    Illuminate\Support\Facades\Schema::create('cache',function($t){$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');});
    Illuminate\Support\Facades\Schema::create('cache_locks',function($t){$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
    app(Illuminate\Database\Migrations\Migrator::class)->run([getenv('CONVERSION_SOURCE').'/database/migrations'],['force'=>true]);
}
