<?php
// Reused with installed bytes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Server;
use Illuminate\Support\Facades\{Auth,DB,Log};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,IdentityRefused,RegistrarRegistry,Runtime,RuntimePorts,StaffSettingsReview as Review,StaffSettingsWrites};
require_once __DIR__.'/staff-tlds.php';
config(['domains.enom_identity.environment'=>'test']);
(new Paymenter\Extensions\Servers\Domains\Domains)->installed();
(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
same(['enom'],app(RegistrarRegistry::class)->keys(),'Production boot registers only Enom identity');
expect(app(StaffSettingsWrites::class)->connectionAvailable(),'Production boot wires staff reads');
tldPanel();$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
$server=Server::create(['name'=>'Synthetic identity account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$action=static fn(string $name)=>Filament\Actions\Testing\TestAction::make($name.'_'.$server->id)->schemaComponent('account_'.$server->id,'content');
$configure=static function(#[SensitiveParameter] array $input, bool $success=true, ?string $message=null)use($staff,$action,$server):void {
    $page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction($action('configure'))->assertActionMounted($action('configure'));
    expect(empty($page->get('mountedActions.0.data.uid'))&&empty($page->get('mountedActions.0.data.token')),'Never prefill saved secrets');
    $page->setActionData(['registrar'=>'enom','environment'=>'test','uid'=>'secret-uid','token'=>'secret-token','reason'=>'Verify synthetic account identity',...$input])
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified($message!==null
            ?Filament\Notifications\Notification::make()->danger()->title('Change refused')->body($message)
            :($success?'Change recorded':'Change refused'))->assertSet('mountedActions',[]);
};
Log::spy();config(['domains.enom_test_daily_call_cap'=>0]);$calls=count($GLOBALS['identityCalls']);
$configure([],false,'Enom TEST request refused before sending. '.Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::facts());
same($calls,count($GLOBALS['identityCalls']),'Staff sees identity cap refusal without a provider send');expect(Account::where('server_id',$server->id)->doesntExist(),'Cap refusal saves no unverified account for this server');
config(['domains.enom_test_daily_call_cap'=>50]);$calls=count($GLOBALS['identityCalls']);$configure([]);same($calls+1,count($GLOBALS['identityCalls']),'Staff verification exactly one read');
$a=Account::where('server_id',$server->id)->sole();same('secret-uid',$a->stable_identity,'Encrypted verified UID');same(false,$a->processing_enabled,'Processing stays off');
expect(!str_contains($a->getRawOriginal('stable_identity'),'secret-uid'),'UID encrypted at rest');
foreach($server->settings()->whereIn('key',['domains_credentials','domains_verified_credentials'])->get() as $s){expect($s->encrypted,'Secrets encrypted before first save');expect(!str_contains($s->getRawOriginal('value'),'secret-token'),'No plaintext token');}
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->assertSee('API user')->assertSee('secret-uid')->assertSee('Test')->assertSee('Orders paused')->assertDontSee('secret-token');
$calls=count($GLOBALS['identityCalls']);$page->mountAction($action('probe'))->setActionData(['reason'=>'Read synthetic account balance'])->callMountedAction()->assertNotified('Change recorded');same($calls+1,count($GLOBALS['identityCalls']),'Separate balance probe exactly one call');
same('9876.54',DB::table('domains_registrar_balances')->where('server_id',$server->id)->value('amount'),'Balance read works before catalogue acceptance');
$GLOBALS['identityMode']='html';$calls=count($GLOBALS['identityCalls']);
$configure([],false,'Enom did not confirm this account. Nothing was saved. Try again later.');
same($calls+1,count($GLOBALS['identityCalls']),'Unavailable identity makes one attempt');
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction($action('probe'))->setActionData(['reason'=>'Check unavailable balance response'])->callMountedAction()
    ->assertNotified(Filament\Notifications\Notification::make()->danger()->title('Change refused')->body('Enom did not confirm the balance. The last saved balance was kept.'));
same($calls+2,count($GLOBALS['identityCalls']),'Unavailable probe makes one attempt');
same('9876.54',DB::table('domains_registrar_balances')->where('server_id',$server->id)->value('amount'),'Failed probe retains last amount');
$GLOBALS['identityMode']='credentials';
$calls=count($GLOBALS['identityCalls']);$configure(['token'=>'rotated-token']);same($a->account_fingerprint,$a->fresh()->account_fingerprint,'Verified token rotation preserves identity');
$configure(['uid'=>'SECRET-UID','token'=>'rotated-token']);same('secret-uid',$a->fresh()->stable_identity,'Authenticated UID case canonicalized');
$configure(['uid'=>'other-uid'],false,'These credentials belong to a different account. The saved account was kept.');same('secret-uid',$a->fresh()->stable_identity,'Different authenticated account refused');
$configure(['uid'=>'wrong-uid'],false,'Enom refused the user ID or API token. Check both for the selected environment.');
$configure(['token'=>'wrong-token'],false,'Enom refused the user ID or API token. Check both for the selected environment.');
$calls=count($GLOBALS['identityCalls']);$configure(['environment'=>'live'],false,'This environment is not enabled for Enom account reads on this site. No provider call was made.');
same($calls,count($GLOBALS['identityCalls']),'Staff environment refusal makes no call');
$configure(['registrar'=>'namesilo','api_key'=>'synthetic-key'],false,'NameSilo identity verification is unavailable. This account cannot be connected.');
same(false,$a->fresh()->processing_enabled,'All attempts leave processing off');expect(!app(RuntimePorts::class)->accepted($a->fresh()),'Identity installs no write ports');
try{app(AccountBindings::class)->requireProcessing(Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($a->fresh()));expect(false,'Processing guard required');}catch(DomainException){}
try{app(AccountBindings::class)->reader($server->id);expect(false,'Catalogue acceptance still required');}catch(RuntimeException){}
Log::shouldNotHaveReceived('warning');Log::shouldNotHaveReceived('error');
foreach(Review::historyRows($server->fresh()) as $row){$json=json_encode($row);foreach(['secret-uid','secret-token','9876.54'] as $secret){expect(!str_contains($json,$secret),'Audit omits identity/token/balance');}}
// A catalogue acceptance cannot promote the staff-only factory into a scheduler reader.
$binding=Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($a->fresh());
Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::save($binding,0,['com'=>['register'=>[1],'renew'=>[1],'transfer'=>[],'registry'=>true,'renewal_safe'=>true]]);
$calls=count($GLOBALS['identityCalls']);app(Paymenter\Extensions\Servers\Domains\Services\RegistrarBalances::class)->daily($a->fresh());
same($calls,count($GLOBALS['identityCalls']),'Daily balance stays off wire even with accepted catalogue');
// Keep unrelated public data updating socket-free while exercising the actual scheduler turn.
app()->instance(Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,new Paymenter\Extensions\Servers\Domains\Services\PslTransport(static function():never {throw new Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed');}));
app(Paymenter\Extensions\Servers\Domains\Services\RuntimeWork::class)->tick();
same($calls,count($GLOBALS['identityCalls']),'Runtime turn cannot promote staff factory to background reads');
try{app(AccountBindings::class)->adoptionReader($binding,app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class),new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\CurlTransport);expect(false,'Identity bootstrap cannot construct an adoption domain reader');}
catch(DomainException $e){same('Domain reads require separate deployment composition.',$e->getMessage(),'Adoption has a separate deployment gate');}
same($calls,count($GLOBALS['identityCalls']),'Adoption factory refuses before any domain read');
// Retained live account plus TEST deployment must refuse staff reads before transport construction.
config(['app.url'=>'https://identity.example.invalid']);
$liveRegistry=new RegistrarRegistry;
Paymenter\Extensions\Servers\Domains\Services\RegistrarFactories::install($liveRegistry,new Paymenter\Extensions\Servers\Domains\Registrars\Enom\AccountVerifier(Paymenter\Extensions\Servers\Domains\Data\Environment::Live));
$liveBindings=new AccountBindings($liveRegistry);$liveServer=Server::create(['name'=>'Synthetic retained live account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$GLOBALS['identityMode']='empty_account';$liveBindings->configure($liveServer->id,'enom',Paymenter\Extensions\Servers\Domains\Data\Environment::Live,['uid'=>'retained-live','token'=>'synthetic-live']);
$liveBindings->confirmLiveSite($liveServer->id,'https://identity.example.invalid');$GLOBALS['identityMode']='credentials';
$calls=count($GLOBALS['identityCalls']);
try{app(AccountBindings::class)->reader($liveServer->id,true);expect(false,'TEST factory must refuse live binding');}catch(IdentityRefused $e){same('identity_environment_refused',$e->reason,'Reader environment refusal');}
same($calls,count($GLOBALS['identityCalls']),'TEST bootstrap cannot read live account');
echo "Production factory and native Filament identity/balance remain default-held.\n";
