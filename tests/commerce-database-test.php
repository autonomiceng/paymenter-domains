<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/core-database.php';
require __DIR__.'/Support/commerce-database.php';
require __DIR__.'/Support/FakeRegistrar.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{Installation,Runtime};

$core=tempnam((string)getenv('TMPDIR'),'core-');$installed=tempnam((string)getenv('TMPDIR'),'installed-');
$booted=static fn()=>array_keys((new ReflectionProperty(Illuminate\Database\Eloquent\Model::class,'booted'))->getValue());
exportCoreDatabase(DB::connection()->getPdo(),$core,$booted());
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,
    new Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable('2026-10-18T12:00:00Z')));
Runtime::register();(new Installation)->install();
$state=static fn()=>[DB::table('migrations')->orderBy('id')->get()->toJson(),
    DB::table('settings')->orderBy('id')->get()->toJson(),DB::table('notification_templates')->orderBy('id')->get()->toJson()];
$expected=$state();exportCoreDatabase(DB::connection()->getPdo(),$installed,$booted());
putenv('PAYMENTER_TEST_COMMERCE_DATABASE='.$installed);putenv('PAYMENTER_TEST_COMMERCE_SHA256='.hash_file('sha256',$installed));
try {
    expect(!restoreCommerceDatabase(),'An already installed database is never replaced');
    $reset=static function()use($core):void {
        $pdo=new PDO('sqlite::memory:');$pdo->exec(file_get_contents($core));DB::connection()->setPdo($pdo);
        $GLOBALS['nativeFixtureDatabase']=['pdo'=>$pdo,'changes'=>(int)$pdo->query('SELECT total_changes()')->fetchColumn(),
            'schema'=>(int)$pdo->query('PRAGMA schema_version')->fetchColumn()];
    };
    $reset();expect(restoreCommerceDatabase(),'Untouched native fixture restores the verified installation');
    same($expected,$state(),'Restore retains installation history, encrypted settings and notification templates');
    (new Installation)->verify();same(0,DB::table('domains_operations')->count(),'Restored installation has no customer operation');
    expect(!restoreCommerceDatabase(),'A restored fixture cannot be reset again');
    $reset();App\Models\User::create(['first_name'=>'Existing','last_name'=>'Fixture','email'=>'existing@example.test','password'=>'synthetic']);
    expect(!restoreCommerceDatabase(),'Existing customer data prevents fixture replacement');
    same('existing@example.test',App\Models\User::sole()->email,'Refusal preserves the existing customer');
    $reset();DB::statement('CREATE TABLE fixture_sentinel (value TEXT)');
    expect(!restoreCommerceDatabase(),'Schema changes prevent fixture replacement');
    $reset();putenv('PAYMENTER_TEST_COMMERCE_SHA256='.str_repeat('0',64));
    try { restoreCommerceDatabase();throw new LogicException('Changed snapshot accepted'); }
    catch(RuntimeException $error){same('Installed fixture snapshot retains admitted bytes',$error->getMessage(),'Wrong snapshot hash refuses before database changes');}
    same(0,DB::table('migrations')->where('migration','like','%domains%')->count(),'Hash refusal preserves the pristine native database');
} finally {unlink($core);unlink($installed);}
$nativeSuccessful=true;
