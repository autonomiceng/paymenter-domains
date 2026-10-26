<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native app, no network.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/staff-alerts.php';
use App\Models\{Invoice,ServiceCancellation};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,DB,Route,Schema};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarBalances as Page,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,Disposition,DriverResult,Environment,Evidence,Money,RequestBudget,RequestContext,RequestOrigin,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Price};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,BalanceForecast,BalanceReads,BalanceSchema,CatalogueAcceptances,ObservationSchema,RegistrarBalances,RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
extract(commerceFixture(true));$clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
app()->instance(AccountBindings::class,$bindings);BalanceSchema::install();\Paymenter\Extensions\Servers\Domains\Services\BalanceAlertSchema::install();ObservationSchema::install();
$a=Account::where('server_id',$tlds[0]->server_id)->sole();$b=Account::where('server_id',$tlds[1]->server_id)->sole();
$binding=CatalogueAcceptances::binding($a);$other=CatalogueAcceptances::binding($b);
$row=static fn($a)=>DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->first();
$forecast=static fn()=>BalanceForecast::for($a,$clock->now());
$user=commerceUser();$product=$native->product($tlds[0],'register');
$order=App\Models\Order::create(['user_id'=>$user->id,'currency_code'=>'USD']);
$seed=App\Models\Service::create(['user_id'=>$user->id,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$native->annualPlan($product)->id,
    'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'active']);
$make=static function(string $name,?string $expiry='2026-11-20',?Account $account=null)use($seed,$a):Domain {
    $account??=$a;$s=$seed->replicate();$s->status='active';$s->save();
    return Domain::create(['domain'=>$name,'sld'=>substr($name,0,-4),'tld'=>'com','user_id'=>$s->user_id,'service_id'=>$s->id,
        'server_id'=>$account->server_id,'registrar_key'=>$account->registrar_key,'account_fingerprint'=>$account->account_fingerprint,
        'status'=>'active','provider_expires_at'=>$expiry,'renewal_policy'=>[]]);
};
$renew=static function(Domain $d,string $state='awaiting_payment',array $extra=[])use($user):Operation {
    $invoice=Invoice::create(['user_id'=>$user->id,'status'=>'pending','currency_code'=>'USD','due_at'=>'2027-05-01']);
    return Operation::create([...$d->only(['server_id','registrar_key','account_fingerprint','service_id','authority_generation']),
        'domain_id'=>$d->id,'operation_key'=>'balance-renew-'.$d->id,'action'=>'renew','term'=>1,'currency'=>'USD','amount'=>'10.00',
        'invoice_id'=>$invoice->id,'funding_basis'=>'paid_invoice','state'=>$state,'base_expires_at'=>$d->provider_expires_at,
        'payload'=>[],'policy'=>[],'attempt'=>[],'funding'=>[],...$extra]);
};
$d=$make(placeholderComFixtureName('balance-window-placeholder.com'));
same('8.000000',$forecast()['total'],'Paymenter due in 26 days counts despite expiry in 33');
same(1,$forecast()['count'],'One forecast holding');
foreach ([['2026-11-15','2026-12-15',true],['2026-12-15','2026-11-15',true],
    ['2026-11-15',null,true],[null,'2026-11-15',true],[null,null,null],
    ['2026-11-18',null,false],['2026-11-17',null,true],['2026-10-17','2026-11-15',true]] as [$due,$expiry,$expected]) {
    same($expected,BalanceForecast::withinWindow($due,$expiry,$clock->now()),'Earlier known date determines inclusive UTC30-day window');
}
BalanceReads::record($binding,new Money('8.00','USD'),$clock->now(),'preflight');
same('OK',RegistrarBalances::facts($a,$clock->now())['status'],'Equal balance is OK');
BalanceReads::record($binding,new Money('7.99','USD'),$clock->now(),'renewal');
same('Low balance',RegistrarBalances::facts($a,$clock->now())['status'],'One cent short is Low balance');
BalanceReads::record($other,new Money('777.00','USD'),$clock->now(),'manual');
same('7.99',$row($a)->amount,'Two servers isolated');same('777.00',$row($b)->amount,'Other server unchanged');
expect(BalanceForecast::compare('999999999999999999.000001','999999999999999999.000002')<0,'Six decimal digits at maximum magnitude stay exact');
$foreign=new AccountBinding('enom',$binding->serverId,Environment::Test,'PRIVATE-OTHER-LOGIN');
DB::table(BalanceSchema::TABLE)->insert(['server_id'=>$binding->serverId,'account_fingerprint'=>$foreign->fingerprint,'amount'=>'123.45','currency'=>'USD']);
BalanceReads::record($foreign,new Money('999.00','USD'),$clock->now(),'manual');
same('123.45',DB::table(BalanceSchema::TABLE)->where('account_fingerprint',$foreign->fingerprint)->value('amount'),'Other fingerprint on same server never overwritten');
same('7.99',$row($a)->amount,'Same server account isolation');
BalanceReads::record($binding,null,$clock->now(),'manual','PRIVATE-PROVIDER-REPLY');
same('7.99',$row($a)->amount,'Failure retains last value');same('read_failed',$row($a)->last_error_code,'Failure category allowlist');
$failedFacts=RegistrarBalances::facts($a,$clock->now());same('Missing',$failedFacts['status'],'A failed check is Missing');same('Last check failed',$failedFacts['cause'],'Missing balance names its cause');same(null,$failedFacts['balance'],'A failed check hides the old amount');
$failed=new DriverResult($binding,Disposition::Unknown,SendState::PossiblySent,ResultReason::Unverified);
$failedReader=new FakeRegistrar($binding,new Capabilities($binding,[]),$failed);
$context=new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new RequestBudget(1),$clock);
expect(BalanceReads::read($failedReader,$context,'preflight')===$failed,'Recording preserves the exact failed driver result for existing guards');
same(['balance'],$failedReader->calls,'Failed balance result is read exactly once');same('7.99',$row($a)->amount,'Failed provider result retains the last balance');
foreach ([new \Paymenter\Extensions\Servers\Domains\Services\ReadDeferred('Synthetic deferred'),
    new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit, new RuntimeException('Synthetic failure')] as $failure) {
    BalanceReads::record($binding,new Money('7.99','USD'),$clock->now(),'manual'); $before=(array)$row($a);
    $throwing=new class($binding,$failure) implements \Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver {
        public int $calls=0;
        public function __construct(private AccountBinding $account,private Throwable $failure){}
        public function binding():AccountBinding{return $this->account;}
        public function balance(RequestContext $c):DriverResult{$c->claimRead($this->account);$this->calls++;throw $this->failure;}
        public function accountCapabilities(RequestContext $c):Capabilities{throw new LogicException('Unused');}
        public function quote(\Paymenter\Extensions\Servers\Domains\Data\Query $q,RequestContext $c):DriverResult{throw new LogicException('Unused');}
        public function availability(\Paymenter\Extensions\Servers\Domains\Data\Query $q,RequestContext $c):DriverResult{throw new LogicException('Unused');}
        public function readDomain(string $n,RequestContext $c):DriverResult{throw new LogicException('Unused');}
        public function readRenewalDomain(string $n,RequestContext $c):DriverResult{throw new LogicException('Unused');}
        public function renewalAssessment(\Paymenter\Extensions\Servers\Domains\Data\Query $q,RequestContext $c):DriverResult{throw new LogicException('Unused');}
        public function reconcile(\Paymenter\Extensions\Servers\Domains\Data\AttemptEvidence $a,RequestContext $c):\Paymenter\Extensions\Servers\Domains\Data\ReconciliationResult{throw new LogicException('Unused');}
    };
    $context=new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new RequestBudget(1),$clock);
    try{BalanceReads::read($throwing,$context,'preflight');throw new LogicException('Expected exact balance exception');}
    catch(Throwable $caught){expect($caught===$failure,'Balance exception identity is preserved');}
    same(1,$throwing->calls,'Throwing balance is called exactly once');
    if($failure instanceof RuntimeException){
        same('7.99',$row($a)->amount,'Ordinary throw retains amount');same('USD',$row($a)->currency,'Ordinary throw retains currency');
        same($before['read_at'],$row($a)->read_at,'Ordinary throw preserves last successful read');
        same('read_failed',$row($a)->last_error_code,'Ordinary throw records failure');same('preflight',$row($a)->source,'Failure records attempted source');
    }else{same($before,(array)$row($a),'Local deferral and TEST cap retain the entire last successful row');}
}
// Refusals before a new read preserve every field of the last successful balance.
BalanceReads::record($binding,new Money('7.99','USD'),$clock->now(),'manual');
foreach(['budget','deadline','binding'] as $refusal){
    $budget=new RequestBudget(1);$budget->consume();
    $localReader=$refusal==='binding'?new FakeRegistrar($other,new Capabilities($other,[]),new DriverResult($other,Disposition::Unknown,SendState::PossiblySent,ResultReason::Unverified)):$failedReader;
    $c=new RequestContext($binding,RequestOrigin::Interactive,
        $clock->now()->modify($refusal==='deadline'?'-1 second':'+25 seconds'),$refusal==='budget'?$budget:new RequestBudget(1),$clock);
    $before=(array)$row($a);
    $refused=false;try{BalanceReads::read($localReader,$c,'preflight');}catch(RuntimeException|DomainException|Paymenter\Extensions\Servers\Domains\Data\AccountBindingMismatch){$refused=true;}
    expect($refused,'Local '.$refusal.' refusal expected');
    same($before,(array)$row($a),'Local '.$refusal.' refusal never invalidates saved balance');
}
$noWire=new class implements Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Transport {
    public int $calls=0;
    public function send(Environment $environment,Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand $command,array $parameters,string $uid,string $token,int $timeoutMs):string{$this->calls++;throw new LogicException('Local refusal must not send');}
};
$readDriver=new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client($binding,'account-enom','synthetic-token',$noWire),['com']);
foreach(['budget','deadline'] as $refusal){
    $c=new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify($refusal==='deadline'?'-1 second':'+25 seconds'),new RequestBudget($refusal==='budget'?0:1),$clock);
    $before=(array)$row($a);same(Disposition::Unknown,BalanceReads::read($readDriver,$c,'preflight')->disposition,'Local driver refusal returns an unknown result');
    same($before,(array)$row($a),'Returned local '.$refusal.' refusal retains successful balance');
}
same(0,$noWire->calls,'Returned local refusals make no physical requests');
BalanceReads::read($readDriver,new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new RequestBudget(1),$clock),'preflight');
same(1,$noWire->calls,'Transport failure consumes exactly one physical read');same('read_failed',$row($a)->last_error_code,'Consumed transport failure invalidates balance');
BalanceReads::record($binding,new Money('7.99','USD'),$clock->now(),'manual');
$claimed=new RequestBudget(1);$thrown=null;
try{BalanceReads::read($readDriver,new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),$claimed,$clock,stopOnReadFailure:true),'renewal');}
catch(DomainException $e){$thrown=$e;}
expect($thrown!==null&&$thrown->getMessage()==='Registrar read failed.','Consumed read exception propagates through balance recording');
same(0,$claimed->remaining(),'Throwing balance consumed one read');
same('read_failed',$row($a)->last_error_code,'Consumed exception invalidates a successful balance');
same('renewal',$row($a)->source,'Consumed exception records its attempted source');same('7.99',$row($a)->amount,'Consumed exception retains the last known amount');
BalanceReads::record($binding,new Money('7.99','USD'),$clock->now(),'manual');
same(null,$row($a)->last_error_code,'Returned failure control begins with a successful balance');
BalanceReads::read($failedReader,new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new RequestBudget(1),$clock),'preflight');
same('read_failed',$row($a)->last_error_code,'A newly consumed failed read still invalidates balance');
// Paid privacy reaches balance only after two successful reads; earlier budget use is not a balance read.
$managementCalls=[];$managementFailure='';
$management=new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($binding,static function($origin,$command)use(&$managementCalls,&$managementFailure):string{
    $managementCalls[]=$command;
    if($command==='GetBalance'&&$managementFailure==='throw'){throw new RuntimeException('Synthetic balance transport failure');}
    if($command==='GetBalance'&&$managementFailure==='cap'){throw new Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit;}
    $body=match($command){
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">privacy.example</DomainName><Status><Belongs-To>account-enom</Belongs-To></Status></GetDomainInfo>',
        'GetWPPSInfo'=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSPrice>1.00</WPPSPrice></GetWPPSInfo>',
        'GetBalance'=>$managementFailure==='malformed'?'<AvailableBalance>bad</AvailableBalance>':'<AvailableBalance>8.00</AvailableBalance>',
        default=>throw new LogicException('Unexpected balance fixture command'),
    };return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
});
$privacy=new Paymenter\Extensions\Servers\Domains\Data\ManagementRequest($binding,'synthetic-balance','privacy.example','privacy_purchase',[],providerId:'456');
foreach(['budget','cap','throw','malformed','success'] as $mode){
    BalanceReads::record($binding,new Money('7.99','USD'),$clock->now(),'manual');$before=(array)$row($a);$managementCalls=[];$managementFailure=$mode;
    $refused=false;try{$management->prepare($privacy,new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new RequestBudget($mode==='budget'?2:3),$clock));}catch(RuntimeException|DomainException){$refused=true;}
    same($mode!=='success',$refused,'Management balance refusal matches the physical read result');
    same($mode==='budget'?['GetDomainInfo','GetWPPSInfo']:['GetDomainInfo','GetWPPSInfo','GetBalance'],$managementCalls,'Privacy reaches the intended balance boundary');
    if(in_array($mode,['budget','cap'],true)){same($before,(array)$row($a),'Management local '.$mode.' refusal retains successful balance');}
    else{same($mode==='success'?null:'read_failed',$row($a)->last_error_code,'Management records only completed or failed physical balance reads');}
}
BalanceReads::record($binding,null,$clock->now(),'preflight','read_failed');
BalanceReads::record($binding,new Money('8.00','USD'),$clock->now()->modify('-1 second'),'manual');
same('read_failed',$row($a)->last_error_code,'Old completion cannot replace newer failure');
$rate=Price::where('server_id',$a->server_id)->where('action','renew')->sole();$saved=$rate->wholesale;
$rate->update(['wholesale'=>null,'revision'=>$rate->revision+1]);same(1,$forecast()['unknown'],'Unknown cost is counted');
same(1,RegistrarBalances::facts($a,$clock->now())['unknown'],'Facts count renewals without a cost');$rate->update(['wholesale'=>$saved,'revision'=>$rate->revision+1]);
$d->update(['billing_managed'=>false]);
$overdue=$make(placeholderComFixtureName('balance-overdue-placeholder.com'),'2026-10-20');
same('8.000000',$forecast()['total'],'Past Paymenter due date and future expiry without open invoice counts as due now');
$overdue->update(['billing_managed'=>false]);
$undated=$make(placeholderComFixtureName('balance-undated-placeholder.com'),null);same(1,$forecast()['unknown'],'Neither date is unknown, not zero');$dueOnly=$renew($undated);same(0,$forecast()['unknown'],'An open obligation with only its invoice due date is known');
DB::table('invoices')->where('id',$dueOnly->invoice_id)->update(['due_at'=>null]);same(1,$forecast()['unknown'],'An open obligation with neither date is unknown');
$undated->update(['billing_managed'=>false]);
$server=App\Models\Server::find($a->server_id);$server->settings()->create(['key'=>'domains_due_days','value'=>'invalid']);
$d->update(['billing_managed'=>true,'provider_expires_at'=>'2026-11-15']);same('8.000000',$forecast()['total'],'Only confirmed expiry remains known with invalid calendar');
$server->settings()->where('key','domains_due_days')->delete();$d->update(['billing_managed'=>false]);
$far=$make(placeholderComFixtureName('balance-open-placeholder.com'),'2027-06-01');$op=$renew($far,'held',['attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent']]);
same('8.000000',$forecast()['total'],'Open unsent obligation outside horizon counts');
$op->update(['attempt'=>['seal'=>'sealed','dispatch_attempted_at'=>$clock->now()->format(DATE_ATOM)]]);
same(0,$forecast()['count'],'Sent obligation excluded');
$far->update(['billing_managed'=>false]);$proven=$make(placeholderComFixtureName('balance-prewire-placeholder.com'),'2027-06-01');
$proof=['safe_code'=>'renewal_definitely_unsent','lease_expires_at'=>null,'started_at'=>$clock->now(),'request_fingerprint'=>str_repeat('a',64),
    'attempt'=>['claim_key'=>str_repeat('b',32),'seal'=>'sealed','dispatch_attempted_at'=>$clock->now()->format(DATE_ATOM),
        'send_state'=>'possibly_sent','authorized_cost'=>'8.00','renewal_mode'=>'ordinary',
        'execution'=>['disposition'=>'rejected','send_state'=>'definitely_unsent','reason'=>'unsent','discrepancies'=>[]]],
    'funding'=>['claim'=>['amount'=>'10.00'],'seal'=>['amount'=>'10.00']],'cost'=>['estimated'=>'8.00','currency'=>'USD','unit'=>'term_total']];
$renew($proven,'held',$proof);
same('8.000000',$forecast()['total'],'Validated sealed prewire refusal remains open outside horizon');
$proven->update(['billing_managed'=>false]);$unproven=$make(placeholderComFixtureName('balance-unproven-placeholder.com'),'2027-06-01');
$proof['attempt']['execution']['discrepancies']=['unknown'];$renew($unproven,'held',$proof);
same(0,$forecast()['count'],'Unvalidated sealed refusal cannot borrow definitely-unsent proof');
$unproven->update(['billing_managed'=>false]);
$ownerOff=$make(placeholderComFixtureName('balance-owner-off-placeholder.com'));$ownerOff->update(['renewal_policy'=>['owner_auto_renew'=>false]]);
same(0,$forecast()['count'],'Owner Off excludes unissued renewals');$ownerDebt=$renew($ownerOff);
same(1,$forecast()['count'],'Owner Off preserves issued unsent debt forecast');$ownerOff->update(['billing_managed'=>false]);
$stopped=$make(placeholderComFixtureName('balance-stopped-placeholder.com'));$stopped->update(['renewal_policy'=>['renewals_stopped'=>true]]);
same(0,$forecast()['count'],'Stopped holding excludes unissued renewals');$issued=$renew($stopped,'held');
same(1,$forecast()['count'],'Stopped holding counts its issued unsent obligation exactly once');same('8.000000',$forecast()['total'],'Issued obligation retains its expected spending');
DB::table('invoices')->where('id',$issued->invoice_id)->update(['status'=>'cancelled']);
same(0,$forecast()['count'],'Cancelled invoice cannot spend even while its issued operation remains held on a stopped holding');
$stopped->update(['renewal_policy'=>[]]);same(0,$forecast()['count'],'Cancelled invoice cycle cannot be counted again as an unissued renewal');
$stopped->update(['renewal_policy'=>['renewals_stopped'=>true]]);
$issued->update(['state'=>'succeeded']);$stopped->update(['provider_expires_at'=>'2027-11-20']);
same(0,BalanceForecast::for($a,$clock->now()->modify('+1 year'))['count'],'Next cycle within its next-year window is not forecast on stopped holding');$stopped->update(['billing_managed'=>false]);
foreach(['pending','uncertain','succeeded','rejected'] as $state){$h=$make(placeholderComFixtureName('balance-'.$state.'-placeholder.com'),'2026-11-01');$renew($h,$state);same(0,$forecast()['count'],'Sent/completed/retired cycle excluded');$h->update(['billing_managed'=>false]);}
$off=$make(placeholderComFixtureName('balance-off-placeholder.com'));$renew($off);$cancel=ServiceCancellation::create(['service_id'=>$off->service_id,'type'=>'end_of_period','reason'=>'Synthetic']);
same(1,$forecast()['count'],'Native end-of-period stop preserves issued obligation');
$cancel->update(['type'=>'immediate']);same(0,$forecast()['count'],'Immediate cancellation excludes unsendable issued obligation');$cancel->delete();
$off->update(['status'=>'retired']);same(0,$forecast()['count'],'Retired holding excluded');
$off->update(['status'=>'active']);$off->service->update(['status'=>'cancelled']);same(0,$forecast()['count'],'Cancelled service excluded');
$otherDomain=$make(placeholderComFixtureName('balance-other-placeholder.com'),'2026-11-01',$b);same(0,$forecast()['count'],'Forecast respects second server');$otherDomain->update(['billing_managed'=>false]);
// One bounded forecast chunk batches cancellation and invoice reads for both obligation paths.
$queryCount=static function()use($forecast):int{DB::enableQueryLog();DB::flushQueryLog();$forecast();$n=count(DB::getQueryLog());DB::disableQueryLog();return $n;};
DB::beginTransaction();
$bounded=[];$bounded[]=$make(placeholderComFixtureName('forecast-bounded-first-placeholder.com'));$renew($bounded[0]);
$oneChunk=$queryCount();
for($i=1;$i<25;$i++){$h=$make(placeholderComFixtureName('forecast-bounded-'.$i.'-placeholder.com'));$bounded[]=$h;$renew($h,$i%2?'awaiting_payment':'held',$i%2?[]:$proof);}
$manyChunk=$queryCount();same($oneChunk,$manyChunk,'Forecast read count stays bounded across one chunk of obligations');
same(13,$forecast()['count'],'Issued and unproven sent obligations retain their distinct forecast semantics');
// Retained adoption stops are loaded once for the same bounded chunk.
Paymenter\Extensions\Servers\Domains\Services\AdoptionSchema::install();
Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema::install();
Paymenter\Extensions\Servers\Domains\Services\HoldingAdoptionSchema::install();
$adoptStop=static function(Domain $h,bool $stop)use($clock,$user):void{
    $record=new Paymenter\Extensions\Servers\Domains\Models\Adoption([...$h->only(['service_id','user_id','server_id','registrar_key','account_fingerprint','authority_generation']),
        'request_key'=>'forecast-adoption-'.$h->id,'request_digest'=>str_repeat('b',64),'domain_id'=>$h->id,'state'=>'committed','origin'=>'staff','environment'=>'test',
        'actor_id'=>$user->id,'reason'=>'Synthetic retained forecast stop','do_not_renew'=>$stop,'cancellation_id'=>$stop?999999:null,
        'evidence'=>['version'=>1,'registrar_key'=>$h->registrar_key,'environment'=>'test','resulting_generation'=>$h->authority_generation],
        'observed_at'=>$clock->now(),'valid_until'=>$clock->now()->modify('+1 hour'),'committed_at'=>$clock->now()]);
    $aid=DB::table('domains_adoptions')->insertGetId($record->getAttributes());
    DB::table('domains_domains')->where('id',$h->id)->update(['provenance'=>'adopted','adoption_id'=>$aid]);
};
$adoptStop($bounded[0],false);$oneAdoption=$queryCount();
foreach(array_slice($bounded,1) as $i=>$h){$adoptStop($h,$i===0);}
same($oneAdoption,$queryCount(),'One chunk batches original adoption stops');same(12,$forecast()['count'],'Original adopted renewal stop blocks its issued obligation');
for($i=25;$i<101;$i++){$h=$make(placeholderComFixtureName('forecast-bounded-'.$i.'-placeholder.com'));$bounded[]=$h;$renew($h);}
expect($queryCount()<=2*$manyChunk+2,'Forecast read count grows by chunks across the 100-domain boundary');
same(88,$forecast()['count'],'The next chunk includes every additional open obligation exactly once');
DB::rollBack();same(0,$forecast()['count'],'Forecast fixture rollback preserves prior holdings');
// Daily and manual reads use the same provider-login limiter, without requiring write activation.
$readState=(object)['calls'=>0,'fail'=>false,'readers'=>[]];$registry=new RegistrarRegistry;
$verify=new class implements AccountVerifier {public function verify(Environment $e,array $c):string{return $c['token'];}};
foreach(['enom','namesilo'] as $key){$registry->register($key,['token'],$verify,static function($binding,$credentials)use($readState):FakeRegistrar {
    same(0,DB::transactionLevel(),'Balance read outside transaction');$readState->calls++;
    if($readState->fail){throw new RuntimeException('PRIVATE-PROVIDER-REPLY');}
    return $readState->readers[]=new FakeRegistrar($binding,new Capabilities($binding,[]),new DriverResult($binding,($readState->failedResult??false)?Disposition::Unknown:Disposition::Accepted,SendState::Sent,($readState->failedResult??false)?ResultReason::Unverified:ResultReason::Acknowledged,new Evidence(balance:new Money('6.00','USD'))));
});}
$balanceReads=static fn()=>array_sum(array_map(static fn($reader)=>count(array_filter($reader->calls,static fn($method)=>$method==='balance')),$readState->readers));
$manualBindings=new AccountBindings($registry);$limiter=new AccountReadBudget($clock);$balances=new RegistrarBalances($manualBindings,$clock,$limiter);
app()->instance(Clock::class,$clock);app()->instance(RegistrarBalances::class,$balances);
staffPanel();$panel=Filament::getCurrentPanel();$pages=[RegistrarSetup::class,RegistrarAccount::class,Page::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$staff=staffUser([Staff::VIEW]);$denied=staffUser([]);Auth::login($denied);
try{$balances->refresh($a->server_id);expect(false,'Refresh must reject missing permission');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Permission refusal');}
same(0,$readState->calls,'Refused refresh never resolves reader');Livewire::actingAs($denied)->test(Page::class)->assertForbidden();
Livewire::actingAs($denied)->test(RegistrarSetup::class)->assertForbidden();Livewire::actingAs($denied)->test(RegistrarAccount::class,['account'=>$a->server_id])->assertForbidden();
Auth::login($staff);same('refreshed',$balances->refresh($a->server_id),'Permitted manual read');same('manual',$row($a)->source,'Manual source');
same('rate_limited',$balances->refresh($a->server_id),'60-second refresh cooldown');same(1,$readState->calls,'Cooldown adds no provider read');
$clock->instant=$clock->now()->modify('+60 seconds');same('refreshed',$balances->refresh($a->server_id),'Exact60-second boundary permits refresh');
$lease=$limiter->acquire($binding,RequestOrigin::Interactive);$calls=$readState->calls;$balances->daily($a);same($calls+1,$readState->calls,'Daily resolves one reader');
same(null,$row($a)->last_error_code,'Local quota refusal preserves last successful read');same('manual',$row($a)->source,'Deferral records no provider result');
same($clock->now()->getTimestamp()+60,(int)$row($a)->daily_due_at,'Deferred daily read retries after one minute');$limiter->release($binding,RequestOrigin::Interactive,$lease);
$calls=$readState->calls;$balances->daily($a);same($calls,$readState->calls,'Deferred daily read avoids immediate retries');
$clock->instant=$clock->now()->modify('+60 seconds');$balances->daily($a);same('daily',$row($a)->source,'Daily source');same(null,$row($a)->last_error_code,'Daily read success');
$calls=$readState->calls;$balances->daily($a);same($calls,$readState->calls,'Successful daily read not retried within24 hours');
$clock->instant=$clock->now()->modify('+60 seconds');$readState->fail=true;same('configuration_unavailable',$balances->refresh($a->server_id),'Reader configuration failure is safe');
same('6.00',$row($a)->amount,'Daily value retained on refresh failure');$readState->fail=false;
// Local limiter errors before a physical read never invalidate retained balance evidence.
foreach(['manual','daily']as$source){foreach(['throw','leases','fingerprint']as$damage){
    $savedBalance=(array)$row($a);$savedBudget=(array)DB::table('domains_read_budgets')->where('server_id',$a->server_id)->sole();
    $savedTime=$clock->instant;
    try{
        $clock->instant=$clock->now()->modify('+61 seconds');
        BalanceReads::record($binding,new Money('6.00','USD'),$clock->now(),'manual');
        DB::table(BalanceSchema::TABLE)->where('id',$savedBalance['id'])->update(['manual_due_at'=>0,'daily_due_at'=>0]);
        $before=(array)$row($a);$wireBefore=$balanceReads();
        $broken=$limiter;
        if($damage==='throw'){
            $broken=new class implements \Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter {
                public function acquire(AccountBinding $b,RequestOrigin $o):string{throw new RuntimeException('Synthetic local limiter failure');}
                public function release(AccountBinding $b,RequestOrigin $o,string $t):void{throw new LogicException('Unused lease');}
            };
        }else{DB::table('domains_read_budgets')->where('server_id',$a->server_id)->update($damage==='leases'?['leases'=>'{']:['account_fingerprint'=>str_repeat('f',64)]);}
        $blocked=new RegistrarBalances($manualBindings,$clock,$broken);
        if($source==='manual'){same('read_failed',$blocked->refresh($a->server_id),'Local limiter failure keeps its safe refresh code');}else{$blocked->daily($a);}
        $after=(array)$row($a);
        foreach(['manual_due_at','daily_due_at']as$field){unset($before[$field],$after[$field]);}
        same($before,$after,'Pre-budget '.$damage.' through '.$source.' preserves all balance evidence');
        same($wireBefore,$balanceReads(),'Pre-budget failure makes no balance/provider send');
        same('USD 6.00',RegistrarBalances::facts($a,$clock->now())['balance'],'Local failure keeps retained balance visible');
        DB::table('domains_read_budgets')->where('server_id',$a->server_id)->update($savedBudget);
        $clock->instant=$clock->now()->modify($source==='manual'?'+60 seconds':'+1 day');
        if($source==='manual'){same('refreshed',$balances->refresh($a->server_id),'Repaired limiter recovers after manual cooldown');}else{$balances->daily($a);}
        same($wireBefore+1,$balanceReads(),'Repaired limiter permits exactly one balance read');
        same(null,$row($a)->last_error_code,'Repaired read remains successful');
    }finally{
        DB::table(BalanceSchema::TABLE)->where('id',$savedBalance['id'])->update($savedBalance);
        DB::table('domains_read_budgets')->where('server_id',$a->server_id)->update($savedBudget);$clock->instant=$savedTime;
    }
}}
$savedBalance=(array)$row($a);$savedTime=$clock->instant;
try{
    $clock->instant=$clock->now()->modify('+60 seconds');$readState->failedResult=true;
    same('read_failed',$balances->refresh($a->server_id),'Consumed failed balance reports a failed read');
    same('read_failed',$row($a)->last_error_code,'Consumed failure invalidates retained display');
    same($clock->now()->getTimestamp(),(int)$row($a)->attempted_at,'Consumed failure records its attempt');
}finally{$readState->failedResult=false;DB::table(BalanceSchema::TABLE)->where('id',$savedBalance['id'])->update($savedBalance);$clock->instant=$savedTime;}
// Render actual Filament page; neither credentials, fingerprints nor provider error text reach HTML or Livewire state.
$shown=RegistrarBalances::facts($a,$clock->now());$serverName=App\Models\Server::findOrFail($a->server_id)->name;
$render=Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSee('Registrars')->assertSee('Add funds')->assertSee($serverName)->assertSee($shown['balance']??'Missing');
$account=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$a->server_id])->assertSee('Check balance')->assertSee('Add funds')->assertSee($shown['balance']??'Missing');
$html=$render->html().$account->html();foreach(['https://cp.enom.com/','https://ote.namesilo.com/cart/add-funds','noopener noreferrer'] as $needle){expect(str_contains($html,$needle),'Rendered account structure and official external links: '.$needle);}
expect(!str_contains($html,'https://www.namesilo.com/cart/add-funds'),'OTE accounts never link to live funding');
$liveServer=App\Models\Server::create(['name'=>'Synthetic live balance','extension'=>'Domains','type'=>'server','enabled'=>true]);
$manualBindings->configure($liveServer->id,'namesilo',Environment::Live,['token'=>'synthetic-live-balance-account']);
Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSeeHtml('https://www.namesilo.com/cart/add-funds');
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$liveServer->id])->assertSeeHtml('https://www.namesilo.com/cart/add-funds')->assertDontSeeHtml('https://ote.namesilo.com/cart/add-funds');
foreach(['PRIVATE-PROVIDER-REPLY','account-enom','account-namesilo',$a->account_fingerprint,$foreign->fingerprint,'domains_credentials'] as $secret){expect(!str_contains($html,$secret),'HTML and state redact private evidence');}
// Staff alerts use the real native inbox independently of Filament's optional inbox flag.
staffAlertFixture();
$forecastQueries=0;
DB::listen(static function($query)use(&$forecastQueries):void {
    if(str_starts_with($query->sql,'select')&&str_contains($query->sql,'"domains_prices"')){$forecastQueries++;}
});
$secondStaff=staffUser([Staff::VIEW]);$customer=commerceUser();
$d->update(['billing_managed'=>true,'provider_expires_at'=>'2026-11-01']);
$rate->update(['revision'=>$rate->revision+1,'observed_at'=>$clock->now(),'fresh_until'=>$clock->now()->modify('+3 days')]);
$clock->instant=$clock->now()->modify('+1 day');$balances->daily($a);
$assertAlerts=static fn()=>staffAlerts('Low balance',$serverName.' balance is below renewals due in 30 days.',route('filament.admin.pages.registrar-setup'),[$staff,$secondStaff]);
$transactions=0;
\Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class,static function()use(&$transactions):void {$transactions++;});
$assertAlerts();$forecastBefore=$forecastQueries;$transactionsBefore=$transactions;$balances->daily($a);$assertAlerts();
same($forecastBefore,$forecastQueries,'Unclaimed daily turn skips the balance forecast');
same($transactionsBefore+1,$transactions,'Idle balance alert skips its locking transaction after the daily claim');
// Re-enter the alert path on the same UTC day without bypassing its retained day marker.
DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->update(['daily_due_at'=>0]);
$clock->instant=$clock->now()->modify('+60 seconds');$balances->daily($a);$assertAlerts();
same('Low balance',RegistrarBalances::facts($a,$clock->now())['status'],'Dashboard warning accompanies the native inbox alert');
// Failed inbox persistence retries delivery from the retained reading, never from another provider read.
$notificationFailure='veto';$replacement=null;
$clock->instant=$clock->now()->modify('+1 day');
App\Models\Notification::creating(static function($n)use(&$notificationFailure,&$replacement,$a,$staff){
    if($n->title!=='Low balance'||!$notificationFailure||(int)$n->user_id!==(int)$staff->id){return;}
    if($notificationFailure==='throw'){throw new RuntimeException('Synthetic inbox failure');}
    return false;
});
// Replace the claim after the failed recipient savepoint rolls back.
\Illuminate\Support\Facades\Log::listen(static function($event)use(&$replacement,$a):void {
    if($replacement!==null&&$event->message==='Staff inbox notification could not be saved.'){
        DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->update(['daily_due_at'=>$replacement]);
    }
});
foreach(['veto','throw','missing-inbox','missing-receipts'] as $failure){
    $clock->instant=$clock->now()->modify('+1 day');$rate->update(['revision'=>$rate->revision+1,'fresh_until'=>$clock->now()->modify('+3 days')]);
    $notificationFailure=in_array($failure,['veto','throw'],true)?$failure:false;
    DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->update(['daily_due_at'=>0,'notified_on'=>null]);
    $unavailable=match($failure){'missing-inbox'=>'notifications','missing-receipts'=>\Paymenter\Extensions\Servers\Domains\Services\StaffAlertSchema::TABLE,default=>null};
    $calls=$readState->calls;$wireBefore=$balanceReads();$sentBefore=App\Models\Notification::where('title','Low balance')->where('user_id',$secondStaff->id)->count();
    if($unavailable){Schema::rename($unavailable,$unavailable.'_unavailable');}
    try{
        $readAt=$clock->now()->getTimestamp();$forecastQueries=0;$balances->daily($a);
        same(1,$forecastQueries,'Granted daily read evaluates one balance forecast');
        foreach([30=>1,59=>1,60=>2,119=>2,120=>2,179=>2,180=>3,419=>3,420=>4,899=>4,900=>5,1859=>5,1860=>6,3779=>6,3780=>7,7379=>7,7380=>8]as$offset=>$expected){
            $clock->instant=(new DateTimeImmutable('@'.($readAt+$offset)));$balances->daily($a);
            same($expected,$forecastQueries,'Persistent '.$failure.' forecasts and delivers only on bounded due retries at '.$offset);
        }
        same($wireBefore+1,$balanceReads(),'Persistent '.$failure.' retries alerts with exactly one daily balance call');
        same($calls+1,$readState->calls,'Persistent '.$failure.' retries alerts with exactly one daily provider read');
        same($readAt+86400,(int)$row($a)->daily_due_at,'Alert failure preserves actual provider read schedule');
        same($readAt,(int)$row($a)->read_at,'Alert retries retain the original reading timestamp');
        same(null,$row($a)->notified_on,'Failed '.$failure.' keeps alert retryable');
    }finally{if($unavailable){Schema::rename($unavailable.'_unavailable',$unavailable);}}
    $notificationFailure=false;$clock->instant=new DateTimeImmutable('@'.($readAt+10980));$balances->daily($a);
    same($calls+1,$readState->calls,'Recovered alert never re-reads provider');same($wireBefore+1,$balanceReads(),'Recovered alert uses no balance call');
    $deliveryDays=$unavailable?[gmdate('Y-m-d',$readAt+10980)]:array_unique(array_map(static fn($offset)=>gmdate('Y-m-d',$readAt+$offset),[0,60,180,420,900,1860,3780,7380,10980]));
    same($sentBefore+count($deliveryDays),App\Models\Notification::where('title','Low balance')->where('user_id',$secondStaff->id)->count(),'Partial retries deliver once per recipient per actual UTC day');
    same(gmdate('Y-m-d',$readAt+10980),$row($a)->notified_on,'Recovery acknowledges the actual UTC delivery day');
}
// Due retries revalidate the retained balance and stop when it is no longer low.
$clock->instant=$clock->now()->modify('+1 day');$rate->update(['revision'=>$rate->revision+1,'fresh_until'=>$clock->now()->modify('+3 days')]);
$notificationFailure='veto';
DB::table(BalanceSchema::TABLE)->where('id',$row($a)->id)->update(['daily_due_at'=>0,'notified_on'=>null]);
$balances->daily($a);
$alertQuery=fn()=>DB::table(\Paymenter\Extensions\Servers\Domains\Services\BalanceAlertSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint);
expect($alertQuery()->value('pending_day')!==null,'Failed low balance delivery remains pending');
BalanceReads::record($binding,new Money('999.00','USD'),$clock->now(),'manual');$forecastBefore=$forecastQueries;
$clock->instant=$clock->now()->modify('+60 seconds');$balances->daily($a);
same(null,$alertQuery()->value('pending_day'),'A repaired balance clears pending low balance delivery');
same($forecastBefore+1,$forecastQueries,'Due retry revalidates low balance exactly once');
$forecastBefore=$forecastQueries;$clock->instant=$clock->now()->modify('+60 seconds');$balances->daily($a);
same($forecastBefore,$forecastQueries,'Cleared low balance work does not rebuild the forecast');
$notificationFailure=false;
$clock->instant=$clock->now()->modify('+1 day');$rate->update(['revision'=>$rate->revision+1,'fresh_until'=>$clock->now()->modify('+3 days')]);$notificationFailure='veto';$replacement=$clock->now()->getTimestamp()+172800;
DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->update(['daily_due_at'=>0,'notified_on'=>null]);
$balances->daily($a);same($replacement,(int)$row($a)->daily_due_at,'Failure cannot release a replacement daily claim');$notificationFailure=false;
// The quota-refusal twin must also preserve a replacement claim.
$clock->instant=$clock->now()->modify('+60 seconds');
DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->update(['daily_due_at'=>0]);
$replacement=$clock->now()->getTimestamp()+172800;
$replacedLimiter=new class($a,$replacement) implements Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter {
    public function __construct(private Account $account,private int $replacement){}
    public function acquire(AccountBinding $binding,RequestOrigin $origin):string{
        DB::table(BalanceSchema::TABLE)->where('server_id',$this->account->server_id)->where('account_fingerprint',$this->account->account_fingerprint)->update(['daily_due_at'=>$this->replacement]);
        throw new Paymenter\Extensions\Servers\Domains\Services\ReadDeferred('Synthetic quota refusal');
    }
    public function release(AccountBinding $binding,RequestOrigin $origin,string $token):void{}
};
(new RegistrarBalances($manualBindings,$clock,$replacedLimiter))->daily($a);same($replacement,(int)$row($a)->daily_due_at,'Quota refusal cannot release replacement claim');
// A recipient delivered after midnight remains deduplicated on that UTC day.
$notificationFailure='veto';$replacement=null;
$clock->instant=$clock->now()->setTimezone(new DateTimeZone('UTC'))->modify('+2 days')->setTime(23,59,30);
$midnightStart=$clock->now();
$rate->update(['revision'=>$rate->revision+1,'observed_at'=>$clock->now(),'fresh_until'=>$clock->now()->modify('+3 days')]);
DB::table(BalanceSchema::TABLE)->where('id',$row($a)->id)->update(['daily_due_at'=>0,'notified_on'=>null]);
$alertQuery()->update(['pending_day'=>null,'retry_at'=>0,'attempts'=>0,'claim'=>null]);
$recipientCount=fn()=>App\Models\Notification::where('title','Low balance')->where('user_id',$staff->id)->count();
$midnightBefore=$recipientCount();$midnightReads=$readState->calls;
$balances->daily($a);
expect(RegistrarBalances::facts($a,$clock->now())['low'],'Midnight probe uses a proven low reading');
same($midnightBefore,$recipientCount(),'Selected recipient fails before midnight');
same($midnightStart->getTimestamp()+86400,(int)$row($a)->daily_due_at,'Daily spacing is unchanged');
$notificationFailure=false;$clock->instant=$midnightStart->modify('+60 seconds');
$balances->daily($a);
same($midnightBefore+1,$recipientCount(),'Recipient recovers just after midnight');
same($midnightReads+1,$readState->calls,'Midnight alert retry does not read the provider');
same($midnightStart->getTimestamp()+86400,(int)$row($a)->daily_due_at,'Midnight alert retry leaves the daily read schedule unchanged');
same($clock->now()->format('Y-m-d'),$row($a)->notified_on,'Delayed notification records its actual UTC delivery day');
$midnightDeliveryDay=$clock->now()->format('Y-m-d');
$clock->instant=$midnightStart->modify('+1 day');
same($midnightDeliveryDay,$clock->now()->format('Y-m-d'),'Next granted daily read is on the same UTC delivery day');
$balances->daily($a);
expect(RegistrarBalances::facts($a,$clock->now())['low'],'Next daily reading remains low');
same($midnightBefore+1,$recipientCount(),'One balance alert per recipient per actual UTC delivery day');
same($midnightReads+2,$readState->calls,'Next due daily read still happens after the midnight alert retry');
same($midnightStart->getTimestamp()+172800,(int)$row($a)->daily_due_at,'Next daily read retains full provider spacing');
$role=$staff->role;$role->update(['permissions'=>[]]);try{$balances->refresh($a->server_id);expect(false,'Permission revoked between renders must refuse');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Current permission reloaded');}
BalanceSchema::install();same('6.00',$row($a)->amount,'Schema reinstall retains balance');
$migration=require dirname(__DIR__).'/Domains/database/migrations/2026_10_19_000015_create_registrar_balances.php';$migration->down();expect(Schema::hasTable(BalanceSchema::TABLE),'Rollback retains ledger');
echo "Registrar balances, forecast, exact money, permissions, daily spacing and Filament rendering passed\n";$nativeSuccessful=true;
