<?php
// Exercised against source and installed ZIP; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Server;
use Illuminate\Support\Facades\{Auth,DB,Log};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,BalanceSchema,CatalogueAcceptances,IdentityRefused,NameSiloSandbox,ObservationSchema,RegistrarBalances,RegistrarFactories,RegistrarRegistry,RuntimePorts,StaffSettingsReview as Review,StaffSettingsWrites};
require_once __DIR__.'/staff-tlds.php';
ini_set('zend.exception_ignore_args','0');$GLOBALS['enrollmentCalls']=0;
$empty=new RegistrarRegistry;config(['domains.namesilo_sandbox_enrollment.enabled'=>false]);
same(false,NameSiloSandbox::bootstrap($empty),'Enrollment defaults off');foreach(['true',1] as $truthy){config(['domains.namesilo_sandbox_enrollment.enabled'=>$truthy]);same(false,NameSiloSandbox::enabled(),'Only explicit boolean opt-in');}config(['domains.namesilo_sandbox_enrollment.enabled'=>false]);same([],$empty->keys(),'No default reader');
config(['domains.namesilo_sandbox_enrollment.enabled'=>true]);
(new Paymenter\Extensions\Servers\Domains\Domains)->installed();(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
same(['namesilo'],app(RegistrarRegistry::class)->keys(),'Production boot composes sandbox only');
$bindings=app(AccountBindings::class);$writes=app(StaffSettingsWrites::class);
tldPanel();$staff=staffUser([...array_keys(Staff::permissions()),'admin.servers.viewAny','admin.servers.view','admin.servers.update']);Auth::setUser($staff);Log::spy();
// Keep one native create below; remaining fixtures skip the already-covered installation preflight.
$nativeServer=static fn(bool $enabled=true)=>Server::create(['name'=>'Sandbox enrollment','type'=>'server','extension'=>'Domains','enabled'=>$enabled]);
$fresh=static fn(bool $enabled=true)=>Server::withoutEvents(static fn()=>$nativeServer($enabled));
$review=static function(Server $s,string $action='enroll_sandbox')use($staff):Review {
    return new Review($staff->id,$s->id,null,$action,Review::ticket($staff->id,$s->id,null,$action,Review::stamp($s->id,null)),'Review sandbox enrollment');
};
$input=['environment'=>'test','label'=>'sandbox@example.test','api_key'=>'candidate-ote-key','confirmed'=>true];
$refuse=static function(#[SensitiveParameter] callable $work,string $message,?string $expected=null):void {
    $caught=null;try{$work();}catch(Throwable $e){$caught=$e;}
    expect($caught!==null,'Refusal required: '.$message);
    $text=(string)$caught;
    $scan=static function(mixed $arg,int $depth=0)use(&$scan):string {
        if($depth>6){return '';}
        if($arg instanceof Closure){return $scan((new ReflectionFunction($arg))->getStaticVariables(),$depth+1);}
        if(is_array($arg)){return implode('',array_map(fn($value)=>$scan($value,$depth+1),$arg));}
        return is_scalar($arg)?(string)$arg:'';
    };
    foreach($caught->getTrace() as $frame){foreach($frame['args']??[] as $arg){$text.=$scan($arg);}}
    foreach(['synthetic-ote-key','candidate-ote-key','wrong-key','sandbox@example.test','rotated-key','racing-key','9876.54'] as $secret){expect(!str_contains($text,$secret),'No secret in exception or argument trace: '.$message);}
    same(null,$caught->getPrevious(),'No retained provider exception');
    if($expected!==null){same($expected,$caught->getMessage(),'Exact safe refusal: '.$message);}
};
same(true,$writes->connectionAvailable('namesilo'),'NameSilo OTE reader is available');same(false,$writes->connectionAvailable('enom'),'NameSilo-only opt-in does not enable Enom reads');
$unsupported=$nativeServer();same('not_configured',$bindings->configurationStatus((int)$unsupported->id),'Fresh server account binding is not configured');expect(!Account::where('server_id',$unsupported->id)->exists(),'Fresh server has no account or Enrollment row');
$emptySetup=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$unsupported->id])->assertSee('Not connected')->assertSee('Account not set up yet. Use Connect account.')->assertDontSee('OTE only · immutable')->assertDontSee('Unavailable · not a current OTE enrollment.');unset($emptySetup);gc_collect_cycles();
$unsupportedReview=$review($unsupported,'configure');
$refuse(fn()=>$writes->run($unsupportedReview,['registrar'=>'enom','environment'=>'test','uid'=>'synthetic-uid','token'=>'synthetic-token','reason'=>'Check unsupported Enom','review_key'=>'unused']),'Enom is unavailable in a NameSilo-only deployment','Connection checks are unavailable.');
expect(!DB::table(Review::KEYS)->where('server_id',$unsupported->id)->exists(),'Unavailable Enom composition refuses before consuming review');
$attempt=static function(#[SensitiveParameter] array $change=[],?Server $s=null)use($writes,$review,$fresh,$input):Server {
    $s??=$fresh();$writes->run($review($s),[...$input,...$change]);return $s;
};
$GLOBALS['enrollmentMode']='ok';$s=$fresh();
// Use Paymenter's unchanged native editor, including its informational settings placeholder.
$extensionDir=base_path('extensions/Servers');if(!is_dir($extensionDir)){mkdir($extensionDir,0700,true);}
if(!file_exists($extensionDir.'/Domains')){symlink(dirname(__DIR__,2).'/Domains',$extensionDir.'/Domains');}
$nativeEdit=Livewire::actingAs($staff)->test(App\Admin\Resources\ServerResource\Pages\EditServer::class,['record'=>$s->id])
    ->fillForm(['name'=>'Native OTE enrollment'])->set('data.settings.domains_namesilo_expected_default_contact_email','expected@example.com')->set('data.settings.domains_setup_note',null)->call('save')->assertHasNoFormErrors();
// Exercise the native write hook with a retained placeholder; Filament may omit an empty placeholder.
(new ReflectionMethod(App\Admin\Resources\ServerResource\Pages\EditServer::class,'handleRecordUpdate'))->invoke($nativeEdit->instance(),$s,['settings'=>['domains_setup_note'=>null,'domains_namesilo_expected_default_contact_email'=>'expected@example.com']]);
expect($s->settings()->where('key','domains_setup_note')->exists(),'Native write hook saves informational placeholder');
unset($nativeEdit);gc_collect_cycles();

$action=static fn(string $name,Server $server)=>setupAction($name,$server->id);
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$s->id])->mountAction($action('enroll_sandbox',$s))->assertActionMounted($action('enroll_sandbox',$s));
$page->assertSchemaComponentExists('label',checkComponentUsing:fn($field)=>str_contains((string)$field->toHtml(),'test-west')&&str_contains((string)$field->toHtml(),'Do not enter personal data')&&!str_contains((string)$field->toHtml(),'login email'));
expect(empty($page->get('mountedActions.0.data.api_key')),'No saved secret prefill');$n=$GLOBALS['enrollmentCalls'];
$page->setActionData([...$input,'api_key'=>'synthetic-ote-key','reason'=>'Enroll a sandbox account']);
expect(str_contains(SettingsActions::details('enroll_sandbox'),'The balance is not saved.'),'Enrollment copy says the key-check balance is discarded');
expect(str_contains(SettingsActions::details('enroll_sandbox'),'next background run may send one scheduled balance read')
    &&str_contains(SettingsActions::details('enroll_sandbox'),'Test connection can send one more balance request'),'Enrollment copy explains scheduled and interactive balance reads');
$GLOBALS['enrollmentExpectedContext']='api';$GLOBALS['enrollmentExpectedTimeout']=10000;
$page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Account connected')->assertSet('mountedActions',[]);
$page->assertActionDoesNotExist($action('enroll_sandbox',$s));unset($page);gc_collect_cycles();
same($n+1,$GLOBALS['enrollmentCalls'],'Staff enrollment exactly one OTE read');$a=Account::where('server_id',$s->id)->sole();$binding=CatalogueAcceptances::binding($a);
expect(NameSiloSandbox::enrolled($a),'Distinct local enrollment identity');
foreach(['sandbox@example.test','synthetic-ote-key','123','456','789','999','9876.54'] as $substitute){expect($a->stable_identity!==$substitute,'No identity substitute');}
same('test',$a->environment,'Fixed sandbox environment');same(false,$a->processing_enabled,'Processing starts off');same(null,$a->live_site,'No live site');
$s->update(['enabled'=>false]);$unavailableSetup=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$s->id])->assertSee('Not connected')->assertSee('Off in Paymenter')->assertDontSee('not a current OTE enrollment');unset($unavailableSetup);gc_collect_cycles();$s->update(['enabled'=>true]);
expect(!app(RuntimePorts::class)->accepted($a),'No installed write ports');
$refuse(fn()=>$bindings->requireProcessing($binding),'Processing gate');$refuse(fn()=>$bindings->reader($s->id),'Domain reads separately composed');
$refuse(fn()=>CatalogueAcceptances::load($binding),'No inherited acceptance');
same(0,Paymenter\Extensions\Servers\Domains\Models\Domain::where('server_id',$s->id)->count(),'No inherited holdings');
same(0,Paymenter\Extensions\Servers\Domains\Models\Operation::count(),'No inherited operations');
foreach($s->settings()->whereIn('key',[NameSiloSandbox::KEY,'domains_credentials','domains_verified_credentials'])->get() as $setting){
    expect($setting->encrypted,'Encrypted before first save');foreach(['sandbox@example.test','synthetic-ote-key','9876.54'] as $secret){expect(!str_contains($setting->getRawOriginal('value'),$secret),'Encrypted at rest');}
}
$facts=json_decode($s->settings()->where('key',NameSiloSandbox::KEY)->sole()->value,true);
same(false,$facts['provider_confirmed_account'],'Stored ownership disclaimer');same(true,$facts['authenticated_ote_key'],'Stored authentication fact');same(hash('sha256','synthetic-ote-key'),$facts['key_fingerprint'],'Fixed key fingerprint');
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$s->id])->assertSee('API connection')->assertSee('Connected')->assertDontSee('Verified')->assertDontSee('OTE only')->assertDontSee('NameSilo did not confirm which account this key belongs to.')->assertDontSee('synthetic-ote-key')->assertDontSee('9876.54');unset($page);gc_collect_cycles();
$configurePage=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$s->id])->mountAction($action('configure',$s))->assertActionMounted($action('configure',$s));
same("NameSilo accounts can't be connected with API credentials.",$configurePage->instance()->getMountedAction()->getModalDescription(),'NameSilo identity refusal remains unchanged');
$configurePage->setActionData(['registrar'=>'enom']);same(Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::description('configure'),$configurePage->instance()->getMountedAction()->getModalDescription(),'Changing registrar updates description');
unset($configurePage);gc_collect_cycles();
// A separate read works without processing or acceptance. It is not ownership proof.
BalanceSchema::install();ObservationSchema::install();Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
$GLOBALS['enrollmentExpectedTimeout']=10000;
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,
    new Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable('2026-10-21 12:00:00',new DateTimeZone('UTC'))));
$n=$GLOBALS['enrollmentCalls'];$probePage=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$s->id])->mountAction($action('probe',$s));
$probePage->assertActionEnabled($action('probe',$s))->setActionData(['reason'=>'Check sandbox balance'])->callMountedAction()
    ->assertHasNoActionErrors()->assertNotified('Balance updated')->assertSet('mountedActions',[]);
same($n+1,$GLOBALS['enrollmentCalls'],'Separate balance probe reaches OTE');
same('/api/getAccountBalance',$GLOBALS['enrollmentLastRead']['path']??null,'Separate balance request route');
same('ok',$GLOBALS['enrollmentLastRead']['mode']??null,'Separate balance request fixture mode');
same(true,$GLOBALS['enrollmentLastRead']['key_valid']??null,'Separate balance uses the enrolled key');
same(Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Outcome::Success,
    (new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\ResponseParser)->parse($GLOBALS['enrollmentLastRead']['body']??'',Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\RegistrarRequest::balance(),'synthetic-ote-key')->outcome,
    'Separate balance reply shape');
unset($probePage);gc_collect_cycles();
$balanceClock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable('2026-10-21 12:00:05',new DateTimeZone('UTC')));
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$balanceClock);
app()->instance(Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,
    new Paymenter\Extensions\Servers\Domains\Services\PslTransport(static function():never {throw new Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed');}));
$balanceService=new RegistrarBalances($bindings,$balanceClock,new AccountReadBudget($balanceClock));
$GLOBALS['enrollmentExpectedContext']='apibatch';
$GLOBALS['enrollmentExpectedTimeout']=25000;
Log::shouldNotHaveReceived('warning');Log::shouldNotHaveReceived('error');
$n=$GLOBALS['enrollmentCalls'];app(Paymenter\Extensions\Servers\Domains\Services\RuntimeWork::class)->tick();same($n+1,$GLOBALS['enrollmentCalls'],'First runtime tick sends one scheduled OTE balance read');
same('/apibatch/getAccountBalance',$GLOBALS['enrollmentLastRead']['path']??null,'Scheduled runtime read uses the OTE batch balance route');
$n=$GLOBALS['enrollmentCalls'];app(Paymenter\Extensions\Servers\Domains\Services\RuntimeWork::class)->tick();same($n,$GLOBALS['enrollmentCalls'],'Following runtime tick does not repeat the daily balance read');
$balanceRow=DB::table(BalanceSchema::TABLE)->where('server_id',$s->id)->sole();same('daily',$balanceRow->source,'Scheduled read records its source');same(null,$balanceRow->last_error_code,'Scheduled OTE read stays healthy');
$GLOBALS['enrollmentExpectedContext']='api';
$balanceClock->instant=$balanceClock->now()->modify('+2 seconds');$n=$GLOBALS['enrollmentCalls'];same('refreshed',$balanceService->refresh($s->id),'Manual refresh uses the enrolled OTE balance composition');
same($n+1,$GLOBALS['enrollmentCalls'],'Manual refresh makes one OTE read');same('manual',DB::table(BalanceSchema::TABLE)->where('server_id',$s->id)->value('source'),'Manual read records its source');
same(null,RegistrarBalances::facts($a,$balanceClock->now())['cause'],'Successful scheduler and refresh keep balance status healthy');
$GLOBALS['enrollmentExpectedContext']='api';
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$s->id])->assertSee('Add funds')->assertSeeHtml('https://ote.namesilo.com/cart/add-funds')->assertDontSee('www.namesilo.com');
$n=$GLOBALS['enrollmentCalls'];$refuse(fn()=>$attempt(['api_key'=>'synthetic-ote-key']),'Duplicate key must use original enrollment');same($n,$GLOBALS['enrollmentCalls'],'Duplicate key refuses before read');

// Render recovery forms before unrelated guard fixtures accumulate.
$uncertain=$fresh();$GLOBALS['enrollmentExpectedContext']='api';$GLOBALS['enrollmentExpectedTimeout']=10000;
$GLOBALS['enrollmentDuringRead']=static function()use($uncertain):void {DB::table('domains_runtime_turns')->insert(['server_id'=>$uncertain->id,'account_fingerprint'=>str_repeat('b',64)]);};
$offline=$fresh(false);$n=$GLOBALS['enrollmentCalls'];$offlinePage=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$offline->id])->mountAction($action('enroll_sandbox',$offline))
    ->setActionData([...$input,'api_key'=>'offline-server-key','reason'=>'Exercise unavailable server copy'])
    ->callMountedAction()->assertHasNoActionErrors()->assertSet('mountedActions',[]);
$sent=session()->get('filament.claimed_notifications',[]);$notice=$sent?end($sent):null;
same("Couldn't save",$notice['title']??null,'Unavailable server refusal title');expect(str_contains($notice['body']??'','No key check was sent and nothing was saved.'),'Unavailable server maps its preflight refusal');
same($n,$GLOBALS['enrollmentCalls'],'Unavailable server sends no OTE request');unset($offlinePage);gc_collect_cycles();
$preflight=$fresh();$n=$GLOBALS['enrollmentCalls'];$writesMock=Mockery::mock($writes)->makePartial();
$writesMock->shouldReceive('run')->once()->andThrow(new RuntimeException('unclassified enrollment failure'));
app()->instance(StaffSettingsWrites::class,$writesMock);
$preflightPage=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$preflight->id])->mountAction($action('enroll_sandbox',$preflight))
    ->setActionData([...$input,'api_key'=>'preflight-key','reason'=>'Exercise uncertain preflight copy'])
    ->callMountedAction()->assertHasNoActionErrors()->assertSet('mountedActions',[]);
$sent=session()->get('filament.claimed_notifications',[]);$notice=$sent?end($sent):null;
same("Couldn't confirm the connection",$notice['title']??null,'Preflight outcome title');expect(str_contains($notice['body']??'','We can’t tell if a key check was sent'),'Preflight fallback does not claim a request was sent');
same('We can’t tell if a key check was sent or if the account saved. Open the account page before you try again. Orders paused: it saved, so check the label is yours. Not connected and not set up yet: nothing saved. Off in Paymenter: turn it on in Servers first. Anything else: don’t try again here. Ask your site admin. Trying again may send another key check.',$notice['body']??null,'Generic uncertainty copy distinguishes each reload state');
same($n,$GLOBALS['enrollmentCalls'],'Preflight failure sends no OTE request');same(0,Account::where('server_id',$preflight->id)->count(),'Preflight failure saves no enrollment');unset($preflightPage);gc_collect_cycles();app()->instance(StaffSettingsWrites::class,$writes);
$usedReviewServer=$fresh();$usedTicket=Review::ticket($staff->id,$usedReviewServer->id,null,'enroll_sandbox',Review::stamp($usedReviewServer->id,null));
(new Review($staff->id,$usedReviewServer->id,null,'enroll_sandbox',$usedTicket,'Consume review for recovery copy'))->local(fn()=>null);
$n=$GLOBALS['enrollmentCalls'];$usedReviewPage=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$usedReviewServer->id])->mountAction($action('enroll_sandbox',$usedReviewServer))
    ->setActionData([...$input,'api_key'=>'used-review-key','reason'=>'Exercise used review copy','review_key'=>$usedTicket])
    ->callMountedAction()->assertHasNoActionErrors()->assertSet('mountedActions',[]);
$sent=session()->get('filament.claimed_notifications',[]);$notice=$sent?end($sent):null;
same("Couldn't save",$notice['title']??null,'Used review refusal title');expect(str_contains($notice['body']??'','An earlier try may still be running, and this page can’t show that. Wait for it to finish.'),'Used review copy delays a second request');
expect(str_contains($notice['body']??'','check the label is yours.'),'Used review distinguishes an earlier enrollment label');
expect(str_contains($notice['body']??'','Not connected and not set up yet: nothing saved.'),'Used review says what an empty account means');
same($n,$GLOBALS['enrollmentCalls'],'Used review sends no additional OTE request');same(0,Account::where('server_id',$usedReviewServer->id)->count(),'Used review creates no binding');unset($usedReviewPage);gc_collect_cycles();
$unknownBody='NameSilo accepted the key, but we can’t tell if the account saved. Open the account page before you try again. Orders paused: it saved, so check the label is yours. Not connected and not set up yet: nothing saved. Off in Paymenter: turn it on in Servers first. Anything else: don’t try again here. Ask your site admin. Trying again may send another key check.';
$n=$GLOBALS['enrollmentCalls'];$uncertainPage=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$uncertain->id])->mountAction($action('enroll_sandbox',$uncertain))
    ->setActionData([...$input,'api_key'=>'uncertain-save-key','reason'=>'Exercise uncertain save copy'])
    ->callMountedAction()->assertHasNoActionErrors()->assertSet('mountedActions',[]);
$sent=session()->get('filament.claimed_notifications',[]);$notice=$sent?end($sent):null;
same("Couldn't confirm the connection",$notice['title']??null,'Enrollment outcome notification');same($unknownBody,$notice['body']??null,'Uncertain save copy');
unset($uncertainPage);gc_collect_cycles();
unset($GLOBALS['enrollmentDuringRead']);same($n+1,$GLOBALS['enrollmentCalls'],'Uncertain save follows one accepted OTE key check');
same(0,Account::where('server_id',$uncertain->id)->count(),'Failed transaction leaves no enrollment');DB::table('domains_runtime_turns')->where('server_id',$uncertain->id)->delete();

$enrolledServer=$s;$guardStart=$GLOBALS['enrollmentCalls'];

foreach([['api_key'=>null],['api_key'=>123],['api_key'=>str_repeat('x',257)],['api_key'=>'a key'],['api_key'=>"bad\x7fkey"],['api_key'=>'candidate-ote-key','contact_id'=>'789'],[]] as $credentials){$refuse(fn()=>NameSiloSandbox::facts($s->id,'sandbox@example.test',$credentials),'Exact credential shape');}
$refuse(fn()=>NameSiloSandbox::facts($s->id,"bad\x7flabel",['api_key'=>'candidate-ote-key']),'Label control');
$refuse(fn()=>NameSiloSandbox::facts($s->id,"bad\u{0085}label",['api_key'=>'candidate-ote-key']),'Label Unicode control');
$refuse(fn()=>NameSiloSandbox::facts($s->id,"bad\u{2028}label",['api_key'=>'candidate-ote-key']),'Label Unicode line separator');
$refuse(fn()=>NameSiloSandbox::facts($s->id,"bad\u{2029}label",['api_key'=>'candidate-ote-key']),'Label Unicode paragraph separator');
$refuse(fn()=>NameSiloSandbox::facts($s->id,"bad\xfflabel",['api_key'=>'candidate-ote-key']),'Label UTF-8');
$refuse(fn()=>NameSiloSandbox::facts($s->id,'candidate-ote-key',['api_key'=>'candidate-ote-key']),'Label cannot expose the key');
// Refusals happen before any wire attempt; labels/contact/order/key are never provider identity.
$invalid=$fresh();
foreach(['live','sandbox','','synthetic-ote-key'] as $environment){$n=$GLOBALS['enrollmentCalls'];$refuse(fn()=>$attempt(['environment'=>$environment],$invalid),'OTE-only');same($n,$GLOBALS['enrollmentCalls'],'Wrong environment sends nothing');}
foreach(['confirmed'=>false,'account_id'=>'123','contact_id'=>'789','order_number'=>'999','stable_identity'=>'sandbox@example.test','credentials'=>['api_key'=>'synthetic-ote-key']] as $key=>$value){
    $n=$GLOBALS['enrollmentCalls'];$refuse(fn()=>$attempt([$key=>$value],$invalid),'Explicit contract and no identity substitutes');same($n,$GLOBALS['enrollmentCalls'],'Substitute sends nothing');
}
foreach([null,false,123,['unexpected'],'',' synthetic label',str_repeat('x',129),"bad\nlabel",'candidate-ote-key'] as $label){$refuse(fn()=>$attempt(['label'=>$label],$invalid),'Bounded label');}
foreach(['','a key',str_repeat('x',257)] as $key){$refuse(fn()=>$attempt(['api_key'=>$key],$invalid),'Bounded key');}
$s=$fresh();config(['domains.namesilo_sandbox_enrollment.enabled'=>false]);expect(Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::make($s->id,null,Review::stamp($s->id,null),true)[0]->isDisabled(),'Enrollment UI off');$refuse(fn()=>$attempt([],$s),'Deployment disabled');same($guardStart,$GLOBALS['enrollmentCalls'],'Input guards send nothing');
config(['domains.namesilo_sandbox_enrollment.enabled'=>true]);
$GLOBALS['enrollmentExpectedTimeout']=10000;
$s=$fresh();foreach(['ok','html','wrong_operation','missing_balance','bad_balance','null_balance','throw'] as $mode){
    $GLOBALS['enrollmentMode']=$mode;$n=$GLOBALS['enrollmentCalls'];
    $refuse(fn()=>$attempt($mode==='ok'?['api_key'=>'wrong-key']:[],$s),'Wrong key or unusable reply');
    same($n+1,$GLOBALS['enrollmentCalls'],'One attempt and no retry');same(0,Account::where('server_id',$s->id)->count(),'Failed check saves no account');
    same(0,$s->settings()->whereIn('key',[NameSiloSandbox::KEY,'domains_credentials','domains_verified_credentials'])->count(),'Failed check saves no secrets/facts');
}
// A fresh binding must not inherit orphaned ledger/catalogue rows or saved acceptance.
$retained=$fresh();foreach(['domains_domains','domains_tlds','domains_prices','domains_operations','domains_registrar_balances','domains_runtime_turns'] as $table){
    $row=['server_id'=>$retained->id,'registrar_key'=>'namesilo','account_fingerprint'=>str_repeat('a',64)];
    $row+=match($table){
        'domains_domains'=>['service_id'=>9000+$retained->id,'user_id'=>1,'domain'=>'retained.example.test','sld'=>'retained','tld'=>'example.test','status'=>'active'],
        'domains_tlds'=>['tld'=>'example.test'],
        'domains_prices'=>['tld'=>'example.test','action'=>'register','term'=>1,'currency'=>'USD','unit'=>'year','source'=>'synthetic','observed_at'=>'2026-01-01 00:00:00'],
        'domains_operations'=>['domain_id'=>9000,'service_id'=>9000,'operation_key'=>'orphan-'.$retained->id,'action'=>'register','state'=>'held','currency'=>'USD'],
        'domains_registrar_balances'=>[],
        'domains_runtime_turns'=>[],
    };
    if(in_array($table,['domains_registrar_balances','domains_runtime_turns'],true)){unset($row['registrar_key']);}
    DB::table($table)->insert($row);$n=$GLOBALS['enrollmentCalls'];$refuse(fn()=>$attempt([],$retained),'Retained '.$table);same($n,$GLOBALS['enrollmentCalls'],'Retained data refuses before read');DB::table($table)->where('server_id',$retained->id)->delete();
}
$retained=$fresh();$retained->settings()->create(['key'=>'domains_catalogue_acceptance','value'=>'retained acceptance','encrypted'=>true,'type'=>'string']);
$n=$GLOBALS['enrollmentCalls'];$refuse(fn()=>$attempt([],$retained),'Retained acceptance');same($n,$GLOBALS['enrollmentCalls'],'Retained acceptance sends nothing');
$s=$enrolledServer;$GLOBALS['enrollmentMode']='ok';
// Model and service guards independently refuse rotation, relabeling, synthetic rebinding and promotion.
$n=$GLOBALS['enrollmentCalls'];$before=$a->getRawOriginal();
$refuse(fn()=>$attempt(['label'=>'another label','api_key'=>'rotated-key'],$s),'New enrollment required for rotation');
foreach(['test','live'] as $environment){
    $refuse(fn()=>$bindings->configure($s->id,'namesilo',Environment::from($environment),['api_key'=>'rotated-key'],$review($s,'configure')),'No enrollment rebind','Sandbox enrollment is immutable.');
}
config(['app.url'=>'https://sandbox.example.test']);$refuse(fn()=>$bindings->confirmLiveSite($s->id,'https://sandbox.example.test'),'No live site binding');
foreach(['stable_identity'=>'real-account','environment'=>'live','server_id'=>$fresh()->id] as $key=>$value){$refuse(fn()=>$a->fresh()->update([$key=>$value]),'Immutable model');}
same($n,$GLOBALS['enrollmentCalls'],'Rotation and promotion send nothing');same($before,$a->fresh()->getRawOriginal(),'Old enrollment retained unchanged');
// Future provider-confirmed seam cannot return a reserved local enrollment identity.
$fakeRegistry=new RegistrarRegistry;RegistrarFactories::install($fakeRegistry,null,new class($a->stable_identity) implements AccountVerifier {
    public function __construct(private string $identity){}public function verify(Environment $e,#[SensitiveParameter] array $c):string{return $this->identity;}
});
$refuse(fn()=>$fakeRegistry->verify('namesilo',$s->id,Environment::Test,['api_key'=>'synthetic-ote-key']),'Reserved local identity cannot be provider proof');
// Existing synthetic binding cannot be adopted. Live identity refusal is unchanged and makes no call.
$synthetic=$fresh();Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$synthetic->id,'registrar_key'=>'namesilo','environment'=>'test','stable_identity'=>'synthetic-account']));
$refuse(fn()=>$attempt([],$synthetic),'No synthetic to enrolled rebind');
$legacy=$fresh();$legacyCredentials=['api_key'=>'synthetic-legacy-key'];$legacyBinding=new AccountBinding('namesilo',$legacy->id,Environment::Test,'legacy-account');
Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$legacy->id,'registrar_key'=>'namesilo','environment'=>'test','stable_identity'=>'legacy-account']));
$legacy->settings()->create(['key'=>'domains_credentials','encrypted'=>true,'type'=>'string','value'=>json_encode($legacyCredentials,JSON_THROW_ON_ERROR)]);
$legacy->settings()->create(['key'=>'domains_verified_credentials','encrypted'=>true,'type'=>'string','value'=>hash_hmac('sha256',$legacyBinding->fingerprint.json_encode($legacyCredentials,JSON_THROW_ON_ERROR),(string)config('app.key'))]);
expect($bindings->configurationAvailable($legacy->id),'Legacy local settings can match their stored digest');$n=$GLOBALS['enrollmentCalls'];
$refuse(fn()=>$bindings->reader($legacy->id,true),'Legacy synthetic NameSilo binding cannot use the OTE balance reader');same($n,$GLOBALS['enrollmentCalls'],'Legacy binding refuses without an OTE call');
$legacyStatus=Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::status($legacy->fresh(),Account::where('server_id',$legacy->id)->sole());expect(!str_contains(implode(' ',$legacyStatus),'Verified'),'Legacy NameSilo is never described as verified');
$corrupt=$fresh();Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$corrupt->id,'registrar_key'=>'namesilo','environment'=>'test','stable_identity'=>NameSiloSandbox::PREFIX.'corrupt']));
DB::table('domains_accounts')->where('server_id',$corrupt->id)->update(['stable_identity'=>'corrupt-ciphertext']);$corruptAccount=Account::where('server_id',$corrupt->id)->sole();
same(false,NameSiloSandbox::enrolled($corruptAccount),'Undecryptable identity fails closed as not enrolled');
$corruptStatus=Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::status($corrupt->fresh(),$corruptAccount);
same(['Connection failed','Could not load account settings'],[$corruptStatus['label'],$corruptStatus['reason']],'Corrupt retained identity reports settings failure without assuming credential rotation');
$corruptEnrollment=$attempt(['api_key'=>'corrupt-marker-key']);$corruptEnrollmentAccount=Account::where('server_id',$corruptEnrollment->id)->sole();
$savedIdentity=$corruptEnrollmentAccount->getRawOriginal('stable_identity');
DB::table('domains_accounts')->where('server_id',$corruptEnrollment->id)->update(['stable_identity'=>'corrupt-ciphertext']);
$unreadableKeyRetry=$fresh();$n=$GLOBALS['enrollmentCalls'];
$refuse(fn()=>$attempt(['api_key'=>'corrupt-marker-key'],$unreadableKeyRetry),'Unreadable saved enrollment blocks key reuse','Existing sandbox enrollment unavailable.');
same($n,$GLOBALS['enrollmentCalls'],'Unreadable saved enrollment blocks duplicate key before OTE');same(0,Account::where('server_id',$unreadableKeyRetry->id)->count(),'Unreadable saved enrollment creates no second binding');
DB::table('domains_accounts')->where('server_id',$corruptEnrollment->id)->update(['stable_identity'=>$savedIdentity]);
$live=$fresh();try{$bindings->configure($live->id,'namesilo',Environment::Live,['api_key'=>'synthetic-ote-key'],$review($live,'configure'));expect(false,'Live must refuse');}
catch(IdentityRefused $e){same('namesilo_identity_unavailable',$e->reason,'Existing live refusal unchanged');}
same($n,$GLOBALS['enrollmentCalls'],'Live sends nothing');
// A positive future verifier still cannot adopt copied local enrollment facts.
$future=$fresh();$futureRegistry=new RegistrarRegistry;RegistrarFactories::install($futureRegistry,null,new class implements AccountVerifier {public function verify(Environment $e,#[SensitiveParameter] array $c):string{return 'future-provider-account';}});
$futureBindings=new AccountBindings($futureRegistry);$futureBindings->configure($future->id,'namesilo',Environment::Test,['api_key'=>'future-key']);expect($futureBindings->configurationAvailable($future->id),'Synthetic future positive seam');
$future->settings()->create(['key'=>NameSiloSandbox::KEY,'encrypted'=>true,'type'=>'string','value'=>json_encode($facts)]);same(false,$futureBindings->configurationAvailable($future->id),'Provider identity cannot inherit enrollment facts');
$future->settings()->where('key',NameSiloSandbox::KEY)->delete();
// Copying encrypted facts or credentials to another server cannot inherit the old binding.
$copy=$fresh();foreach($s->settings()->whereIn('key',[NameSiloSandbox::KEY,'domains_credentials','domains_verified_credentials'])->get() as $setting){$copy->settings()->create(['key'=>$setting->key,'encrypted'=>true,'type'=>'string','value'=>$setting->value]);}
$refuse(fn()=>$attempt([],$copy),'Copied settings not a fresh enrollment');same($n,$GLOBALS['enrollmentCalls'],'Copies refused before read');
$refuse(fn()=>Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$copy->id,'registrar_key'=>'namesilo','environment'=>'live','stable_identity'=>$a->stable_identity])),'Reserved identity cannot be live');
$refuse(fn()=>Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$copy->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>$a->stable_identity])),'Reserved identity cannot change registrar');
// Raw database tampering is not trusted: local configuration validates the immutable anchor and server/key.
$setting=$s->settings()->where('key',NameSiloSandbox::KEY)->sole();$original=$setting->value;
foreach(['label'=>'edited label','key_fingerprint'=>hash('sha256','rotated-key'),'server_id'=>$copy->id,'provider_confirmed_account'=>true,'authenticated_ote_key'=>false,'contract'=>'provider_confirmed'] as $key=>$value){
    $edited=$facts;$edited[$key]=$value;$setting->value=json_encode($edited);$setting->save();
    same(false,$bindings->configurationAvailable($s->id),'Edited facts refuse reads: '.$key);$refuse(fn()=>$bindings->reader($s->id,true),'Edited facts guard');
}
$n=$GLOBALS['enrollmentCalls'];$refuse(fn()=>$attempt([]),'Unreadable retained enrollment blocks new keys');same($n,$GLOBALS['enrollmentCalls'],'Unavailable original sends nothing');
$setting->value=$original;$setting->save();expect($bindings->configurationAvailable($s->id),'Original enrollment reads');
$credential=$s->settings()->where('key','domains_credentials')->sole();$saved=$credential->value;$credential->value=json_encode(['api_key'=>'rotated-key']);$credential->save();
same(false,$bindings->configurationAvailable($s->id),'Unreviewed key rotation refuses');$credential->value=$saved;$credential->save();
config(['domains.namesilo_sandbox_enrollment.enabled'=>false]);same(false,$bindings->configurationAvailable($s->id),'Disabling enrollment fails closed');config(['domains.namesilo_sandbox_enrollment.enabled'=>true]);
$registry=app(RegistrarRegistry::class);config(['domains.namesilo_sandbox_enrollment.enabled'=>false]);$refuse(fn()=>$registry->reader($binding,['api_key'=>'synthetic-ote-key'],true),'Direct balance factory deployment guard');config(['domains.namesilo_sandbox_enrollment.enabled'=>true]);
$liveBinding=new AccountBinding('namesilo',$s->id,Environment::Live,$a->stable_identity);$refuse(fn()=>$registry->reader($liveBinding,['api_key'=>'synthetic-ote-key'],true),'Direct balance factory environment guard');
// Validate source/environment/key claims independently of the facts anchor, including forged local objects.
foreach(['contract'=>'provider_confirmed','server_id'=>$copy->id,'provider_confirmed_account'=>true,'authenticated_ote_key'=>false,'key_fingerprint'=>hash('sha256','rotated-key')] as $key=>$value){
    $forged=$facts;$forged[$key]=$value;$model=clone $a;$model->stable_identity=NameSiloSandbox::identity($forged);
    $refuse(fn()=>NameSiloSandbox::validate($model,$forged,['api_key'=>'synthetic-ote-key']),'Untrusted enrollment claim '.$key);
}
$model=clone $a;$model->registrar_key='enom';$refuse(fn()=>NameSiloSandbox::validate($model,$facts,['api_key'=>'synthetic-ote-key']),'Wrong registrar enrollment');
$model=clone $a;$model->environment='live';$refuse(fn()=>NameSiloSandbox::validate($model,$facts,['api_key'=>'synthetic-ote-key']),'Wrong environment enrollment');
$refuse(fn()=>$bindings->enrollSandbox($fresh()->id,Environment::Test,'sandbox@example.test',['api_key'=>'candidate-ote-key'],$review($s)),'Review server mismatch');
$reviewTarget=$fresh();$refuse(fn()=>$bindings->enrollSandbox($reviewTarget->id,Environment::Test,'sandbox@example.test',['api_key'=>'candidate-ote-key'],$review($reviewTarget,'configure')),'Review action mismatch');
$refuse(fn()=>DB::transaction(fn()=>$bindings->enrollSandbox($s->id,Environment::Test,'sandbox@example.test',['api_key'=>'candidate-ote-key'],$review($s))),'No outer transaction');
$reviewTarget=$fresh();$n=$GLOBALS['enrollmentCalls'];$invalidReview=new Review($staff->id,$reviewTarget->id,null,'enroll_sandbox','invalid ticket','Review sandbox enrollment');
$refuse(fn()=>$bindings->enrollSandbox($reviewTarget->id,Environment::Test,'sandbox@example.test',['api_key'=>'candidate-ote-key'],$invalidReview),'Review required before read');same($n,$GLOBALS['enrollmentCalls'],'Invalid review sends nothing');
// Recheck permission and fresh deployment/state after the read before saving.
$race=$fresh();$GLOBALS['enrollmentDuringRead']=static fn()=>$race->update(['name'=>'Changed during key check']);
$refuse(fn()=>$attempt([],$race),'Reviewed native server changed in flight');unset($GLOBALS['enrollmentDuringRead']);same(0,Account::where('server_id',$race->id)->count(),'Final review snapshot check');
$race=$fresh();$GLOBALS['enrollmentDuringRead']=static fn()=>config(['domains.namesilo_sandbox_enrollment.enabled'=>false]);
$refuse(fn()=>$attempt([],$race),'Deployment changed in flight');unset($GLOBALS['enrollmentDuringRead']);config(['domains.namesilo_sandbox_enrollment.enabled'=>true]);same(0,Account::where('server_id',$race->id)->count(),'No save after deployment revoke');
$race=$fresh();$GLOBALS['enrollmentDuringRead']=static function()use($staff):void {$staff->role->update(['permissions'=>['admin.domains.view']]);};
$refuse(fn()=>$attempt([],$race),'Permission changed in flight');unset($GLOBALS['enrollmentDuringRead']);$staff->role->update(['permissions'=>array_keys(Staff::permissions())]);same(0,Account::where('server_id',$race->id)->count(),'No save after permission revoke');
$race=$fresh();$GLOBALS['enrollmentDuringRead']=static function()use($race):void {DB::table('domains_runtime_turns')->insert(['server_id'=>$race->id,'account_fingerprint'=>str_repeat('a',64)]);};
$refuse(fn()=>$attempt([],$race),'Retained state appeared in flight');unset($GLOBALS['enrollmentDuringRead']);same(0,Account::where('server_id',$race->id)->count(),'Final freshness check');DB::table('domains_runtime_turns')->where('server_id',$race->id)->delete();
$race=$fresh();$GLOBALS['enrollmentDuringRead']=static function()use($attempt):void {unset($GLOBALS['enrollmentDuringRead']);$attempt(['api_key'=>'racing-key']);};
$refuse(fn()=>$attempt(['api_key'=>'racing-key'],$race),'Duplicate key enrolled in flight');unset($GLOBALS['enrollmentDuringRead']);same(0,Account::where('server_id',$race->id)->count(),'Final key uniqueness check');
$s->update(['enabled'=>false]);$n=$GLOBALS['enrollmentCalls'];$refuse(fn()=>$attempt(['api_key'=>'synthetic-ote-key']),'Disabled original retains its key');same($n,$GLOBALS['enrollmentCalls'],'Disabled original sends nothing');$replacement=$attempt(['api_key'=>'rotated-key']);$s->update(['enabled'=>true]);
$newAccount=Account::where('server_id',$replacement->id)->sole();expect($newAccount->account_fingerprint!==$binding->fingerprint,'Rotated key creates independent enrollment');same(false,$newAccount->processing_enabled,'Replacement processing off');
$refuse(fn()=>CatalogueAcceptances::load(CatalogueAcceptances::binding($newAccount)),'Replacement inherits no acceptance');
foreach(Review::historyRows($s->fresh()) as $row){$json=json_encode($row);foreach(['sandbox@example.test','synthetic-ote-key','9876.54','key_fingerprint'] as $secret){expect(!str_contains($json,$secret),'Audit omits label/key/balance');}}
echo "NameSilo OTE enrollment: immutable, explicitly weaker, live refused, redacted; real provider calls: 0.\n";
