<?php
// Offline NS5 dependency witness; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/namesilo-enrollment-wire.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist,Staff};
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,Installation,Runtime,StaffSettingsReview as Review,StaffSettingsWrites};
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
same('test',$account->environment,'Witness uses only a test account');
same('matched',app(AccountBindings::class)->configurationStatus($server->id),'Saved test key is current');
same(1,$GLOBALS['enrollmentCalls'],'Existing socket-free fake authenticates the test key once');
$refusal=null;
try{$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true]);}
catch(DomainException $error){$refusal=$error->getMessage();}
same(null,$refusal,'Staff can turn on the connected NameSilo test account');
expect($account->fresh()->processing_enabled,'Reviewed Orders enables the test account');
same('OK',AccountChecklist::runtime($account->fresh())['value'],'Checklist accepts the connected test runtime');
same(1,$GLOBALS['enrollmentCalls'],'Local activation makes no provider request');
$binding=AccountBindings::binding($account->fresh());
$writes->run($review('test_transfer'),['accept'=>false]);
$writes->run($review('processing'),['enabled'=>false,'confirmed'=>true]);
$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true]);
expect(!Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::accepted($binding),'Resuming TEST Orders preserves deliberate transfer Off');
$writes->run($review('test_transfer'),['accept'=>true,'verified'=>true]);
expect(Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::accepted($binding),'Reviewed TEST action activates transfers');
same(1,$GLOBALS['enrollmentCalls'],'Feature activation makes no provider request');
$nativeSuccessful=true;
