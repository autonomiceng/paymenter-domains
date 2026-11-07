<?php
// Native setup witness: genuine local TEST enrollment, installed runtime and the account's Orders row.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/namesilo-enrollment-wire.php';
require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/renewal-initialized.php';
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,Runtime,StaffSettingsReview as Review,StaffSettingsWrites};
$GLOBALS['enrollmentCalls']=0;
config(['domains.namesilo_account.environment'=>'test','cache.default'=>'database','session.driver'=>'database','queue.default'=>'database',
    'queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900]);
Illuminate\Support\Facades\Schema::create('cache_locks',static function($t):void{$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
initializedRenewalFixture();Runtime::register();
$actor=staffUser(array_keys(Staff::permissions()));Illuminate\Support\Facades\Auth::setUser($actor);
$server=Server::create(['name'=>'Demo NameSilo OTE','type'=>'server','extension'=>'Domains','enabled'=>true]);
$review=static fn(string $action)=>new Review($actor->id,$server->id,null,$action,Review::ticket($actor->id,$server->id,null,$action,Review::stamp($server->id,null)),'Review TEST Orders setup');
$writes=app(StaffSettingsWrites::class);
$writes->run($review('configure'),['registrar'=>'namesilo','environment'=>'test','credentials'=>['api_key'=>'synthetic-ote-key']]);
$account=Account::where('server_id',$server->id)->sole();
$orders=static fn()=>(new ReflectionMethod(AccountChecklist::class,'orders'))->invoke(null,$account->fresh());
same('Ready. Turn on Orders.',$orders()['cause'],'Connected OTE with shared workers names the action, without a vague setup step');
config(['queue.connections.database.retry_after'=>899]);
same('Set the queue retry time to at least 900 seconds, then restart the queue worker.',$orders()['cause'],'Orders names the actual queue blocker and fix');
try{$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true]);expect(false,'Short retry time must refuse Orders');}catch(DomainException $error){
    same($orders()['cause'],Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::refusal($error,'processing',[],$server->id),'The refusal toast names the same real blocker');
}
same(false,$account->fresh()->processing_enabled,'Refused Orders stays off');
config(['queue.connections.database.retry_after'=>900]);
$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true]);
same(true,$account->fresh()->processing_enabled,'Orders can turn on without TLD prices');
same(1,$GLOBALS['enrollmentCalls'],'Only synthetic enrollment authenticated; readiness and Orders send nothing');
echo "Registrar Orders readiness passed.\n";$nativeSuccessful=true;
