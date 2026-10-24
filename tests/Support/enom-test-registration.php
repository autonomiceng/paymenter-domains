<?php
// Installed TEST deployment, socket-free cURL; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,Cache,DB,Queue};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,DispatchSeal,Environment,ExecutionRecord,HistoryAcceptance,Query,RequestOrigin,SendState};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,EnomTestCalls,EnomTestCallLimit,EnomTestCallSchema,EnomTestRegistration,Installation,NativeCatalogue,ObservationWork,Runtime,RuntimePorts,RuntimeWork,StaffSettingsReview as Review,StaffSettingsWrites,Storefront};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
require __DIR__.'/enom-test-registration-fixture.php';
try{$write('acceptance',['ending'=>'com','accept'=>true,'terms'=>[1],'registry'=>true,'verified'=>true]);throw new LogicException('TEST route/interval acceptance must be recorded.');}
catch(DomainException $error){expect(str_contains($error->getMessage(),'Purchase route'),'Staff catalogue acceptance requires explicit TEST protocol review');}
$setupAction=setupAction('acceptance',$server->id);
Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])->mountAction($setupAction)
    ->assertSchemaComponentExists('test_registration_accepted',checkComponentUsing:fn($field)=>$field->getLabel()==='I reviewed Enom TEST Purchase at https://resellertest.enom.com/interface.asp and its 25-second request interval')
    ->setActionData(['ending'=>'com','accept'=>true,'terms'=>['1'],'registry'=>true,'verified'=>true,'reason'=>'Synthetic unticked TEST route review'])->callMountedAction()
    ->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't save")->body('Check the box confirming the Enom TEST Purchase route and request interval, then try again. Nothing was saved.'));
same('Enom TEST Purchase acceptance is unavailable on this deployment; contact the deployment administrator. Nothing was saved.',Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::refusal(new DomainException('Enom TEST Purchase route acceptance is unavailable.'),'acceptance'),'Unavailable TEST route acceptance names the deployment administrator');
try{CatalogueAcceptances::load($binding);throw new LogicException('Refused TEST route review must save nothing.');}catch(DomainException){}
config(['domains.enom_writes.registration'=>false]);$write('acceptance',['ending'=>'com','accept'=>true,'terms'=>[1],'registry'=>true,'verified'=>true]);
$routeFact=Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::acceptance($a->fresh());expect(str_starts_with($routeFact,'Revision ')&&!str_contains($routeFact,'Enom TEST Purchase route'),'No unaccepted TEST route warning without the deployment opt-in');
config(['domains.enom_writes.registration'=>true]);same($routeFact.' · Enom TEST Purchase route and interval not accepted',Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::acceptance($a->fresh()),'Opted-in deployment warns about unaccepted TEST route');
config(['domains.enom_catalogue.enabled'=>false]);same($routeFact,Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::acceptance($a->fresh()),'No unaccepted TEST route warning while the TEST catalogue is off');config(['domains.enom_catalogue.enabled'=>true]);
config(['domains.enom_identity.environment'=>'live']);same($routeFact,Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::acceptance($a->fresh()),'No unaccepted TEST route warning for a live identity');config(['domains.enom_identity.environment'=>'test']);
$write('acceptance',['ending'=>'com','accept'=>true,'terms'=>[1],'registry'=>true,'verified'=>true,'test_registration_accepted'=>true]);
$accepted=CatalogueAcceptances::load($binding);
same('https://resellertest.enom.com/interface.asp',$accepted->registrationRoute,'Existing staff catalogue acceptance records the TEST Purchase route');
same(25,$accepted->registrationIntervalSeconds,'Existing staff catalogue acceptance records the worker request interval');
$write('default_rule',['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none']);
$write('import',['ending'=>'com']);$tld=Tld::where('server_id',$server->id)->sole();
$write('tld_settings',['enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true],$tld->id);
$write('publish',[],$tld->id);$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$tld->id);
same(7,count($GLOBALS['enomWire']['forms']),'Identity, probe and annual import total seven reads');same(7,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Every setup call counted');same(50,EnomTestCalls::limit(),'Default cap fifty');
same('7 / 50 used today (UTC), shared by all Enom TEST accounts. Every reserved request counts, including ones that failed, timed out or were stopped before sending.',EnomTestCalls::facts(),'Staff sees current shared TEST call usage');
same(EnomTestCalls::facts().' Calls remain today, so a counter error or UTC day change refused it; you can retry.',EnomTestCalls::refusedFacts(),'Refusal facts explain a refusal while calls remain');
 $catalogueUsed=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');config(['domains.enom_test_daily_call_cap'=>$catalogueUsed+1]);$beforeImport=count($GLOBALS['enomWire']['forms']);
try{$write('import',['ending'=>'com']);throw new LogicException('A mid-import TEST cap refusal must reach staff.');}catch(EnomTestCallLimit){}
same($catalogueUsed+1,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Mid-import cap leaves its earlier read counted');
same($beforeImport+1,count($GLOBALS['enomWire']['forms']),'Mid-import cap stops before the next catalogue request');config(['domains.enom_test_daily_call_cap'=>50]);
expect(EnomTestCalls::callsRemain(),'Counter reports available calls below the configured limit');config(['domains.enom_test_daily_call_cap'=>7]);
expect(!EnomTestCalls::callsRemain(),'Counter reports no calls at the configured limit');config(['domains.enom_test_daily_call_cap'=>50]);
same('Enom TEST daily call limit, counter, or UTC day change refused the send.',(new EnomTestCallLimit)->getMessage(),'Internal refusal names the daily cap and rollover');
EnomTestCalls::reserve(Environment::Live);same($catalogueUsed+1,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Live requests do not use the TEST counter');
$reader=$bindings->reader($server->id,true);$generalReader=$bindings->reader($server->id);
$readContext=new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Interactive,
    $clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(4),$clock,Paymenter\Extensions\Servers\Domains\Services\Runtime::limiter());
expect($generalReader instanceof Paymenter\Extensions\Servers\Domains\Registrars\Enom\EnomTestReconciliationReader,'Opted-in general reads use the narrow reconciliation wrapper');
$historyNow=$clock->now();$history=new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'enom_registration_v1');
$historyAttempt=new AttemptEvidence($binding,new Query('history-only-placeholder.com',Action::Register,1),'history-only',1,ExecutionRecord::RecordedNull,SendState::PossiblySent,
    $historyNow->modify('-60 seconds'),$historyNow->modify('-1 second'),seal:DispatchSeal::Sealed,historyAcceptance:$history);
expect($history->covers($historyAttempt,$historyNow),'Regression fixture carries valid pre-existing Enom history acceptance');$beforeHistory=count($GLOBALS['enomWire']['forms']);
$historyContext=new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(20),$clock,Paymenter\Extensions\Servers\Domains\Services\Runtime::limiter());
try{$generalReader->reconcile($historyAttempt,$historyContext);throw new LogicException('TEST reconciliation cannot query history without a captured OrderID.');}
catch(DomainException $error){same('Captured TEST Purchase OrderID is required.',$error->getMessage(),'History acceptance cannot satisfy captured-ID reconciliation');}
same($beforeHistory,count($GLOBALS['enomWire']['forms']),'History acceptance cannot open the TEST reconciliation history path');
$closedQuery=new Paymenter\Extensions\Servers\Domains\Data\Query('closed-poll.example',Action::Register,1);$beforeClosed=count($GLOBALS['enomWire']['forms']);
foreach([fn()=>$generalReader->quote($closedQuery,$readContext),fn()=>$generalReader->readDomain('closed-poll.example',$readContext),
    fn()=>$generalReader->renewalAssessment($closedQuery,$readContext)] as $closedRead){try{$closedRead();throw new LogicException('General TEST reader exposed a reconciliation-only method.');}catch(DomainException){}}
same($beforeClosed,count($GLOBALS['enomWire']['forms']),'General TEST reader blocks domain and renewal polling before Enom');
$GLOBALS['enomWire']['execFail']=true;$beforeFailedSend=count($GLOBALS['enomWire']['forms']);$beforeFailedReservation=(int)DB::table(EnomTestCallSchema::TABLE)->value('used');$reader->balance($readContext);$GLOBALS['enomWire']['execFail']=false;
same($beforeFailedSend+1,count($GLOBALS['enomWire']['forms']),'Failed synthetic send entered the transport once');same($beforeFailedReservation+1,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Failed send reservation remains spent');
$savedClock=$clock->at;$clock->at=new DateTimeImmutable('2026-10-21T23:59:59Z');$rollDay=EnomTestCalls::day();
$rollUsed=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',$rollDay)->value('used');$crossed=false;
DB::listen(static function($query)use(&$crossed,$clock):void{if(!$crossed&&str_contains($query->sql,EnomTestCallSchema::TABLE)){$crossed=true;$clock->at=$clock->at->modify('+1 second');}});
$beforeIdentity=count($GLOBALS['enomWire']['forms']);try{app(Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry::class)->verify('enom',$server->id,Environment::Test,['uid'=>'test-uid','token'=>'TEST-SECRET-TOKEN']);throw new LogicException('Identity rollover must keep its typed local refusal.');}catch(EnomTestCallLimit){}
same($rollUsed+1,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',$rollDay)->value('used'),'Rollover identity reservation remains charged to its original day');
same($beforeIdentity,count($GLOBALS['enomWire']['forms']),'Identity UTC rollover stops before curl_exec');$clock->at=$savedClock;Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());

$previewUser=commerceUser();$previewProduct=app(NativeCatalogue::class)->product($tld,'register');$previewCart=commerceCart($previewUser);commerceAdd($previewCart,$previewProduct,placeholderComFixtureName('preview-optout-placeholder.com'));
config(['domains.enom_writes.registration'=>null]);expect(!$ports->accepted($a),'Missing deployment write key keeps A4 preview-only behavior');
$beforeOrders=App\Models\Order::count();$beforeInvoices=App\Models\Invoice::count();$beforeTransactions=App\Models\InvoiceTransaction::count();$beforeCalls=count($GLOBALS['enomWire']['forms']);
commerceCheckout($previewUser,$previewCart)->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>'Test domains can’t be ordered. Remove them to check out.','type'=>'error']);
same($beforeOrders,App\Models\Order::count(),'Opt-out checkout creates no order');same($beforeInvoices,App\Models\Invoice::count(),'Opt-out checkout creates no invoice');same($beforeTransactions,App\Models\InvoiceTransaction::count(),'Opt-out checkout creates no payment');same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Opt-out checkout makes no Enom calls');
config(['domains.enom_writes.registration'=>true,'domains.enom_identity.environment'=>'live']);
$beforeOrders=App\Models\Order::count();$beforeInvoices=App\Models\Invoice::count();$beforeTransactions=App\Models\InvoiceTransaction::count();$beforeCalls=count($GLOBALS['enomWire']['forms']);
commerceCheckout($previewUser,$previewCart)->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>'Test domains can’t be ordered. Remove them to check out.','type'=>'error']);
same($beforeOrders,App\Models\Order::count(),'Changing identity mode to live cannot reopen accepted TEST checkout');same($beforeInvoices,App\Models\Invoice::count(),'Identity-mode mismatch creates no invoice');same($beforeTransactions,App\Models\InvoiceTransaction::count(),'Identity-mode mismatch creates no payment');same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Identity-mode mismatch makes no Enom calls');
config(['domains.enom_identity.environment'=>'test']);
config(['domains.enom_writes.registration'=>true]);
$corrupt=clone $a;$corrupt->stable_identity='changed-identity';expect(!$ports->registered($corrupt),'Mismatched identity reports unregistered instead of throwing');
$deny=static function(callable $work):void {try{$work();throw new LogicException('Expected refusal');}catch(DomainException) {}};
foreach([false,'true'] as $off){config(['domains.enom_writes.registration'=>$off]);expect(!$ports->accepted($a),'Only boolean true enables composition');}
config(['domains.enom_writes.registration'=>true]);
foreach([null,'live'] as $environment){config(['domains.enom_identity.environment'=>$environment]);expect(!$ports->accepted($a),'Live/unset refuses composition');$deny(fn()=>EnomTestRegistration::factory($binding));}
config(['domains.enom_writes.registration'=>true,'domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>false]);expect(!$ports->accepted($a),'Catalogue opt-in mandatory');
same('TEST registration refused: the deployment TEST catalogue is off.',EnomTestRegistration::reason(),'Staff can identify a disabled TEST catalogue');config(['domains.enom_catalogue.enabled'=>true]);
config(['domains.enom_identity.environment'=>null]);same('TEST registration refused: the deployment identity environment is not test. Live and unset environments cannot use TEST registration.',EnomTestRegistration::reason(),'Staff can identify a missing TEST identity environment');config(['domains.enom_identity.environment'=>'test']);
same('Deployment allows TEST registration. Catalogue acceptance must include the Enom TEST Purchase route and request interval; the account also needs accepted catalogue terms, processing on, and a successful USD balance read (Check balance) under an hour old when the worker runs.',EnomTestRegistration::reason(),'Staff copy describes deployment and evidence prerequisites');
same('Not sent: orders paused',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('processing'),'Processing-off hold directs staff recovery');
same('Not sent: registrar account not set up',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('enom_test_setup_required'),'Setup hold directs staff to actionable checks');
expect(!EnomTestRegistration::enabled(new AccountBinding('enom',999,Environment::Live,'test-uid')),'LIVE fingerprint refuses');
expect(!EnomTestRegistration::enabled(new AccountBinding('namesilo',999,Environment::Test,'test-uid')),'Other registrar refuses');
DB::enableQueryLog();DB::flushQueryLog();$composition=EnomTestRegistration::factory($binding)();same([],DB::getQueryLog(),'Pure factory performs no DB reads');DB::disableQueryLog();
same(['register'=>true,'transfer'=>false],$composition['acquisition']->gates(),'Registration only');
$features=(new ReflectionProperty($composition['management'],'features'))->getValue($composition['management']);
expect(!$features->renewal&&!$features->management,'Renewal and management remain closed');
foreach([Action::Transfer,Action::Renew] as $action){$deny(fn()=>$features->requireEnabled($action));}
$deny(fn()=>$features->requireManagement());
$renewalFeatures=(new ReflectionProperty($composition['renewal'],'features'))->getValue($composition['renewal']);
expect(!$renewalFeatures->renewal,'Renewal composition keeps its independent write gate off');
expect(!$a->fresh()->processing_enabled,'Composing does not enable processing');
// Existing audited processing surface with real database cache, shared-worker configuration checks.
Illuminate\Support\Facades\Schema::create('cache',static function($t):void {$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration')->index();});
Illuminate\Support\Facades\Schema::create('cache_locks',static function($t):void {$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
config(['cache.default'=>'database','session.driver'=>'database','queue.default'=>'database','queue.connections.database.retry_after'=>900]);Queue::fake();
$user=commerceUser();$user->update(['email'=>'registration-owner@example.com']);
foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
$product=app(NativeCatalogue::class)->product($tld,'register');$front=app(Storefront::class);
$before=count($GLOBALS['enomWire']['forms']);$beforeUsed=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])->assertSee('API calls today')->assertSee(EnomTestCalls::usage()['used'].' of '.EnomTestCalls::usage()['limit'])->assertSeeInOrder(['Setup checklist','Registrations','On','Transfers'])->assertDontSee('TEST-SECRET-TOKEN');$front->endings();same($before,count($GLOBALS['enomWire']['forms']),'Page views make zero calls');same($beforeUsed,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Page views do not reserve TEST calls');
$before=count($GLOBALS['enomWire']['forms']);Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])
    ->callAction('check_balance')->assertNotified('Balance updated');
same($before+1,count($GLOBALS['enomWire']['forms']),'Staff Refresh now makes one counted balance read');
same('GetBalance',$GLOBALS['enomWire']['forms'][array_key_last($GLOBALS['enomWire']['forms'])]['Command'],'Existing staff Refresh now reads the opted-in TEST balance');
$manualBalance=DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->first();
same(['manual','USD',null],[$manualBalance->source,$manualBalance->currency,$manualBalance->last_error_code],'Staff balance action records fresh USD evidence');
same('available',$front->check('paid-test-placeholder.com')['status'],'Explicit search');
$worker=app(RuntimeWork::class);$unpaidUser=commerceUser();$unpaidUser->update(['email'=>'unpaid-owner@example.com']);
foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$unpaidUser->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
$beforeOrders=App\Models\Order::count();$beforeServices=App\Models\Service::count();$beforeInvoices=App\Models\Invoice::count();
$unpaidCart=commerceCart($unpaidUser);commerceAdd($unpaidCart,$product,placeholderComFixtureName('unpaid-test-placeholder.com'));$checkout=commerceCheckout($unpaidUser,$unpaidCart);$checkout->assertHasNoErrors();
same($beforeOrders+1,App\Models\Order::count(),'Opt-in native checkout creates its order');
same($beforeServices+1,App\Models\Service::count(),'Opt-in native checkout creates its service');
same($beforeInvoices+1,App\Models\Invoice::count(),'Opt-in native checkout creates its invoice');
$unpaidDomain=Domain::where('domain','unpaid-test-placeholder.com')->sole();$unpaidOp=$unpaidDomain->operations()->sole();
same('unpaid-test-placeholder.com',$unpaidDomain->reservation_name,'Native checkout creates the domain reservation');
same('awaiting_payment',$unpaidOp->state,'Native checkout creates its awaiting-payment operation');
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($unpaidOp->id);
same($before,count($GLOBALS['enomWire']['forms']),'Unpaid registration makes no Enom requests');expect(!isset($unpaidOp->fresh()->attempt['claim_key']),'Unpaid registration receives no worker claim');
$forged=['phase'=>'preflight','refused_at'=>$clock->now()->format(DATE_ATOM),'dispatch_attempted_at'=>null,'authorized_cost'=>null];
try{$unpaidOp->update(['attempt'=>['test_call_refusals'=>[$forged]]]);throw new LogicException('Refusal history cannot be added outside a cap reset.');}catch(DomainException){}
expect(!isset($unpaidOp->fresh()->attempt['test_call_refusals']),'Unpaid operation retains no forged cap history');Cache::flush();
DB::table('services')->where('id',$unpaidOp->service_id)->update(['status'=>'cancelled']);DB::table('invoices')->where('id',$unpaidOp->invoice_id)->update(['status'=>'cancelled']);
$turn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
if(!$turn){DB::table('domains_runtime_turns')->insert(['server_id'=>$server->id,'account_fingerprint'=>$binding->fingerprint,'operation_cursor'=>0,'domain_cursor'=>0,'tld_cursor'=>0,'turn'=>0,'due_at'=>0,'finished_at'=>0]);$turn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();}
DB::table('domains_runtime_turns')->where('id',$turn->id)->update(['operation_cursor'=>0,'domain_cursor'=>$unpaidDomain->id-1,'due_at'=>0]);
$before=count($GLOBALS['enomWire']['forms']);$usedBefore=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');Cache::flush();Queue::fake();$worker->tick();
same('cancelled',$unpaidDomain->fresh()->status,'Durable TEST domain cursor retires a cancelled unpaid reservation after missed callbacks');
same(null,$unpaidDomain->fresh()->reservation_name,'TEST lifecycle recovery releases the abandoned local name');same(['rejected','acquisition_cancelled_unpaid'],[$unpaidOp->fresh()->state,$unpaidOp->fresh()->safe_code],'Missed callback recovery closes the unpaid operation');
same($before,count($GLOBALS['enomWire']['forms']),'Local TEST lifecycle recovery makes no provider requests');same($usedBefore,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Local TEST lifecycle recovery reserves no provider calls');
$new=static function(string $name)use($user,$product,$clock):Operation {
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $cart=commerceCart($user);commerceAdd($cart,$product,$name);commerceCheckout($user,$cart)->assertHasNoErrors();
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount);
    same('paid',App\Models\Invoice::findOrFail($op->invoice_id)->status,'Native payment marks the invoice paid');
    return $op->fresh();
};
// Default processing off holds a native paid order without opening a provider request.
$paused=$new(placeholderComFixtureName('processing-off-placeholder.com'));$before=count($GLOBALS['enomWire']['forms']);$pausedUsed=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');$worker->operation($paused->id);
same(['held','processing'],[$paused->fresh()->state,$paused->fresh()->safe_code],'Paid TEST order reports the staff processing hold');
same($before,count($GLOBALS['enomWire']['forms']),'Processing-off queued registration sends no Enom request');same($pausedUsed,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Processing-off does not reserve a TEST call');
$write('processing',['enabled'=>true,'confirmed'=>true]);expect($a->fresh()->processing_enabled,'Staff deliberately enabled processing');
$pausedDomain=$paused->domain->fresh();$releaseTicket=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$pausedDomain->id,(int)$paused->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($paused->fresh(),$pausedDomain),$clock);
$before=count($GLOBALS['enomWire']['forms']);app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$pausedDomain->id,(int)$paused->id,'release',$releaseTicket,'Processing is enabled and the unsent registration can be reviewed again');
same('queued',$paused->fresh()->state,'Paid TEST order held before claim can be released after processing is enabled');same($before,count($GLOBALS['enomWire']['forms']),'Staff release sends no TEST request');
$routeHold=$new(placeholderComFixtureName('route-evidence-placeholder.com'));$accepted=CatalogueAcceptances::load($binding);
DB::transaction(fn()=>CatalogueAcceptances::saveLocked(new Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance($binding,$accepted->revision,$accepted->endings)));
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($routeHold->id);
same(['held','write_acceptance_required'],[$routeHold->fresh()->state,$routeHold->fresh()->safe_code],'Missing accepted TEST Purchase route holds before claim');
same($before,count($GLOBALS['enomWire']['forms']),'Missing route acceptance sends no Enom request');
$write('acceptance',['ending'=>'com','accept'=>true,'terms'=>[1],'registry'=>true,'verified'=>true,'test_registration_accepted'=>true]);
$write('import',['ending'=>'com']);$write('publish',[],$tld->id);
$routeDomain=$routeHold->domain->fresh();$releaseTicket=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$routeDomain->id,(int)$routeHold->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($routeHold->fresh(),$routeDomain),$clock);
app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$routeDomain->id,(int)$routeHold->id,'release',$releaseTicket,'Staff reaccepted the current TEST Purchase route and interval');
same('queued',$routeHold->fresh()->state,'A fresh staff route acceptance allows release of the unclaimed hold');
$usdHold=$new(placeholderComFixtureName('usd-proof-required-placeholder.com'));DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->update(['source'=>'daily']);
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($usdHold->id);
same(['held','usd_balance_required'],[$usdHold->fresh()->state,$usdHold->fresh()->safe_code],'TEST write requires the existing staff USD balance probe');
same('Not sent: check the registrar balance first',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('usd_balance_required'),'Missing balance evidence directs staff to the balance page');
same($before,count($GLOBALS['enomWire']['forms']),'Missing provider-backed USD evidence blocks before preflight');
DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->update(['source'=>'manual']);
$usdDomain=$usdHold->domain->fresh();$releaseTicket=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$usdDomain->id,(int)$usdHold->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($usdHold->fresh(),$usdDomain),$clock);
$before=count($GLOBALS['enomWire']['forms']);app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$usdDomain->id,(int)$usdHold->id,'release',$releaseTicket,'The latest balance is now a successful USD read');
same('queued',$usdHold->fresh()->state,'Fresh USD evidence allows the unclaimed hold to be released');same($before,count($GLOBALS['enomWire']['forms']),'USD hold release itself makes no Enom request');
$op=$new(placeholderComFixtureName('paid-test-placeholder.com'));$before=count($GLOBALS['enomWire']['forms']);$usedBeforePurchasePath=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');$GLOBALS['advanceDuringPreflight']=true;$worker->operation($op->id);
$purchaseCommands=array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command');
same(['Check','PE_GetProductPrice','GetBalance','Purchase'],$purchaseCommands,'Three preflights then one sealed Purchase; no reconciliation read before completion proof: '.json_encode([$purchaseCommands,$op->fresh()->state,$op->fresh()->safe_code,DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->first()]));same('pending',$op->fresh()->state,'ACK remains Processing');
same($usedBeforePurchasePath+4,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Only preflight and Purchase reserve four call units before completion proof');
$before=count($GLOBALS['enomWire']['forms']);$GLOBALS['testRegisteredName']=$op->domain->domain;
$offlinePsl=static fn():never=>throw new Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed');
app()->instance(Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,new Paymenter\Extensions\Servers\Domains\Services\PslTransport($offlinePsl));
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
if(!$runtimeTurn){DB::table('domains_runtime_turns')->insert(['server_id'=>$server->id,'account_fingerprint'=>$binding->fingerprint,'operation_cursor'=>0,'domain_cursor'=>0,'tld_cursor'=>0,'turn'=>0,'due_at'=>0,'finished_at'=>0]);$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();}
DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$op->id]);DB::table('domains_runtime_operations')->where('operation_id',$op->id)->update(['due_at'=>0]);
Cache::flush();Queue::fake();app(RuntimeWork::class)->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$op->id);
same($before,count($GLOBALS['enomWire']['forms']),'Scheduler queues captured-order reconciliation without making reads in the scheduler turn');
(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same('succeeded',$op->fresh()->state,'Restarted worker reconciles captured OrderID with history closed');
same(8,count($GLOBALS['enomWire']['forms'])-$before,'Captured OrderID uses seven proof reads and one post-proof nameserver read: '.json_encode(array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command')));$d=$op->domain->fresh();
same('active',$d->status,'Ownership confirmed');same('10.00',$op->fresh()->cost['actual']['amount'],'Actual charge');expect(isset($d->renewal_policy['billing_anchor']),'Billing anchor established');same(null,$d->service->expires_at,'Native expiry stays null');
same(['ns1.provider.example','ns2.provider.example'],$d->observation['nameservers']['nameservers']??null,'Confirmed TEST registration stores provider nameservers as observed');
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($op->id);same($before,count($GLOBALS['enomWire']['forms']),'Completed duplicate no calls');unset($GLOBALS['testRegisteredName']);
same('registration_pending',$paused->domain->fresh()->status,'Processing-off paid TEST reservation remains registration pending');
same('active',$op->domain->fresh()->status,'Captured-order registration completes to active before scheduler check');
app(ObservationWork::class)->enroll((int)$server->id);$usedBeforePoll=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');
$before=count($GLOBALS['enomWire']['forms']);$observation=app(ObservationWork::class)->tick();
same('read_deferred',$observation[$server->id]??null,'Opted-in TEST registration skips general domain observation');
same(0,DB::table('domains_observation_work')->where('server_id',$server->id)->count(),'General observation creates no queued domain reads');
same($before,count($GLOBALS['enomWire']['forms']),'Observation tick sends no Enom reads');same($usedBeforePoll,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Observation tick reserves no Enom calls');
$turn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
if(!$turn){DB::table('domains_runtime_turns')->insert(['server_id'=>$server->id,'account_fingerprint'=>$binding->fingerprint,'operation_cursor'=>0,'domain_cursor'=>0,'tld_cursor'=>0,'turn'=>0,'due_at'=>0,'finished_at'=>0]);}
else{DB::table('domains_runtime_turns')->where('id',$turn->id)->update(['operation_cursor'=>0,'domain_cursor'=>0,'tld_cursor'=>0,'due_at'=>0]);}
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$unpaidOp->id]);DB::table('domains_runtime_operations')->where('operation_id',$unpaidOp->id)->update(['attempts'=>0,'due_at'=>0]);
$usedBeforePoll=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');$before=count($GLOBALS['enomWire']['forms']);Cache::flush();Queue::fake();app(RuntimeWork::class)->tick();
Queue::assertNotPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$unpaidOp->id);
same('rejected',$unpaidOp->fresh()->state,'Retired unpaid TEST operation remains terminal and is not requeued');
same($before,count($GLOBALS['enomWire']['forms']),'Runtime tick sends no Enom reads');same($usedBeforePoll,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Runtime tick reserves no Enom calls');
// Missing frozen contacts must reach the existing contact-specific hold and correction path before any Enom read.
$incompleteUser=commerceUser();$incompleteUser->update(['email'=>'contact-owner@example.com']);$incompleteCart=commerceCart($incompleteUser);commerceAdd($incompleteCart,$product,placeholderComFixtureName('contacts-recovery-placeholder.com'));
commerceCheckout($incompleteUser,$incompleteCart)->assertHasNoErrors();$contactOp=Domain::where('domain','contacts-recovery-placeholder.com')->sole()->operations()->sole();
App\Helpers\ExtensionHelper::addPayment($contactOp->invoice_id,null,$contactOp->amount);
same('paid',App\Models\Invoice::findOrFail($contactOp->invoice_id)->status,'Contact correction case receives native payment');
expect($a->fresh()->processing_enabled,'Contact recovery fixture has processing enabled');expect($ports->accepted($a->fresh()),'Contact recovery fixture has accepted TEST runtime');
expect(app(Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class)->current($contactOp->fresh()),'Contact recovery fixture is dispatchable');
expect(Paymenter\Extensions\Servers\Domains\Services\AcquisitionSetup::ready($contactOp->fresh()),'Contact recovery fixture has valid purchase inputs');
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($contactOp->id);same($before,count($GLOBALS['enomWire']['forms']),'Missing contacts stop before Enom reads');
same(['held','contact_details_required'],[$contactOp->fresh()->state,$contactOp->fresh()->safe_code],'Claim preserves the contact-specific recovery hold: '.json_encode([$contactOp->fresh()->state,$contactOp->fresh()->safe_code]));
foreach(['address'=>'1 Corrected Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$incompleteUser->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
\Paymenter\Extensions\Servers\Domains\Services\ContactRecovery::replace($incompleteUser,$contactOp->id,'Owner corrected missing address and phone');
same('queued',$contactOp->fresh()->state,'Contact correction queues the unsent registration');$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$before=count($GLOBALS['enomWire']['forms']);$worker->operation($contactOp->id);
same(['Check','PE_GetProductPrice','GetBalance','Purchase'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Corrected contact order resumes through one Purchase without pre-proof nameserver reads');
// Exhaust after preflight, before seal: safe cap evidence remains staff-retryable.
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$capOp=$new(placeholderComFixtureName('cap-held-placeholder.com'));
expect(EnomTestRegistration::applies($capOp),'TEST deployment opts in only Enom registration operations');
$otherRegistrar=clone $capOp;$otherRegistrar->registrar_key='namesilo';
expect(!EnomTestRegistration::applies($otherRegistrar),'Enom TEST opt-in leaves NameSilo registration on its own path');
$used=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');config(['domains.enom_test_daily_call_cap'=>$used+3]);
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($capOp->id);same(['Check','PE_GetProductPrice','GetBalance'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Cap refuses Purchase before send');same('held',$capOp->fresh()->state,'Cap held');same('enom_test_call_cap',$capOp->fresh()->safe_code,'Safe staff reason');
same(1,count($capOp->fresh()->attempt['test_call_refusals']??[]),'Refused claim retained as retryable cap evidence');expect(!isset($capOp->fresh()->attempt['claim_key'],$capOp->fresh()->attempt['seal']),'No uncertain or sealed attempt remains');
try{$capOp->fresh()->update(['attempt'=>[]]);throw new LogicException('Refusal history cannot be erased.');}catch(DomainException){}
same(1,count($capOp->fresh()->attempt['test_call_refusals']??[]),'Cap refusal history remains append-only');
same('Not sent: Enom test daily limit reached',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('enom_test_call_cap'),'Staff wording directs safe cap recovery and states the USD evidence requirement');
expect(str_contains(EnomTestCalls::facts(),'Limit reached; Enom TEST requests are refused until 00:00 UTC'),'Staff sees the exhausted positive limit and reset time');
$capViewer=Auth::user();Auth::setUser($staff);
expect(OperationActions::relevant('release',$capOp->fresh()),'Retained registration cap proof offers release');
same('release',RequestFacts::notice($capOp->fresh())['action']??null,'Retained registration cap notice offers release');
Auth::setUser($capViewer);
$capDomain=$capOp->domain->fresh();$releaseTicket=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$capDomain->id,(int)$capOp->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($capOp->fresh(),$capDomain),$clock);
app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$capDomain->id,(int)$capOp->id,'release',$releaseTicket,'Capacity is available after review');
same('queued',$capOp->fresh()->state,'Staff release preserves the proven-unsent TEST retry');$used=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');
config(['domains.enom_test_daily_call_cap'=>$used+8]);$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$GLOBALS['testNameUnavailable']=true;$before=count($GLOBALS['enomWire']['forms']);$worker->operation($capOp->id);unset($GLOBALS['testNameUnavailable']);
same(['Check','PE_GetProductPrice','GetBalance'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Retried preflight may refuse a taken name');
same(['held','write_acceptance_required'],[$capOp->fresh()->state,$capOp->fresh()->safe_code],'Ordinary preflight refusal remains a reviewed unsent hold');
same(1,count($capOp->fresh()->attempt['test_call_refusals']??[]),'Retry retains original cap refusal history');
expect(Paymenter\Extensions\Servers\Domains\Services\OperationClaims::testCallRefusalRetryable($capOp->fresh()),'Prior cap refusal preserves narrow retry and refund proof');
$exhausted=clone $capOp->fresh();$attempt=$exhausted->attempt;$attempt['capacity_deferrals']=array_fill(0,6,$clock->now()->format(DATE_ATOM));$exhausted->setAttribute('attempt',$attempt);
expect(!Paymenter\Extensions\Servers\Domains\Services\OperationClaims::testCallRefusalRetryable($exhausted),'Six capacity deferrals exhaust the TEST retry budget');
Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireUnsent($exhausted,$capDomain);
try{Paymenter\Extensions\Servers\Domains\Services\StaffOperationWrites::release($exhausted,['account'=>$a],$clock->now());throw new LogicException('Exhausted TEST retries cannot be released.');}catch(DomainException){}
same('held',$capOp->fresh()->state,'Exhausted TEST capacity proof remains held and refundable');
$clock->at=$clock->at->modify('+300 seconds');
$releaseTicket=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$capDomain->id,(int)$capOp->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($capOp->fresh(),$capDomain),$clock);
app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$capDomain->id,(int)$capOp->id,'release',$releaseTicket,'Registry availability will be rechecked after a delayed safe readmission');
$used=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');config(['domains.enom_test_daily_call_cap'=>$used+5]);
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();if($runtimeTurn){DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);}
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$capOp->id]);DB::table('domains_runtime_operations')->where('operation_id',$capOp->id)->update(['due_at'=>0]);
$before=count($GLOBALS['enomWire']['forms']);Cache::flush();Queue::fake();app(RuntimeWork::class)->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$capOp->id);
$worker->operation($capOp->id);
same(['Check','PE_GetProductPrice','GetBalance','Purchase'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Released retry performs fresh admission and one Purchase without pre-proof nameserver reads: '.json_encode([array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),$capOp->fresh()->state,$capOp->fresh()->safe_code,$capOp->fresh()->attempt]));
same('pending',$capOp->fresh()->state,'Recovered operation has one acknowledged Purchase');
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();
config(['domains.enom_test_daily_call_cap'=>500]);$refundOp=$new(placeholderComFixtureName('cap-refund-placeholder.com'));$used=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');config(['domains.enom_test_daily_call_cap'=>$used+3]);
$worker->operation($refundOp->id);same(['held','enom_test_call_cap'],[$refundOp->fresh()->state,$refundOp->fresh()->safe_code],'Refund case starts from a proven-unsent cap hold');
same(['amount'=>$refundOp->amount,'currency'=>'USD'],app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class)->refundHeld($staff,$refundOp->id,'enom_test_call_cap'),'Cap-held operation can be refunded exactly once');
expect(isset($refundOp->fresh()->funding['refund']),'Cap refusal history remains valid through account credit');
config(['domains.enom_test_daily_call_cap'=>0]);expect(str_contains(EnomTestCalls::facts(),'Daily limit is 0; all Enom TEST requests are refused.'),'Zero limit wording is accurate');expect(!EnomTestCalls::callsRemain(),'Zero limit has no retry path');$beforeCalls=count($GLOBALS['enomWire']['forms']);$beforeUsed=(int)DB::table(EnomTestCallSchema::TABLE)->value('used');
$balanceBeforeRefusal=DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->first();
$capRead=static fn()=>new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),
    new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1),$clock,Runtime::limiter());
try{Paymenter\Extensions\Servers\Domains\Services\BalanceReads::read($reader,$capRead(),'manual');throw new LogicException('Zero cap must refuse');}catch(EnomTestCallLimit){}
same((array)$balanceBeforeRefusal,(array)DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->first(),'A local cap refusal preserves reviewed USD balance evidence');
same($beforeUsed,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Zero cap does not increment');same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Zero cap refuses before transport send');
$clock->at=$clock->at->modify('+61 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());Cache::flush();
$beforeCalls=count($GLOBALS['enomWire']['forms']);$evidenceBefore=array_intersect_key((array)$balanceBeforeRefusal,array_flip(['amount','currency','source','attempted_at','read_at','last_error_code']));
Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])
    ->callAction('check_balance')->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't check balance")->body('Enom test daily limit reached. Try again after 00:00 UTC.'));
same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Staff Refresh now honors the exhausted cap before transport');
$evidenceAfter=DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->first();
same($evidenceBefore,array_intersect_key((array)$evidenceAfter,array_flip(['amount','currency','source','attempted_at','read_at','last_error_code'])),'Staff cap refusal preserves the prior successful USD evidence');
config(['domains.enom_test_daily_call_cap'=>'50']);same('Deployment setting domains.enom_test_daily_call_cap is invalid; all Enom TEST requests are refused. Contact the deployment administrator.',EnomTestCalls::facts(),'Non-integer cap wording names the setting and who fixes it');expect(!EnomTestCalls::callsRemain(),'Invalid limit has no retry path');
$beforeCalls=count($GLOBALS['enomWire']['forms']);$beforeUsed=(int)DB::table(EnomTestCallSchema::TABLE)->value('used');
try{Paymenter\Extensions\Servers\Domains\Services\BalanceReads::read($reader,$capRead(),'manual');throw new LogicException('Non-integer cap must refuse');}catch(EnomTestCallLimit){}
same($beforeUsed,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Non-integer cap refuses without increment');same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Non-integer cap refuses before transport send');
config(['domains.enom_test_daily_call_cap'=>500]);
$clock->at=$clock->at->modify('+61 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());Cache::flush();
$beforeCalls=count($GLOBALS['enomWire']['forms']);$beforeUsed=(int)DB::table(EnomTestCallSchema::TABLE)->value('used');$counterFault=true;
DB::listen(static function($query)use(&$counterFault):void{if($counterFault&&str_starts_with(strtolower($query->sql),'insert')&&str_contains($query->sql,EnomTestCallSchema::TABLE)){$counterFault=false;throw new RuntimeException('Synthetic counter error');}});
Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])
    ->callAction('check_balance')->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't check balance")->body("Couldn't count Enom test calls. Try again."));
expect(!$counterFault,'Synthetic counter error reached the reservation');same($beforeUsed,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Counter error refusal does not increment');same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Staff Refresh now explains a refusal while calls remain, before transport');
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$reconcileCapOp=$new(placeholderComFixtureName('reconcile-cap-placeholder.com'));$worker->operation($reconcileCapOp->id);same('pending',$reconcileCapOp->fresh()->state,'Second Purchase ACK remains Processing');
$GLOBALS['testRegisteredName']=$reconcileCapOp->domain->domain;$used=(int)DB::table(EnomTestCallSchema::TABLE)->value('used');config(['domains.enom_test_daily_call_cap'=>$used+3]);
$before=count($GLOBALS['enomWire']['forms']);(new RuntimeWork($clock,new RuntimePorts))->operation($reconcileCapOp->id);
same(3,count($GLOBALS['enomWire']['forms'])-$before,'Mid-reconciliation cap permits only three reads');same('pending',$reconcileCapOp->fresh()->state,'Cap during reconciliation remains Processing');same('enom_test_reconciliation_call_cap',$reconcileCapOp->fresh()->safe_code,'Staff sees the reconciliation cap reason');same('enom_test_reconciliation_call_cap',$reconcileCapOp->fresh()->policy['reconciliation']['reason'],'Reconciliation retains the cap reason');
same('Registrar has not confirmed the result',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('enom_test_reconciliation_call_cap'),'Staff wording reflects cap recovery and possible sent Purchase');
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($reconcileCapOp->id);same($before,count($GLOBALS['enomWire']['forms']),'Duplicate reconciliation cannot resend Purchase or exceed cap');same('pending',$reconcileCapOp->fresh()->state,'Duplicate remains Processing');
config(['domains.enom_test_daily_call_cap'=>(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used')+7]);
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();
$GLOBALS['enomWire']['execFail']=true;$before=count($GLOBALS['enomWire']['forms']);$worker->operation($reconcileCapOp->id);$GLOBALS['enomWire']['execFail']=false;
expect(count($GLOBALS['enomWire']['forms'])>$before,'Unrelated reconciliation failure consumes its reserved reads');
same('reconciliation_required',$reconcileCapOp->fresh()->safe_code,'A later non-cap read failure clears the stale cap safe code');
same('reconciliation_required',$reconcileCapOp->fresh()->policy['reconciliation']['reason'],'A later non-cap read failure updates its reconciliation reason');
config(['domains.enom_test_daily_call_cap'=>(int)DB::table(EnomTestCallSchema::TABLE)->value('used')+8]);Cache::flush();
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
if($runtimeTurn){DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);}
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$reconcileCapOp->id]);
DB::table('domains_runtime_operations')->where('operation_id',$reconcileCapOp->id)->update(['due_at'=>0]);
$before=count($GLOBALS['enomWire']['forms']);Cache::flush();Queue::fake();(new RuntimeWork($clock,new RuntimePorts))->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$reconcileCapOp->id);
same($before,count($GLOBALS['enomWire']['forms']),'Runtime scheduler queues paused reconciliation without provider traffic');
Queue::pushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$reconcileCapOp->id)->sole()->handle(new RuntimeWork($clock,new RuntimePorts));
$resumeCommands=array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command');same(['GetDomainInfo','GetRenew','GetRegLock','GetWPPSInfo','GetDomainInfo','GetDomainExp','GetOrderDetail','GetDNS'],$resumeCommands,'Raised cap resumes bounded proof and post-proof nameserver reads: '.json_encode($resumeCommands));same('succeeded',$reconcileCapOp->fresh()->state,'Resumed reconciliation completes without a new Purchase: '.json_encode([$resumeCommands,$reconcileCapOp->fresh()->safe_code,$reconcileCapOp->fresh()->policy['reconciliation']??null]));same(['ns1.provider.example','ns2.provider.example'],$reconcileCapOp->domain->fresh()->observation['nameservers']['nameservers']??null,'Resumed reconciliation stores observed nameservers');unset($GLOBALS['testRegisteredName']);
config(['domains.enom_test_daily_call_cap'=>500]);$beforeCalls=count($GLOBALS['enomWire']['forms']);$beforeUsed=(int)DB::table(EnomTestCallSchema::TABLE)->value('used');
try{EnomTestCalls::send($binding,true);throw new LogicException('Purchase requires a worker reservation');}catch(EnomTestCallLimit){}
same($beforeUsed,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Unreserved Purchase refuses without spending a cap unit');
try{EnomTestCalls::withPurchase(new AccountBinding('enom',$binding->serverId,Environment::Live,'live-uid'),fn()=>null);throw new LogicException('Purchase scope is TEST-only');}catch(EnomTestCallLimit){}
same($beforeUsed,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Live Purchase cannot reserve or send');
$otherBinding=new AccountBinding('enom',$binding->serverId,Environment::Test,'another-test-uid');
try{EnomTestCalls::withPurchase($binding,function()use($binding,$otherBinding):void{EnomTestCalls::reservePurchase($binding);EnomTestCalls::send($otherBinding,true);});throw new LogicException('Purchase reservation binds the fingerprint');}catch(EnomTestCallLimit){}
same($beforeUsed+1,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Mismatched Purchase consumes only its reserved unit');
EnomTestCalls::withPurchase($binding,function()use($binding):void{
    EnomTestCalls::reservePurchase($binding);
    EnomTestCalls::send($binding,true);
    try{EnomTestCalls::send($binding,true);throw new LogicException('Purchase reservation is single-use');}catch(EnomTestCallLimit){}
});
same($beforeUsed+2,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'One scope reserves one Purchase even when a second send is refused');
EnomTestCalls::withPurchase($binding,function()use($binding,$op):void{
    EnomTestCalls::reservePurchase($binding);
    expect(EnomTestCalls::purchaseUnconsumed($binding->fingerprint,(int)$op->id),'Unsent Purchase proof matches its operation scope');
    expect(!EnomTestCalls::purchaseUnconsumed($binding->fingerprint,(int)$op->id+1),'Unsent Purchase proof rejects a different operation');
    EnomTestCalls::send($binding,true);
},(int)$op->id);
same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Reservation guard checks never reach transport');
$sealedRolloverOp=$new(placeholderComFixtureName('sealed-rollover-placeholder.com'));
$clock->at=new DateTimeImmutable('2026-10-21T23:59:59Z');Cache::flush();$write('import',['ending'=>'com']);$write('probe');Cache::flush();$sealedRolloverDay=EnomTestCalls::day();
$limiter=Runtime::limiter();expect($limiter instanceof Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter,'Installed TEST worker uses the shared account read limiter');
$heldRead=$limiter->acquire($binding,RequestOrigin::Background);$before=count($GLOBALS['enomWire']['forms']);
$worker->operation($sealedRolloverOp->id);
same($before,count($GLOBALS['enomWire']['forms']),'Saturated account read slot defers before provider traffic');
same('queued',$sealedRolloverOp->fresh()->state,'Local read-capacity refusal remains retryable');
same(1,count($sealedRolloverOp->fresh()->attempt['capacity_deferrals']??[]),'First claim retains its bounded capacity deferral');
$limiter->release($binding,RequestOrigin::Background,$heldRead);Cache::flush();
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$sealedRolloverOp->id]);DB::table('domains_runtime_operations')->where('operation_id',$sealedRolloverOp->id)->update(['due_at'=>0]);
Queue::fake();app(RuntimeWork::class)->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$sealedRolloverOp->id);
$sealedRolloverUsed=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',$sealedRolloverDay)->value('used');
$before=count($GLOBALS['enomWire']['forms']);

$GLOBALS['enomWire']['during']=static function(array $form)use($clock,$sealedRolloverOp):void {
    if(($form['Command']??null)!=='GetBalance'){return;}
    $GLOBALS['enomWire']['during']=null;
    $GLOBALS['enomWire']['before']=static function()use($clock,$sealedRolloverOp):void {
        same('sealed',$sealedRolloverOp->fresh()->attempt['seal']??null,'Rollover hook runs after the paid operation is sealed');
        $clock->at=new DateTimeImmutable('2026-10-22T00:00:00Z');
    };
};
$worker->operation($sealedRolloverOp->id);
unset($GLOBALS['enomWire']['before']);$GLOBALS['enomWire']['during']=null;
same(['Check','PE_GetProductPrice','GetBalance'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Midnight after seal refuses before Purchase enters cURL');
same(['held','enom_test_call_cap'],[$sealedRolloverOp->fresh()->state,$sealedRolloverOp->fresh()->safe_code],'Post-seal unsent rollover becomes a retryable cap hold');
same('purchase',$sealedRolloverOp->fresh()->attempt['test_call_refusals'][0]['phase']??null,'Post-seal refusal is recorded as Purchase phase');
same(1,count($sealedRolloverOp->fresh()->attempt['capacity_deferrals']??[]),'Purchase cap retains earlier bounded capacity deferrals');
expect(!isset($sealedRolloverOp->fresh()->attempt['claim_key'],$sealedRolloverOp->fresh()->attempt['seal'],$sealedRolloverOp->fresh()->attempt['execution']),'Refusal clears seal and execution evidence');
same($sealedRolloverUsed+4,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',$sealedRolloverDay)->value('used'),'Preflight plus Purchase reservations remain charged to the old UTC day');
same(0,(int)(DB::table(EnomTestCallSchema::TABLE)->where('day','2026-10-22')->value('used')??0),'Rollover does not reserve a second-day Purchase unit');
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
expect(Paymenter\Extensions\Servers\Domains\Services\OperationClaims::testCallRefusalRetryable($sealedRolloverOp->fresh()),'Post-seal unsent refusal passes the staff release guard');
$sealedDomain=$sealedRolloverOp->domain->fresh();
$sealedRelease=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$sealedDomain->id,(int)$sealedRolloverOp->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($sealedRolloverOp->fresh(),$sealedDomain),$clock);
app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$sealedDomain->id,(int)$sealedRolloverOp->id,'release',$sealedRelease,'The midnight refusal was verified as unsent');
same('queued',$sealedRolloverOp->fresh()->state,'Staff can release the post-seal unsent cap hold');
same(1,count($sealedRolloverOp->fresh()->attempt['capacity_deferrals']??[]),'Release preserves capacity deferrals alongside refusal history');
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$sealedRolloverOp->id]);DB::table('domains_runtime_operations')->where('operation_id',$sealedRolloverOp->id)->update(['due_at'=>0]);
Cache::flush();Queue::fake();$before=count($GLOBALS['enomWire']['forms']);app(RuntimeWork::class)->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$sealedRolloverOp->id);
same($before,count($GLOBALS['enomWire']['forms']),'Runtime scheduler queues released TEST write without provider traffic');
$rolloverStart=$clock->at;$rolloverDay=EnomTestCalls::day();$rolloverUsed=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',$rolloverDay)->value('used');
EnomTestCalls::withPurchase($binding,function()use($binding,$clock,$rolloverDay,$rolloverUsed):void{
    EnomTestCalls::reservePurchase($binding);$clock->at=$clock->at->modify('+1 day');
    try{EnomTestCalls::send($binding,true);throw new LogicException('A Purchase reservation cannot cross UTC midnight');}catch(EnomTestCallLimit){}
    same($rolloverUsed+1,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',$rolloverDay)->value('used'),'Old-day reservation remains charged');
    same(0,(int)(DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used')??0),'Rollover refusal does not consume a new-day unit');
});
$clock->at=$rolloverStart;
$requestClock=$clock->at;$clock->at=new DateTimeImmutable('2026-10-21T23:59:59Z');Cache::flush();$readDay=EnomTestCalls::day();
$readUsed=(int)(DB::table(EnomTestCallSchema::TABLE)->where('day',$readDay)->value('used')??0);$readRollover=false;
DB::listen(static function($query)use(&$readRollover,$clock):void {
    if(!$readRollover&&str_contains($query->sql,EnomTestCallSchema::TABLE)){$readRollover=true;$clock->at=$clock->at->modify('+1 second');}
});
$readDeadline=new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,RequestOrigin::Interactive,
    $clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1),$clock,Runtime::limiter());
$before=count($GLOBALS['enomWire']['forms']);try{$reader->balance($readDeadline);throw new LogicException('A read cannot send against yesterday’s reservation');}catch(EnomTestCallLimit){}
same($before,count($GLOBALS['enomWire']['forms']),'Read UTC rollover stops before cURL send');
same($readUsed+1,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',$readDay)->value('used'),'Read rollover keeps the old-day unit spent');
$clock->at=$requestClock;Cache::flush();$deadlineUsed=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');$slowCounter=false;
DB::listen(static function($query)use(&$slowCounter):void {
    if(!$slowCounter&&str_contains($query->sql,EnomTestCallSchema::TABLE)){$slowCounter=true;$GLOBALS['enomWire']['nanoseconds']+=26000000000;}
});
$deadlineContext=new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,RequestOrigin::Interactive,
    $clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1),$clock,Runtime::limiter());
$before=count($GLOBALS['enomWire']['forms']);$reader->balance($deadlineContext);
same($before,count($GLOBALS['enomWire']['forms']),'Counter latency past deadline refuses before read send');
same($deadlineUsed+1,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Deadline-expired read reservation remains spent');
// Isolate the limiter after synthetic clock jumps, then enter the worker on the
// last second to verify preflight rollover with the reviewed price still current.
config(['domains.enom_test_daily_call_cap'=>500]);$clock->at=new DateTimeImmutable('2026-10-21T23:59:59Z');Cache::flush();
DB::table('domains_read_budgets')->where('server_id',$server->id)->delete();$midnightOp=$new(placeholderComFixtureName('preflight-midnight-placeholder.com'));$oldDay=EnomTestCalls::day();$newDayUsed=(int)(DB::table(EnomTestCallSchema::TABLE)->where('day','2026-10-22')->value('used')??0);
$oldUsed=(int)(DB::table(EnomTestCallSchema::TABLE)->where('day',$oldDay)->value('used')??0);$before=count($GLOBALS['enomWire']['forms']);
$GLOBALS['advanceDuringPreflight']=true;$worker->operation($midnightOp->id);
same(['Check','PE_GetProductPrice','GetBalance','Purchase'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Preflight may cross UTC midnight before reserving Purchase; reconciliation waits for completion proof');
same('pending',$midnightOp->fresh()->state,'Midnight during preflight does not create a false cap hold');
same($oldUsed+1,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',$oldDay)->value('used'),'Check stays charged to its send day');
same($newDayUsed+3,(int)DB::table(EnomTestCallSchema::TABLE)->where('day','2026-10-22')->value('used'),'Other preflights and Purchase reserve on their send day');
expect($a->fresh()->processing_enabled,'Catalogue-off regression starts after staff enabled processing');$catalogueCart=commerceCart($user);commerceAdd($catalogueCart,$product,placeholderComFixtureName('catalogue-off-processing-placeholder.com'));config(['domains.enom_catalogue.enabled'=>false]);$counts=[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])];
commerceCheckout($user,$catalogueCart)->assertDispatched('notify',fn($event,$params)=>$params[0]['type']==='error');same($counts,[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])],'Catalogue key-off with processing enabled creates no order, invoice, payment, or Purchase');config(['domains.enom_catalogue.enabled'=>true]);
config(['domains.enom_writes.registration'=>null]);expect($a->fresh()->processing_enabled,'Key-off regression starts after staff enabled processing');$closedCart=commerceCart($user);commerceAdd($closedCart,$product,placeholderComFixtureName('key-off-processing-placeholder.com'));$counts=[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])];
commerceCheckout($user,$closedCart)->assertDispatched('notify',fn($event,$params)=>$params[0]['type']==='error');same($counts,[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])],'Key-off with processing enabled creates no order, invoice, payment, or Purchase');config(['domains.enom_writes.registration'=>true]);
$write('acceptance',['ending'=>'com','accept'=>true,'terms'=>[1],'registry'=>true,'verified'=>true,'test_registration_accepted'=>true,'transfer'=>true]);$accepted=CatalogueAcceptances::load($binding);
foreach(DB::table('domains_prices')->where(['server_id'=>$server->id,'tld'=>'com'])->get() as $row){$proof=json_decode($row->eligibility,true,512,JSON_THROW_ON_ERROR);$proof['catalogue_revision']=$accepted->revision;DB::table('domains_prices')->where('id',$row->id)->update(['eligibility'=>json_encode($proof,JSON_THROW_ON_ERROR)]);}
$source=(array)DB::table('domains_prices')->where(['server_id'=>$server->id,'tld'=>'com','action'=>'register','term'=>1,'currency'=>'USD'])->first();expect($source!==[],'Registration cost provides the synthetic transfer row');
$proof=json_decode($source['eligibility'],true,512,JSON_THROW_ON_ERROR);$proof['catalogue_revision']=$accepted->revision;$source['action']='transfer';$source['revision']=1;$source['eligibility']=json_encode($proof,JSON_THROW_ON_ERROR);unset($source['id'],$source['created_at'],$source['updated_at']);DB::table('domains_prices')->insert($source);
DB::table('domains_tlds')->where('id',$tld->id)->update(['transfer_enabled'=>true]);DB::table('domains_sales_policies')->where('tld','com')->update(['transfer_enabled'=>true]);$server->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);$write('publish',[],$tld->id);$transferProduct=app(NativeCatalogue::class)->product($tld,'transfer');
$mixedCart=commerceCart($user);commerceAdd($mixedCart,$product,placeholderComFixtureName('mixed-register-placeholder.com'));$GLOBALS['testTransferQuery']=true;commerceAdd($mixedCart,$transferProduct,placeholderComFixtureName('mixed-transfer-placeholder.com'));unset($GLOBALS['testTransferQuery']);$mixedCounts=[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])];
commerceCheckout($user,$mixedCart)->assertDispatched('notify',fn($event,$params)=>$params[0]['type']==='error');same($mixedCounts,[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),count($GLOBALS['enomWire']['forms'])],'Mixed TEST registration and transfer cart creates no order, invoice, payment, or Purchase');
$selection=app(Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class)->selection('com','transfer',1,$clock->now());DB::table('domains_accounts')->where('id',$a->id)->update(['processing_enabled'=>false]);
try{DB::transaction(fn()=>app(Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class)->admit([$selection],$clock->now()));throw new LogicException('TEST registration does not exempt transfer from processing.');}catch(DomainException){}DB::table('domains_accounts')->where('id',$a->id)->update(['processing_enabled'=>true]);
resetWire();$clock->at=new DateTimeImmutable('2026-10-22T00:00:01Z');
file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));$nativeSuccessful=true;
echo "Installed TEST registration, safe cap holds, captured-ID reconciliation and retained cap passed.\n";
