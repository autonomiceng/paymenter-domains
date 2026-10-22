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
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Domains\\',dirname(__DIR__,2).'/Domains/',true);
$app=require $fixture.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
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
