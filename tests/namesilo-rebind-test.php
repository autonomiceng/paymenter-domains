<?php
// Native encrypted settings, retained ledgers and staff reviews cannot be exercised by a unit fixture.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/native-app.php';
require __DIR__.'/Support/namesilo-enrollment-wire.php';
require __DIR__.'/Support/staff-tlds.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,Installation,LiveReservations,LocalAccountEnrollment,NameSiloAccountGuard,NameSiloSandbox,RegistrarRegistry,Runtime,RuntimePorts,StaffSettingsReview as Review,StaffSettingsWrites};
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($h,array $options): bool {$GLOBALS['rebindOptions']=array_replace($GLOBALS['rebindOptions']??[],$options);return true;}
function curl_getinfo($h,int $option): int {return 200;}
function curl_close($h): void {}
function curl_exec($h): bool {
    $o=$GLOBALS['rebindOptions'];$url=parse_url($o[CURLOPT_URL]);
    same('ote.namesilo.com',$url['host'],'Upgraded holding read stays on TEST');
    same('/apibatch/getDomainInfo',$url['path'],'Upgraded connection reads the domain through its admitted runtime');
    same(0,DB::transactionLevel(),'Domain reads stay outside locks');
    $GLOBALS['rebindReads']=($GLOBALS['rebindReads']??0)+1;
    $body=json_encode(['request'=>['operation'=>'getDomainInfo'],'reply'=>['code'=>($GLOBALS['rebindDomainMissing']??false)?200:300,'detail'=>'success',
        'created'=>'2026-01-01','expires'=>'2027-01-01','status'=>'Active','locked'=>'Yes','private'=>'No','auto_renew'=>'No',
        'nameservers'=>[['nameserver'=>'ns1.example.net','position'=>1],['nameserver'=>'ns2.example.net','position'=>2]],
        'contact_ids'=>['registrant'=>'123','administrative'=>'123','technical'=>'123','billing'=>'123']]]);
    return ($o[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
$GLOBALS['enrollmentCalls']=0;
config(['domains.namesilo_sandbox_enrollment.enabled'=>true,'domains.namesilo_account.environment'=>null,
    'cache.default'=>'database','session.driver'=>'database','queue.default'=>'database',
    'queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900]);
\Illuminate\Support\Facades\Schema::create('cache_locks',static function($t):void {
    $t->string('key')->primary();$t->string('owner');$t->integer('expiration');
});
\Illuminate\Support\Facades\Schema::create('cache',static function($t):void {
    $t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');
});
(new Installation)->install();Runtime::register();tldPanel();
$staff=staffUser(array_keys(Staff::permissions()));
$server=Server::create(['name'=>'Synthetic retained TEST account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$review=static fn(int $id,string $action='configure')=>new Review($staff->id,$id,null,$action,
    Review::ticket($staff->id,$id,null,$action,Review::stamp($id,null)),'Authenticate retained TEST account');
$credentials=['api_key'=>'synthetic-ote-key'];
app(StaffSettingsWrites::class)->run($review($server->id,'enroll_sandbox'),
    ['environment'=>'test','label'=>'Synthetic retained account','api_key'=>$credentials['api_key'],'confirmed'=>true]);
$account=Account::where('server_id',$server->id)->sole();$binding=AccountBindings::binding($account);
$domain=Domain::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,
    'service_id'=>701,'user_id'=>$staff->id,'domain'=>'example.com','sld'=>'example','tld'=>'com','status'=>'active',
    'provenance'=>'purchased','provider_expires_at'=>'2027-01-01','snapshot'=>['history'=>'synthetic holding']]);
$operation=Operation::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,
    'domain_id'=>$domain->id,'service_id'=>701,'authority_generation'=>1,'operation_key'=>'retained-held-request',
    'action'=>'renew','currency'=>'USD','amount'=>'12.00','state'=>'held','safe_code'=>'staff_review',
    'attempt'=>['seal'=>'synthetic-retained-seal','execution'=>null],'policy'=>[]]);
$retained=[$account->fresh()->getRawOriginal(),$domain->fresh()->getRawOriginal(),$operation->fresh()->getRawOriginal()];
$history=Review::historyRows($server);$sandboxRow=$server->settings()->where('key',NameSiloSandbox::KEY)->sole()->getRawOriginal();
same(1,$GLOBALS['enrollmentCalls'],'Legacy enrollment used the socket-free authentication fixture');
config(['domains.namesilo_sandbox_enrollment.enabled'=>false,'domains.namesilo_account.environment'=>'test']);
// Recompose the same installed services after the deployment switches from the old opt-in to local TEST.
$registry=new RegistrarRegistry;LocalAccountEnrollment::bootstrap($registry);
app()->instance(RegistrarRegistry::class,$registry);app()->instance(AccountBindings::class,new AccountBindings($registry));
app()->forgetInstance(StaffSettingsWrites::class);
$bindings=app(AccountBindings::class);$writes=app(StaffSettingsWrites::class);
$refuse=static function(callable $work):void {
    $before=[$GLOBALS['enrollmentCalls'],$GLOBALS['rebindReads']??0];$caught=null;
    try{$work();}catch(\Throwable $error){$caught=$error;}
    expect($caught!==null,'Unsafe account change must refuse');
    same($before,[$GLOBALS['enrollmentCalls'],$GLOBALS['rebindReads']??0],'Refused change makes no wire call');
};
$input=['registrar'=>'namesilo','environment'=>'test','api_key'=>$credentials['api_key'],'reason'=>'Authenticate retained TEST account'];
// Exercise the existing staff action: before the fix it catches the immutable-binding refusal and saves nothing.
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])
    ->callAction('configure_'.$server->id,data:$input)->assertHasNoActionErrors()->assertNotified('Saved');
same(2,$GLOBALS['enrollmentCalls'],'Existing staff action re-authenticates the same key once');
same('matched',$bindings->configurationStatus($server->id),'Upgraded account has a verified authenticated TEST connection');
$after=$account->fresh()->getRawOriginal();unset($after['updated_at'],$retained[0]['updated_at']);
same($retained[0],$after,'Upgrade retains the account ID, identity, fingerprints, environment and processing pause');
same($retained[1],$domain->fresh()->getRawOriginal(),'Upgrade retains exact holding and encrypted history bytes');
same($retained[2],$operation->fresh()->getRawOriginal(),'Upgrade retains the held request, seal and unknown execution');
same($sandboxRow,$server->settings()->where('key',NameSiloSandbox::KEY)->sole()->getRawOriginal(),'Original encrypted enrollment facts are retained');
same($history,array_slice(Review::historyRows($server),0,count($history)),'Existing audit history remains intact');
expect(!array_filter(Review::historyRows($server),fn($entry)=>str_contains(json_encode($entry),$credentials['api_key'])),'Audit never includes the key');
expect(!$account->fresh()->processing_enabled,'Authentication never enables sends');
expect(app(RuntimePorts::class)->registered($account->fresh()),'Upgraded TEST account composes the installed domain runtime');
$clock=app(Clock::class);$context=static fn()=>new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+60 seconds'),new RequestBudget(4),$clock,stopOnReadFailure:true);
$registry->requireDomainReads('namesilo'); // Normal verification must recognize the composed TEST holding reader.
$reader=$bindings->reader($server->id);$result=$reader->readDomain('example.com',$context())->evidence()->domain;
same(['example.com',true],[$result?->name,$result?->owned],'Same account reads its domain after authentication');
NameSiloAccountGuard::check($binding,$context(),static fn()=>throw new \RuntimeException('Authenticated local lineage must not need a default-contact read'));
$usage=static fn()=>(new LiveReservations(new TestReservationPolicy,Environment::Test))->usage($binding);
same(['used'=>1,'spend_micros'=>0],$usage(),'Domain read consumes the same TEST budget and no spend');
// The demo's retained transfer fixture returns provider code 200: composition cannot claim ownership.
$GLOBALS['rebindDomainMissing']=true;
$failed=false;try{$reader->readDomain('example.com',$context());}catch(\DomainException $e){$failed=true;}
expect($failed,'A provider domain-read failure stays a failure after composition is fixed');
$GLOBALS['rebindDomainMissing']=false;
$revision=$bindings->credentialRevision($binding);
$writes->run($review($server->id),['registrar'=>'namesilo','environment'=>'test','credentials'=>$credentials]);
expect($revision!==$bindings->credentialRevision($binding),'Reviewed same-key reauthentication replaces the credential fence');
$refuse(fn()=>$reader->readDomain('example.com',$context()));
same(['used'=>2,'spend_micros'=>0],$usage(),'Reauthentication preserves accumulated budget');
$other=Server::create(['name'=>'Duplicate TEST key','type'=>'server','extension'=>'Domains','enabled'=>true]);
$refuse(fn()=>$writes->run($review($other->id),['registrar'=>'namesilo','environment'=>'test','credentials'=>$credentials]));
expect(!Account::where('server_id',$other->id)->exists(),'Same key cannot create another budget lineage');
$refuse(fn()=>$writes->run($review($server->id),['registrar'=>'namesilo','environment'=>'live','credentials'=>$credentials]));
$refuse(fn()=>$writes->run($review($server->id),['registrar'=>'namesilo','environment'=>'test','credentials'=>['api_key'=>'other-synthetic-key']]));
config(['domains.namesilo_test.daily_call_cap'=>1]);
$refuse(fn()=>$bindings->reader($server->id)->readDomain('example.com',$context()));
config(['domains.namesilo_account.environment'=>'live']);
$refuse(fn()=>$bindings->reader($server->id));
same($retained[2],$operation->fresh()->getRawOriginal(),'Reads and refusal checks leave held requests untouched');
echo "NameSilo same-key TEST upgrade preserves lineage, holdings, held requests, history, pause and guards.\n";
$nativeSuccessful=true;
