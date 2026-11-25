<?php
// NameSilo TEST account enrolled with a reviewed key, Orders and transfers not yet chosen; MIT, Copyright (c) 2026 Autonomic Engineering.
// Setup only: namesilo-test-activation-test.php owns the assertions on this state.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/namesilo-enrollment-wire.php';
require __DIR__.'/staff-panel.php';
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{Installation,Runtime,StaffSettingsReview as Review,StaffSettingsWrites};
$GLOBALS['enrollmentCalls']=0;
config(['domains.namesilo_account.environment'=>'test',
    'cache.default'=>'database','session.driver'=>'database','queue.default'=>'database',
    'queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900]);
Illuminate\Support\Facades\Schema::create('cache_locks',static function($t):void {
    $t->string('key')->primary();$t->string('owner');$t->integer('expiration');
});
(new Installation)->install();Runtime::register();
$staff=staffUser(array_keys(Staff::permissions()));
$server=Server::create(['name'=>'Synthetic NS5 test activation','type'=>'server','extension'=>'Domains','enabled'=>true]);
$review=static fn(string $action)=>new Review($staff->id,$server->id,null,$action,
    Review::ticket($staff->id,$server->id,null,$action,Review::stamp($server->id,null)),'Review synthetic test activation');
$writes=app(StaffSettingsWrites::class);
$writes->run($review('configure'),['registrar'=>'namesilo','environment'=>Environment::Test->value,
    'credentials'=>['api_key'=>'synthetic-ote-key']]);
$account=Account::where('server_id',$server->id)->sole();
