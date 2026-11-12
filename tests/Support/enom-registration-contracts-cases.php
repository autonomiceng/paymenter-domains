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
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomTestCalls, EnomTestCallLimit, EnomTestRegistration, EnomTestSendLimit, EnomTestSendLimitSchema, Installation, NativeCatalogue, ObservationWork, Runtime, RuntimePorts, RuntimeWork, StaffSettingsReview as Review, StaffSettingsWrites, Storefront};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
require __DIR__.'/enom-test-registration-fixture.php';
require_once __DIR__.'/installed-offer-evidence.php';
config(['domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true]);
$protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
expect(\Paymenter\Extensions\Servers\Domains\Services\EnomProtocol::accepts($binding,$protocol),'Compiled TEST Purchase route and interval');
// Protocol evidence does not come from the staff sales form; covered by enom-protocol-test.php and offer-staff-policy-test.
saveDefaultRule(['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none']);
$tld=installedOfferEvidence($binding,$clock);
$write('test_renewal',['accept'=>true,'verified'=>true]);
// Keep the independent renewal writer off during its negative composition cases.
config(['domains.enom_writes.renewal'=>false]);
$write('tld_settings',['enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true],$tld->id);
$write('publish',[],$tld->id);$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$tld->id);
$catalogueUsed=enomTestSendCount();same(2,$catalogueUsed,'Only real configure and probe consume setup sends; independent evidence is local');
expect(!str_contains(EnomTestSendLimit::facts('test-uid'),'Limit reached'),'Facts report sends left below the configured limit');
config(['domains.enom_test_sends_per_day'=>$catalogueUsed]);expect(str_contains(EnomTestSendLimit::facts('test-uid'),'Limit reached. Try again later.'),'Facts report the reached limit');config(['domains.enom_test_sends_per_day'=>1000]);
same('Our send check stopped this Enom request before sending: send limit, setting, counter or UTC day change.',(new EnomTestCallLimit)->getMessage(),'Internal refusal names the send limit');
EnomTestSendLimit::admit(Environment::Live,'test-uid','GetBalance');same($catalogueUsed,enomTestSendCount(),'Live requests do not use the TEST limit');
$reader=$bindings->reader($server->id,true);$generalReader=$bindings->reader($server->id);
$readContext=new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Interactive,
    $clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(4),$clock,Paymenter\Extensions\Servers\Domains\Services\Runtime::limiter());
expect($generalReader instanceof Paymenter\Extensions\Servers\Domains\Registrars\Enom\EnomTestReconciliationReader,'Opted-in general reads use the narrow reconciliation wrapper');
$historyNow=$clock->now();$history=new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'enom_registration_v1');
$historyAttempt=new AttemptEvidence($binding,new Query('history-only-placeholder.com',Action::Register,1),'history-only',1,ExecutionRecord::RecordedNull,SendState::PossiblySent,
    $historyNow->modify('-60 seconds'),$historyNow->modify('-1 second'),seal:DispatchSeal::Sealed,historyAcceptance:$history);
expect($history->covers($historyAttempt,$historyNow),'Regression fixture carries valid pre-existing Enom history acceptance');$beforeHistory=count($GLOBALS['enomWire']['forms']);
$historyContext=new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(20),$clock,Paymenter\Extensions\Servers\Domains\Services\Runtime::limiter());
// Accepted registration history opens read-only recovery; an empty XML list cannot establish a purchase.
$GLOBALS['enomWire']['reply']=static fn(array $p):string=>$p['Command']==='GetOrderList'
    ?'<interface-response><Command>GetOrderList</Command><ErrCount>0</ErrCount><Done>true</Done><OrderCount>0</OrderCount><OrderList></OrderList></interface-response>'
    :$reply($p);
try{$historyResult=$generalReader->reconcile($historyAttempt,$historyContext);}
finally{$GLOBALS['enomWire']['reply']=$reply;}
same(['GetDomainInfo','GetRenew','GetRegLock','GetWPPSInfo','GetDomainInfo','GetDomainExp','GetOrderList'],
    array_column(array_slice($GLOBALS['enomWire']['forms'],$beforeHistory),'Command'),'Covering acceptance opens only bounded read-only reconciliation');
$historyFacts=$historyResult->result()->evidence();
same([false,[],Paymenter\Extensions\Servers\Domains\Data\Disposition::Unknown],
    [$historyFacts->historyComplete,$historyFacts->candidates,$historyResult->disposition],'Empty XML history remains incomplete and never invents a successful purchase');
foreach([null,new HistoryAcceptance($binding,'America/Los_Angeles',120,86400,'enom_registration_v1')]as$uncovered){
    $attempt=new AttemptEvidence($binding,$historyAttempt->query,'history-uncovered',1,ExecutionRecord::RecordedNull,SendState::PossiblySent,
        $historyAttempt->windowStart,$historyAttempt->windowEnd,seal:DispatchSeal::Sealed,historyAcceptance:$uncovered);
    expect(!($uncovered?->covers($attempt,$historyNow)??false),'Negative has no covering history acceptance');$beforeHistory=count($GLOBALS['enomWire']['forms']);
    // A sealed attempt that may have been sent reads its domain facts, but uncovered history keeps the order history closed.
    $uncoveredResult=$generalReader->reconcile($attempt,$historyContext);$uncoveredSent=array_column(array_slice($GLOBALS['enomWire']['forms'],$beforeHistory),'Command');
    same(['GetDomainInfo','GetRenew','GetRegLock','GetWPPSInfo','GetDomainInfo','GetDomainExp'],$uncoveredSent,'Uncovered history reads domain facts but never the order history');
    $uncoveredFacts=$uncoveredResult->result()->evidence();
    same([false,[],Paymenter\Extensions\Servers\Domains\Data\Disposition::Unknown],
        [$uncoveredFacts->historyComplete,$uncoveredFacts->candidates,$uncoveredResult->disposition],'Uncovered history never settles the attempt');
}
// Without a sealed attempt that may have been sent, uncovered history still refuses before any read.
foreach([[ExecutionRecord::Absent,DispatchSeal::Absent,SendState::DefinitelyUnsent],[ExecutionRecord::RecordedNull,DispatchSeal::Unknown,SendState::PossiblySent]]as[$execution,$seal,$send]){
    $attempt=new AttemptEvidence($binding,$historyAttempt->query,'history-unsent',1,$execution,$send,
        $historyAttempt->windowStart,$historyAttempt->windowEnd,seal:$seal,historyAcceptance:null);$beforeHistory=count($GLOBALS['enomWire']['forms']);
    try{$generalReader->reconcile($attempt,$historyContext);throw new LogicException('Unsent or unsealed attempt without history acceptance must refuse before reads.');}
    catch(DomainException $error){same('Captured TEST Purchase OrderID is required.',$error->getMessage(),'Uncovered history cannot substitute for a captured order');}
    same($beforeHistory,count($GLOBALS['enomWire']['forms']),'Uncovered history on an unsent attempt sends no registrar request');
}
$closedQuery=new Paymenter\Extensions\Servers\Domains\Data\Query('closed-poll.example',Action::Register,1);$beforeClosed=count($GLOBALS['enomWire']['forms']);
foreach([fn()=>$generalReader->quote($closedQuery,$readContext),fn()=>$generalReader->readDomain('closed-poll.example',$readContext),
    fn()=>$generalReader->renewalAssessment($closedQuery,$readContext)] as $closedRead){try{$closedRead();throw new LogicException('General TEST reader exposed a reconciliation-only method.');}catch(DomainException){}}
same($beforeClosed,count($GLOBALS['enomWire']['forms']),'General TEST reader blocks domain and renewal polling before Enom');
$GLOBALS['enomWire']['execFail']=true;$beforeFailedSend=count($GLOBALS['enomWire']['forms']);$beforeFailedReservation=enomTestSendCount();$reader->balance($readContext);$GLOBALS['enomWire']['execFail']=false;
same($beforeFailedSend+1,count($GLOBALS['enomWire']['forms']),'Failed synthetic send entered the transport once');same($beforeFailedReservation+1,enomTestSendCount(),'Failed send stays counted');
$limitUsed=enomTestSendCount();config(['domains.enom_test_sends_per_day'=>$limitUsed]);
$beforeIdentity=count($GLOBALS['enomWire']['forms']);try{app(Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry::class)->verify('enom',$server->id,Environment::Test,['uid'=>'test-uid','token'=>'TEST-SECRET-TOKEN']);throw new LogicException('Identity at the send limit must keep its typed local refusal.');}catch(EnomTestCallLimit){}
same($limitUsed,enomTestSendCount(),'Refused identity send records no attempt');
same($beforeIdentity,count($GLOBALS['enomWire']['forms']),'Identity at the send limit stops before curl_exec');config(['domains.enom_test_sends_per_day'=>1000]);

// Active paused accounts have no public offer, unlike the retired A4 preview catalogue.
// Deployment-off stale checkout coverage remains at the end of this installed phase;
// default-held guessed checkout and zero obligations are covered by registration-held.php.
$beforeCalls=count($GLOBALS['enomWire']['forms']);
same([],app(Storefront::class)->endings(),'A catalogue review cannot open paused new sales');
same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Closed active display makes no provider request');
$corrupt=clone $a;$corrupt->stable_identity='changed-identity';expect(!$ports->registered($corrupt),'Mismatched identity reports unregistered instead of throwing');
$deny=static function(callable $work):void {try{$work();throw new LogicException('Expected refusal');}catch(DomainException) {}};
foreach([false,'true'] as $off){config(['domains.enom_writes.registration'=>$off]);expect(!$ports->accepted($a),'Only boolean true enables composition');}
config(['domains.enom_writes.registration'=>true]);
foreach([null,'live'] as $environment){config(['domains.enom_identity.environment'=>$environment]);expect(!$ports->accepted($a),'Live/unset refuses composition');$deny(fn()=>EnomTestRegistration::factory($binding));}
config(['domains.enom_writes.registration'=>true,'domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>false]);expect(!$ports->accepted($a),'Catalogue opt-in mandatory');
same('TEST registration refused: the deployment TEST catalogue is off.',EnomTestRegistration::reason(),'Staff can identify a disabled TEST catalogue');config(['domains.enom_catalogue.enabled'=>true]);
config(['domains.enom_identity.environment'=>null]);same('TEST registration refused: the deployment identity environment is not test. Live and unset environments cannot use TEST registration.',EnomTestRegistration::reason(),'Staff can identify a missing TEST identity environment');config(['domains.enom_identity.environment'=>'test']);
same('Deployment allows TEST registration. The account needs at least one TLD saved under What you sell, processing on, and enough registrar funds. The worker checks the balance before sending.',EnomTestRegistration::reason(),'Staff copy describes deployment and evidence prerequisites');
same('Not sent: Orders off for this account',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('processing'),'Processing-off hold directs staff recovery');
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
$front=app(Storefront::class);
$before=count($GLOBALS['enomWire']['forms']);$beforeUsed=enomTestSendCount();Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])->assertSee('API calls, last 24 hours')->assertSee(number_format(EnomTestSendLimit::usage('test-uid')['day']).' of '.number_format(EnomTestSendLimit::usage('test-uid')['day_limit']))->assertSeeInOrder(['Sales','Registrations','On','Transfers'])->assertDontSee('TEST-SECRET-TOKEN');$front->endings();same($before,count($GLOBALS['enomWire']['forms']),'Page views make zero calls');same($beforeUsed,enomTestSendCount(),'Page views record no TEST sends');
$before=count($GLOBALS['enomWire']['forms']);Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])
    ->callAction('checkBalance')->assertNotified('Balance updated');
same($before+1,count($GLOBALS['enomWire']['forms']),'Staff Refresh now makes one counted balance read');
same('GetBalance',$GLOBALS['enomWire']['forms'][array_key_last($GLOBALS['enomWire']['forms'])]['Command'],'Existing staff Refresh now reads the opted-in TEST balance');
$manualBalance=DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->where('account_fingerprint',$binding->fingerprint)->first();
same(['manual','USD',null],[$manualBalance->source,$manualBalance->currency,$manualBalance->last_error_code],'Staff balance action records fresh USD evidence');
config(['domains.enom_writes.renewal'=>true]);
$write('processing',['enabled'=>true,'confirmed'=>true]);$write('publish',[],$tld->id);
$product=app(NativeCatalogue::class)->product($tld,'register');expect($product!==null,'Real active publication follows deliberate processing activation');
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
// Daily price sync and What you sell fills are covered separately; keep both not due during local retirement.
app(ObservationWork::class)->enroll((int)$server->id);
$offerQueue=DB::table('domains_observation_queues')->where('server_id',$server->id);$work=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($offerQueue->value('offer_work'));$work['feed_due']=$clock->now()->getTimestamp()+86400;$work['facts']=[];$work['credential_revision']=$bindings->offerCredentialRevision($binding);$work['upgrade_generation']=\Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::generation();
$offerQueue->update(['offer_work'=>json_encode($work,JSON_THROW_ON_ERROR)]);
$before=count($GLOBALS['enomWire']['forms']);$usedBefore=enomTestSendCount();Cache::flush();Queue::fake();$worker->tick();
same('cancelled',$unpaidDomain->fresh()->status,'Durable TEST domain cursor retires a cancelled unpaid reservation after missed callbacks');
same(null,$unpaidDomain->fresh()->reservation_name,'TEST lifecycle recovery releases the abandoned local name');same(['rejected','acquisition_cancelled_unpaid'],[$unpaidOp->fresh()->state,$unpaidOp->fresh()->safe_code],'Missed callback recovery closes the unpaid operation');
same($before,count($GLOBALS['enomWire']['forms']),'Local TEST lifecycle recovery makes no provider requests');same($usedBefore,enomTestSendCount(),'Local TEST lifecycle recovery reserves no provider calls');
$new=static function(string $name)use($user,$product,$clock):Operation {
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $cart=commerceCart($user);commerceAdd($cart,$product,$name);commerceCheckout($user,$cart)->assertHasNoErrors();
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount);
    same('paid',App\Models\Invoice::findOrFail($op->invoice_id)->status,'Native payment marks the invoice paid');
    return $op->fresh();
};

$nativeSuccessful=true;
