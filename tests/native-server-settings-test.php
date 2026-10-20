<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic identities and readers only.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/installed.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/FakeRegistrar.php';
use App\Admin\Resources\ServerResource\Pages\{CreateServer,EditServer};
use App\Models\{Extension,Server};
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\{Auth,DB,Schema};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarSetup,RegistrarBalances as BalancesPage};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,Disposition,DriverResult,Environment,Evidence,Money,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,BalanceReads,BalanceSchema,CatalogueAcceptances,Installation,NativeCatalogue,NativeServerGuard,RegistrarBalances,RegistrarRegistry,Runtime,RuntimeHealth,SellerPolicies,StaffSettingsReview as Review,StaffSettingsWrites};
use Paymenter\Extensions\Servers\Domains\Tests\{FakeClock,FakeRegistrar};
function serverSettingsReject(callable $call): void
{
    $refused=false;try{$call();}catch(DomainException|Illuminate\Validation\ValidationException $e){$refused=true;}
    expect($refused,'Unsafe native account mutation must refuse at line '.debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,1)[0]['line']);
}
$registry=new RegistrarRegistry;$io=(object)['verifications'=>0,'readers'=>0];
$verifier=new class($io) implements AccountVerifier {
    public function __construct(private object $io) {}
    public function verify(Environment $env,#[SensitiveParameter] array $c): string
    {
        same(0,DB::transactionLevel(),'Identity verification outside native save transaction');$this->io->verifications++;
        return str_starts_with($c['token']??$c['api_key'],'PRIVATE-WRONG')?'other-account':(isset($c['uid'])?'synthetic-enom':'synthetic-namesilo');
    }
};
foreach(['enom'=>['uid','token'],'namesilo'=>['api_key']] as $key=>$fields){
    $registry->register($key,$fields,$verifier,static function(AccountBinding $b,array $c)use($io):FakeRegistrar {
        same(0,DB::transactionLevel(),'Reader factory outside locks');$io->readers++;
        return new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(balance:new Money('42.17','USD'))));
    });
}
app()->instance(RegistrarRegistry::class,$registry);$bindings=new AccountBindings($registry);app()->instance(AccountBindings::class,$bindings);
$clock=new FakeClock(new DateTimeImmutable('2026-10-19T12:00:00Z'));app()->instance(Clock::class,$clock);
$entry=new Domains;$entry->boot();installedPanel();
expect(!Schema::hasTable('domains_accounts'),'Fresh native form begins with no extension schema');
$permissions=[...array_keys(Staff::permissions()),'admin.servers.viewAny','admin.servers.view','admin.servers.create','admin.servers.update','admin.servers.delete'];
$staff=staffUser($permissions);Auth::login($staff);
$noNative=staffUser([Staff::VIEW]);$nativeOnly=staffUser(['admin.servers.viewAny','admin.servers.view','admin.servers.update']);
Livewire::actingAs($noNative)->test(CreateServer::class)->assertForbidden();
Livewire::actingAs($staff)->test(CreateServer::class)->fillForm(['name'=>'Native first registrar','extension'=>'Domains'])->call('create')->assertHasNoFormErrors();
$first=Server::where('name','Native first registrar')->sole();
foreach(Installation::SCHEMAS as $schema){$schema::verify();}
same(0,Account::count(),'Native creation installs retained schema without inventing account verification');
same(0,$io->verifications,'Native Create Server does no provider verification');
Livewire::actingAs($staff)->test(CreateServer::class)->fillForm(['name'=>'Native second registrar','extension'=>'Domains'])->call('create')->assertHasNoFormErrors();
$second=Server::where('name','Native second registrar')->sole();
// Register the owned pages after the fresh server exists, as native discovery does on its next boot.
$panel=Filament\Facades\Filament::getCurrentPanel();$panel->pages([RegistrarSetup::class,BalancesPage::class]);
Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel):void {RegistrarSetup::registerRoutes($panel);BalancesPage::registerRoutes($panel);});
app('router')->getRoutes()->refreshNameLookups();
$writer=new StaffSettingsWrites(app(NativeCatalogue::class),app(SellerPolicies::class),$bindings,true);app()->instance(StaffSettingsWrites::class,$writer);
$configure=static function(Server $s,string $registrar,string $secret)use($staff):void {
    $name=TestAction::make('configure_'.$s->id)->schemaComponent('account_'.$s->id,'content');
    $page=Livewire::actingAs($staff)->test(RegistrarSetup::class)->mountAction($name)->assertActionMounted($name);
    $data=['registrar'=>$registrar,'environment'=>'test','reason'=>'Verify the original synthetic account',...($registrar==='enom'?['uid'=>'synthetic-enom','token'=>$secret]:['api_key'=>$secret])];
    $page->setActionData($data)->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded');
};
$configure($first,'enom','PRIVATE-ORIGINAL-ENOM');$configure($second,'namesilo','PRIVATE-ORIGINAL-NAMESILO');
foreach([$first,$second] as $server){
    $a=Account::where('server_id',$server->id)->sole();$binding=CatalogueAcceptances::binding($a);
    same(false,$a->processing_enabled,'Owned verified configuration starts paused');
    $bindings->setProcessing($server->id,true,static fn()=>null);
    BalanceReads::record($binding,new Money('42.17','USD'),$clock->now(),'manual');
    expect($bindings->configurationAvailable($server->id),'Local configuration validated without constructing reader');
    same('42.17 USD',RegistrarBalances::facts($a,$clock->now())['balance'],'Valid original account displays own cached amount');
    $route=app('router')->getRoutes()->getByName('filament.admin.resources.servers.edit');
    same(EditServer::class,explode('@',$route->getActionName())[0],'Native route remains native');
    // Cached native route remains native; no Secure* class or mount guard participates.
    $cached=new Illuminate\Routing\RouteCollection;$copy=clone $route;$copy->prepareForSerialization();$cached->add($copy);
    $all=app('router')->getRoutes();app('router')->setCompiledRoutes($cached->compile());
    same(EditServer::class,explode('@',app('router')->getRoutes()->getByName($route->getName())->getActionName())[0],'Cached editor is not remapped');
    app('router')->setRoutes($all);app('url')->setRoutes($all);
    Livewire::actingAs($noNative)->test(EditServer::class,['record'=>$server->id])->assertForbidden();
    $edit=Livewire::actingAs($nativeOnly)->test(EditServer::class,['record'=>$server->id])->assertSuccessful();
    $settings=$edit->get('data.settings');
    $secret=$server->id===$first->id?'PRIVATE-ORIGINAL-ENOM':'PRIVATE-ORIGINAL-NAMESILO';
    expect(str_contains(json_encode($edit->snapshot),$secret),'Accepted boundary: native edit permission exposes decrypted credentials in Livewire state');
    expect(isset($settings['domains_verified_credentials']),'Native all-settings fill exposes the digest too');
    $rawBefore=DB::table('settings')->where('settingable_id',$server->id)->whereIn('key',['domains_credentials','domains_verified_credentials'])->orderBy('id')->get()->toJson();
    $edit->call('save')->assertHasNoFormErrors(); // Native hydration and unchanged save, no mount/hydrate interception.
    same($rawBefore,DB::table('settings')->where('settingable_id',$server->id)->whereIn('key',['domains_credentials','domains_verified_credentials'])->orderBy('id')->get()->toJson(),'Unchanged native save leaves credential ciphertext unchanged');
    $bindings->requireProcessing($binding);
    $edit->fillForm(['name'=>'Renamed native account '.$server->id])->call('save')->assertHasNoFormErrors();
    $bindings->requireProcessing($binding);
    // Internal keys are not declared native inputs; forged client state is not verified rotation.
    $edit->set('data.settings.domains_credentials','{"api_key":"PRIVATE-WRONG-PAYLOAD"}')->call('save')->assertHasNoFormErrors();
    same($rawBefore,DB::table('settings')->where('settingable_id',$server->id)->whereIn('key',['domains_credentials','domains_verified_credentials'])->orderBy('id')->get()->toJson(),'Native payload cannot persist undeclared credential settings');
    $setting=$server->settings()->where('key','domains_credentials')->sole();
    $setting->value=json_encode($a->registrar_key==='enom'?['uid'=>'other-account','token'=>'PRIVATE-WRONG-ENOM']:['api_key'=>'PRIVATE-WRONG-NAMESILO']);$setting->save();
    $readerCalls=$io->readers;
    serverSettingsReject(fn()=>$bindings->requireProcessing($binding));serverSettingsReject(fn()=>$bindings->reader($server->id));
    DB::transaction(function()use($bindings,$server,$a):void {serverSettingsReject(fn()=>$bindings->requireProcessingLocked(Server::whereKey($server->id)->lockForUpdate()->sole(),$a->fresh()));});
    same($readerCalls,$io->readers,'Changed credentials refuse before reader or spending transport composition');
    $facts=RegistrarBalances::facts($a,$clock->now());same('Unknown',$facts['balance'],'Wrong account never displays original account balance');same('Configuration unavailable',$facts['error'],'Changed credentials give safe balance status');
    same('42.17',DB::table(BalanceSchema::TABLE)->where('server_id',$server->id)->value('amount'),'Held display retains original balance history');
    $health=RuntimeHealth::for($server->id);expect(str_contains($health['Registrar configuration'],'saved settings no longer match the verified account'),'Hold covers credential and digest settings');expect(str_contains($health['Registrar configuration'],'re-enter credentials for the original account'),'Local health gives actionable hold');
    Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSee('On hold')->assertSee('re-enter credentials for the original account')->assertDontSee('PRIVATE-WRONG');
    Livewire::actingAs($staff)->test(BalancesPage::class)->assertDontSee('PRIVATE-WRONG');
    // A positively verified different account still cannot repurpose the retained binding.
    serverSettingsReject(fn()=>$bindings->configure($server->id,$a->registrar_key,Environment::Test,$a->registrar_key==='enom'?['uid'=>'other-account','token'=>'PRIVATE-WRONG-ROTATION']:['api_key'=>'PRIVATE-WRONG-ROTATION']));
    same($binding->fingerprint,$a->fresh()->account_fingerprint,'Failed rotation retains B1 fingerprint key');
    $configure($server,$a->registrar_key,'PRIVATE-ROTATED-'.$a->registrar_key);
    same($binding->fingerprint,$a->fresh()->account_fingerprint,'Verified same-account rotation retains B1 fingerprint key');
    same(false,$a->fresh()->processing_enabled,'Verified recovery requires explicit resume');
    same('42.17 USD',RegistrarBalances::facts($a,$clock->now())['balance'],'Same verified account can display its retained amount again');
    $digest=$server->settings()->where('key','domains_verified_credentials')->sole();$savedDigest=$digest->value;$digest->update(['value'=>str_repeat('f',64)]);
    serverSettingsReject(fn()=>$bindings->reader($server->id));same('Unknown',RegistrarBalances::facts($a,$clock->now())['balance'],'Digest-only changes also hide the balance');$digest->update(['value'=>$savedDigest]);
    $bindings->setProcessing($server->id,true,static fn()=>null);$server->refresh()->update(['enabled'=>false]);
    same(false,$a->fresh()->processing_enabled,'Native disable pauses the retained account');
    expect(str_contains(RuntimeHealth::for($server->id)['Registrar configuration'],'Re-enable it in Servers, then resume processing in Registrar setup'),'Disabled-server health names its actual recovery path');same('Unknown',RegistrarBalances::facts($a,$clock->now())['balance'],'Disabled account hides balance');
    serverSettingsReject(fn()=>$bindings->reader($server->id));$server->refresh()->update(['enabled'=>true]);same(false,$a->fresh()->processing_enabled,'Re-enable does not resume spending');
    foreach([Server::class,Extension::class] as $model){
        foreach(['extension'=>'Enom','type'=>'other','id'=>999999] as $field=>$value){$s=$model::findOrFail($server->id);$s->$field=$value;serverSettingsReject(fn()=>$s->save());}
        $s=$model::findOrFail($server->id);serverSettingsReject(fn()=>$s->delete());
        $s=$model::findOrFail($server->id);$s->id=999999;serverSettingsReject(fn()=>$s->delete());
    }
    $bindings->setProcessing($server->id,true,static fn()=>null);
    $retained=DB::table('settings')->where('settingable_id',$server->id)->orderBy('id')->get()->toJson();
    Livewire::actingAs($staff)->test(EditServer::class,['record'=>$server->id])->callAction('delete')
        ->assertSuccessful()->assertActionHalted('delete')->assertNotified('Server could not be deleted')->assertNotNotified('Deleted');
    expect(Server::whereKey($server->id)->exists(),'Handled native delete retains server');
    same(false,$a->fresh()->processing_enabled,'Native before-delete hook still pauses processing');
    same($retained,DB::table('settings')->where('settingable_id',$server->id)->orderBy('id')->get()->toJson(),'Handled refusal retains encrypted settings');
    same($binding->fingerprint,$a->fresh()->account_fingerprint,'Handled refusal retains the account binding');
    serverSettingsReject(fn()=>$a->fresh()->delete());
    $owned=Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSee('Treat staff with server-edit permission as able to access them');
    foreach([$secret,'PRIVATE-ROTATED-'.$a->registrar_key,$savedDigest,$a->account_fingerprint] as $private){expect(!str_contains($owned->html(),$private),'Owned setup excludes saved credentials, HMAC and fingerprints');}
    $audit=Review::historyRows($server->fresh());expect(count($audit)>=2,'Configuration and rotation retain audited review outcomes');
    foreach(['PRIVATE-ORIGINAL','PRIVATE-ROTATED','PRIVATE-WRONG','domains_credentials',$savedDigest] as $private){expect(!str_contains(json_encode($audit),$private),'Retained owned audit excludes credentials and verification digest');}
    $history=json_encode(TldFacts::history($server->id));
    foreach(['PRIVATE-ORIGINAL','PRIVATE-ROTATED','PRIVATE-WRONG',$savedDigest,$a->account_fingerprint] as $private){expect(!str_contains($history,$private),'Owned history projection excludes secrets, digest and account fingerprint');}
}
class_alias(App\Classes\Extension\Server::class,'Paymenter\\Extensions\\Servers\\SyntheticHosting\\SyntheticHosting');
$control=Server::create(['type'=>'server','extension'=>'SyntheticHosting','name'=>'Unrelated hosting control','enabled'=>false]);
Livewire::actingAs($staff)->test(EditServer::class,['record'=>$control->id])->callAction('delete')->assertSuccessful()->assertNotified('Deleted');
expect(!Server::whereKey($control->id)->exists(),'Unrelated native server deletion continues');
// Local site holds have different recovery from credential edits and never construct a reader.
$live=Server::create(['type'=>'server','extension'=>'Domains','name'=>'Live site qualification','enabled'=>true]);
$liveBinding=$bindings->configure($live->id,'namesilo',Environment::Live,['api_key'=>'PRIVATE-SITE-LIVE']);
$liveAccount=Account::where('server_id',$live->id)->sole();BalanceReads::record($liveBinding,new Money('77.00','USD'),$clock->now(),'manual');
$savedUrl=config('app.url');config(['app.url'=>'https://native-settings.example.invalid']);
same('site_unbound',$bindings->configurationStatus($live->id),'Unbound live site is distinct from changed credentials');
expect(str_contains(RuntimeHealth::for($live->id)['Registrar configuration'],'This release has no staff action to bind it; processing stays off.'),'Unbound-site health names the unsupported staff binding action');
same('Unknown',RegistrarBalances::facts($liveAccount,$clock->now())['balance'],'Unbound live site hides cached amount');
$bindings->confirmLiveSite($live->id,'https://native-settings.example.invalid');
same('77.00 USD',RegistrarBalances::facts($liveAccount,$clock->now())['balance'],'Explicit site binding permits same-account cached display');
config(['app.url'=>'https://moved-native-settings.example.invalid']);
same('site_mismatch',$bindings->configurationStatus($live->id),'Changed application URL holds live configuration');
expect(str_contains(RuntimeHealth::for($live->id)['Registrar configuration'],'restore the original site configuration'),'Site mismatch does not suggest ineffective credential rotation');
same('Unknown',RegistrarBalances::facts($liveAccount,$clock->now())['balance'],'Wrong live site hides cached amount');
config(['app.url'=>'http://native-settings.example.invalid']);same('site_invalid',$bindings->configurationStatus($live->id),'Invalid live URL has distinct recovery');
config(['app.url'=>'https://native-settings.example.invalid']);
// Copying another server's matching credential/digest pair cannot reuse its HMAC binding.
$destination=$second->fresh();$saved=[];
foreach(['domains_credentials','domains_verified_credentials'] as $key){$s=$destination->settings()->where('key',$key)->sole();$saved[$key]=$s->value;$s->update(['value'=>$live->settings()->where('key',$key)->sole()->value]);}
serverSettingsReject(fn()=>$bindings->reader($destination->id));
same('Unknown',RegistrarBalances::facts(Account::where('server_id',$destination->id)->sole(),$clock->now())['balance'],'Copied pair never displays another bound balance');
foreach($saved as $key=>$value){$destination->settings()->where('key',$key)->sole()->update(['value'=>$value]);}
config(['app.url'=>$savedUrl]);
expect(!array_key_exists('Native editors',RuntimeHealth::for($first->id)),'Server qualification health needs no remap fact');
same(null,NativeServerGuard::deletionNoticeMissing(),'Native delete notice capabilities are available');
expect(!array_key_exists('Server delete notice',RuntimeHealth::for($first->id)),'Available native delete notice needs no unavailable warning');
Livewire::actingAs($nativeOnly)->test(RegistrarSetup::class)->assertForbidden();Livewire::actingAs($nativeOnly)->test(BalancesPage::class)->assertForbidden();
// Legacy processing exclusion and installation refusal are scoped model events, with no remaps.
$legacy=Server::create(['type'=>'server','extension'=>'Enom','name'=>'Paused legacy','enabled'=>false]);
$toggle=$legacy->settings()->create(['key'=>'operations_enabled','value'=>false,'type'=>'boolean']);
serverSettingsReject(fn()=>$toggle->update(['value'=>true]));
DB::table('settings')->where('id',$toggle->id)->update(['value'=>'1']);
Livewire::actingAs($staff)->test(CreateServer::class)->fillForm(['name'=>'Legacy must refuse','extension'=>'Domains'])->call('create')->assertHasFormErrors(['extension']);
expect(!Server::where('name','Legacy must refuse')->exists(),'Installation refusal precedes native persistence');
DB::table('settings')->where('id',$toggle->id)->update(['value'=>'0']);
same(0,$io->readers,'All native forms, local health and cached balance facts make zero provider reads');
echo "Native server editor qualification without remaps/interception: creation, exposure, harmless saves, holds, balances, rotation, permissions and lifecycle passed.\n";$nativeSuccessful=true;
