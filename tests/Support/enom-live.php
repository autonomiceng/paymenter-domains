<?php
// Installed live deployment, socket-free cURL; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/enom-wire.php';require_once __DIR__.'/staff-tlds.php';require_once __DIR__.'/native-commerce.php';
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,Cache,DB,Queue};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,DispatchSeal,Environment,ExecutionRecord,HistoryAcceptance,Query,RequestOrigin,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,EnomLive,EnomLiveCalls,EnomTestManagement,EnomTestCalls,EnomTestCallLimit,EnomTestCallSchema,EnomTestRegistration,Installation,NativeCatalogue,ObservationWork,Runtime,RuntimePorts,RuntimeWork,StaffSettingsReview as Review,StaffSettingsWrites,Storefront};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$clock=new class implements Clock {
    public DateTimeImmutable $at;
    public function __construct(){$this->at=new DateTimeImmutable('2026-10-21T12:00:00Z');}
    public function now():DateTimeImmutable{return $this->at;}
};
app()->instance(Clock::class,$clock);Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$session=app('session')->driver();$session->start();app('request')->setLaravelSession($session);app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$hot=storage_path('framework/domains-assets-hot');file_put_contents($hot,'http://assets.example.test');app(Illuminate\Foundation\Vite::class)->useHotFile($hot);
require_once app_path('Classes/helpers.php');tldPanel();
$rdap=new Paymenter\Extensions\Servers\Domains\Services\Rdap(static function(string $url):array{$path=parse_url($url,PHP_URL_PATH);$data=str_ends_with($path,'dns.json')?['services'=>[[['com'],['https://rdap.example.com/']]]]:['objectClassName'=>'domain','ldhName'=>basename($path),'status'=>['active'],'events'=>[['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-10-21T00:00:00Z']]];return ['status'=>200,'body'=>json_encode($data,JSON_THROW_ON_ERROR)];});
app()->instance(Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck::class,new Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck($rdap));
$staff=staffUser([...array_keys(Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()),'admin.invoices.update']);Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic live registration','type'=>'server','extension'=>'Domains','enabled'=>true]);
(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
$reply=static function(array $p)use($clock):string {
    same(0,DB::transactionLevel(),'Every physical request outside financial locks');
    same('https://reseller.enom.com/interface.asp',$GLOBALS['enomWire']['options'][CURLOPT_URL],'live endpoint only');
    $command=$p['Command'];$name=($p['SLD']??'').'.'.($p['TLD']??'com');
    if($command==='Check'&&($GLOBALS['advanceDuringPreflight']??false)){unset($GLOBALS['advanceDuringPreflight']);$clock->at=$clock->at->modify('+1 second');}
    if(isset($GLOBALS['testRegisteredName'])){$name=$GLOBALS['testRegisteredName'];}
    $bill=$clock->now()->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('n/j/Y g:i:s A');
    $body=match($command){
        'GetBalance'=>'<AvailableBalance>100.00</AvailableBalance>',
        'GetTLDList'=>'<TLDList><TLD>com</TLD></TLDList>',
        'GetTLDDetails'=>'<TLDs><TLD><TLD>com</TLD><Registration><Minimum>1</Minimum><Maximum>1</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs>',
        'GetExtAttributes'=>'<ResponseCount>0</ResponseCount>',
        'PE_GetProductPrice'=>'<ProductPrice><ProductEnabled>true</ProductEnabled><Price>10.0000</Price></ProductPrice>',
        'Check'=>'<Domains><Domain><Name>'.$name.'</Name><RRPCode>'.(($GLOBALS['testTransferQuery']??false)?'211':(($GLOBALS['testNameUnavailable']??false)?'211':'210')).'</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP>'.(($GLOBALS['testTransferQuery']??false)?'<Properties><Transferable>true</Transferable></Properties>':'').'</Domain></Domains>',
        'Purchase'=>'<RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-21</ExpirationDate>',
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">'.$name.'</DomainName><Status><Belongs-To>live-uid</Belongs-To><Expiration>2027-10-21</Expiration></Status></GetDomainInfo>',
        'GetDomainExp'=>'<ExpirationDate>2027-10-21</ExpirationDate>',
        'GetDNS'=>'<dns>ns1.provider.example</dns><dns>ns2.provider.example</dns>',
        'GetRenew'=>'<RenewName>0</RenewName>','GetRegLock'=>'<Reg-Lock>1</Reg-Lock>',
        'GetWPPSInfo'=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
        'GetOrderDetail'=>'<Order><OrderID>1234</OrderID><Result>true</Result><OrderBillAmount>$10.00</OrderBillAmount><BillDate>'.$bill.'</BillDate><OrderDetail><Description>'.$name.'</Description><ProductType>Register</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>10.00</AmountPaid><ExpiryDateTime>2027-10-21</ExpiryDateTime></OrderDetail></Order>',
        default=>throw new LogicException('Unreviewed live command '.$command),
    };
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
resetWire(['reply'=>$reply]);
$settings=app(StaffSettingsWrites::class);$bindings=app(AccountBindings::class);$ports=app(RuntimePorts::class);
expect($settings->connectionAvailable(),'Installed live phase boots staff connection checks');
$write=static function(string $action,array $data=[],?int $tld=null)use($settings,$staff,$server):void {
    $r=new Review($staff->id,$server->id,$tld,$action,Review::ticket($staff->id,$server->id,$tld,$action,Review::stamp($server->id,$tld)),'Review synthetic live registration');$settings->run($r,$data);
};
$write('configure',['registrar'=>'enom','environment'=>'live','uid'=>'live-uid','token'=>'LIVE-SECRET-TOKEN']);
$a=Account::where('server_id',$server->id)->sole();$binding=CatalogueAcceptances::binding($a);
$bindings->confirmLiveSite($server->id,'https://my.becotech.com');
$deny=static function(callable $work):void {try{$work();throw new LogicException('Expected refusal');}catch(DomainException){}};
$actions=['catalogue','registration','renewal','management'];
foreach($actions as $action){same(false,Paymenter\Extensions\Servers\Domains\Services\EnomLive::enabled($binding,$action),'Live actions default off');}
$accountConfig=['fingerprint'=>$binding->fingerprint,'daily_call_cap'=>1000,'daily_spend_limit_usd'=>'100.00'];
config(['domains.enom_live.site'=>'https://my.becotech.com','domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);
foreach($actions as $action){config(['domains.enom_live.'.$action=>true]);}
expect($ports->accepted($a->fresh()),'Valid live composition is available');
foreach($actions as $action){
    config(['domains.enom_live.'.$action=>false]);
    expect(!Paymenter\Extensions\Servers\Domains\Services\EnomLive::enabled($binding,$action),'Each action requires its own opt-in');
    if($action==='catalogue'){foreach($actions as $dependent){expect(!EnomLive::enabled($binding,$dependent),'Every live action also requires catalogue');}}
    config(['domains.enom_live.'.$action=>true]);
}
// Exercise the private wire independently of the engine's earlier guards.
$reflection=new ReflectionClass(Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::class);$wire=$reflection->newInstanceWithoutConstructor();$credentialReads=0;
$reflection->getConstructor()->invoke($wire,$binding,static function()use(&$credentialReads):array {$credentialReads++;return ['uid'=>'live-uid','token'=>'LIVE-SECRET-TOKEN'];});
$exchange=static fn(string $role)=>(new ReflectionMethod($wire,'exchange'))->invoke($wire,$role);
$typedRefusal=static function(callable $work):void {try{$work();throw new LogicException('Expected typed pre-send refusal');}catch(EnomTestCallLimit){}};
$wireBefore=count($GLOBALS['enomWire']['forms']);
foreach(['catalogue'=>['management','GetBalance'],'registration'=>['registration','ModifyNS'],'renewal'=>['renewal','Extend'],'management'=>['management','SetRegLock']] as $action=>[$role,$command]){
    config(['domains.enom_live.'.$action=>false]);$beforeCredentials=$credentialReads;
    $typedRefusal(fn()=>$exchange($role)(EnomLive::route(),$command,[],25000));same($beforeCredentials,$credentialReads,'Physical action opt-out refuses before credentials');
    config(['domains.enom_live.'.$action=>true]);
}
foreach(['renewal'=>'Extend','management'=>'SetRegLock'] as $role=>$command){$typedRefusal(fn()=>$exchange($role)(EnomLive::route(),$command,[],25000));}
config(['domains.enom_live.accounts'=>['live-uid'=>[...$accountConfig,'daily_call_cap'=>0]]]);
$typedRefusal(fn()=>(new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\CurlTransport)->send(Environment::Live,Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Balance,[],'live-uid','LIVE-SECRET-TOKEN',25000));
config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);$typedRefusal(fn()=>EnomLiveCalls::read('unbound-live-uid'));
same($wireBefore,count($GLOBALS['enomWire']['forms']),'Physical guards send no fake requests');
$before=count($GLOBALS['enomWire']['forms']);
foreach(['https://stage.example.com',null,'http://my.becotech.com'] as $site){
    config(['domains.enom_live.site'=>$site]);expect(!$ports->accepted($a->fresh()),'Site mismatch refuses composition');
    $deny(fn()=>Paymenter\Extensions\Servers\Domains\Services\EnomLiveCalls::reserve($binding));
}
config(['domains.enom_live.site'=>'https://my.becotech.com','app.url'=>'https://copied.example.com']);expect(!$ports->accepted($a->fresh()),'Copied application URL refuses live composition');$deny(fn()=>EnomLiveCalls::reserve($binding));config(['app.url'=>'https://my.becotech.com']);
DB::table('domains_accounts')->where('server_id',$server->id)->update(['live_site'=>'https://copied.example.com']);expect(!$ports->accepted($a->fresh()),'Retained site must match deployment');$deny(fn()=>EnomLiveCalls::reserve($binding));DB::table('domains_accounts')->where('server_id',$server->id)->update(['live_site'=>'https://my.becotech.com']);
config(['domains.enom_live.accounts'=>['live-uid'=>[...$accountConfig,'fingerprint'=>str_repeat('f',64)]]]);
expect(!$ports->accepted($a->fresh()),'Wrong account binding refuses composition');
config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);
try{EnomLive::account(new AccountBinding('enom',$server->id,Environment::Test,'live-uid'));throw new LogicException('TEST account must be refused live');}catch(DomainException $error){same('Live Enom account required.',$error->getMessage(),'TEST environment refused before account resolution');}
foreach([[$binding,'https://resellertest.enom.com/interface.asp'],[new AccountBinding('enom',$server->id,Environment::Test,'live-uid'),EnomLive::route()]] as [$routeBinding,$route]){try{new Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance($routeBinding,1,[],$route,25);throw new LogicException('Crossed route must be refused');}catch(InvalidArgumentException){}}
// A retained TEST identity cannot be rebound through a different live server.
$testServer=Server::create(['name'=>'Retained TEST','type'=>'server','extension'=>'Domains','enabled'=>true]);
Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$testServer->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'live-uid']));
expect(!$ports->accepted($a->fresh()),'Same TEST UID on another server refuses live');
$deny(fn()=>Paymenter\Extensions\Servers\Domains\Services\EnomLiveCalls::reserve($binding));
// Remove this synthetic conflicting row directly; production bindings are retained.
DB::table('domains_accounts')->where('server_id',$testServer->id)->delete();
same($before,count($GLOBALS['enomWire']['forms']),'Binding refusals send nothing');
$write('probe');
$deny(fn()=>$write('acceptance',['ending'=>'com','accept'=>true,'terms'=>[1],'registry'=>true,'verified'=>true,'test_registration_accepted'=>true]));
$write('acceptance',['ending'=>'com','accept'=>true,'terms'=>[1],'registry'=>true,'verified'=>true,'live_registration_accepted'=>true]);
foreach(['renewal','management'] as $action){
    $class='Paymenter\\Extensions\\Servers\\Domains\\Services\\EnomTest'.ucfirst($action);
    $server->settings()->create(['key'=>$class::KEY,'encrypted'=>true,'type'=>'string','value'=>json_encode(['fingerprint'=>$binding->fingerprint,...$class::protocol($binding)])]);
    expect(!$class::accepted($binding),'A TEST acceptance row with even the live fingerprint and route grants no live authority');
    $deny(fn()=>$write('test_'.$action,['accept'=>true,'verified'=>true]));
    $write('live_'.$action,['accept'=>true,'verified'=>true]);expect($class::accepted($binding),'Independent live acceptance');
    $write('live_'.$action,['accept'=>false]);expect(!$class::accepted($binding),'Live revocation');
    $write('live_'.$action,['accept'=>true,'verified'=>true]);
}
$write('default_rule',['mode'=>'markup_percent','markup'=>'30.00','round_up'=>'none']);
config(['domains.enom_live.catalogue'=>false]);$before=count($GLOBALS['enomWire']['forms']);$deny(fn()=>$write('import',['ending'=>'com']));same($before,count($GLOBALS['enomWire']['forms']),'Catalogue off refuses import before send');config(['domains.enom_live.catalogue'=>true]);
$write('import',['ending'=>'com']);$tld=Tld::where('server_id',$server->id)->sole();
$write('tld_settings',['enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true],$tld->id);
$write('publish',[],$tld->id);$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$tld->id);
Illuminate\Support\Facades\Schema::create('cache',static function($t):void {$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration')->index();});
Illuminate\Support\Facades\Schema::create('cache_locks',static function($t):void {$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
config(['cache.default'=>'database','session.driver'=>'database','queue.default'=>'database','queue.connections.database.retry_after'=>900]);Queue::fake();
$write('processing',['enabled'=>true,'confirmed'=>true]);
$observations=app(ObservationWork::class);$observations->enroll($server->id);$before=count($GLOBALS['enomWire']['forms']);$observations->tick();same($before,count($GLOBALS['enomWire']['forms']),'Live catalogue keeps the TEST staff-only refresh policy');same(null,DB::table('domains_observation_queues')->where('server_id',$server->id)->value('catalogue_hold'),'No spurious automatic catalogue failure');
config(['domains.enom_live.catalogue'=>false]);$observations->tick();same($before,count($GLOBALS['enomWire']['forms']),'Catalogue opt-out sends no scheduled refresh');same(null,DB::table('domains_observation_queues')->where('server_id',$server->id)->value('catalogue_hold'),'Catalogue opt-out creates no false failure');config(['domains.enom_live.catalogue'=>true]);
Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class)->assertSee('Enom live registration')->assertSee('Live renewal acceptance')->assertDontSee('LIVE-SECRET-TOKEN');
$user=commerceUser();$user->update(['email'=>'live-owner@example.com']);
foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
$product=app(NativeCatalogue::class)->product($tld,'register');$worker=app(RuntimeWork::class);
$new=static function(string $name,bool $pay=true)use($user,$product):Operation {
    $cart=commerceCart($user);commerceAdd($cart,$product,$name);commerceCheckout($user,$cart)->assertHasNoErrors();
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    if($pay){App\Helpers\ExtensionHelper::addPayment($op->invoice_id,null,$op->amount);}
    return $op->fresh();
};
$preview=commerceCart($user);commerceAdd($preview,$product,placeholderComFixtureName('live-preview-placeholder.com'));$orders=App\Models\Order::count();$invoices=App\Models\Invoice::count();config(['domains.enom_live.registration'=>false]);
commerceCheckout($user,$preview)->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>'Domains can’t be ordered right now. Remove them to check out.','type'=>'error']);
same([$orders,$invoices],[App\Models\Order::count(),App\Models\Invoice::count()],'Live preview-only checkout creates no native debt');config(['domains.enom_live.registration'=>true]);
$unpaid=$new(placeholderComFixtureName('live-unpaid-placeholder.com'),false);$before=count($GLOBALS['enomWire']['forms']);$worker->operation($unpaid->id);
same($before,count($GLOBALS['enomWire']['forms']),'Live unpaid invoice refuses before send');
$unpaidState=$unpaid->fresh()->state;$unpaid->update(['state'=>'running']);config(['domains.enom_live.registration'=>false,'domains.enom_live.renewal'=>false,'domains.enom_live.management'=>false]);
expect(!$ports->accepted($a->fresh()),'An unsealed unresolved operation grants no recovery composition');
$unpaid->update(['state'=>$unpaidState]);config(['domains.enom_live.registration'=>true,'domains.enom_live.renewal'=>true,'domains.enom_live.management'=>true]);
$blocked=$new(placeholderComFixtureName('live-off-placeholder.com'));config(['domains.enom_live.registration'=>false]);$before=count($GLOBALS['enomWire']['forms']);$worker->operation($blocked->id);same($before,count($GLOBALS['enomWire']['forms']),'Registration off sends no request');expect(!isset($blocked->fresh()->attempt['dispatch_attempted_at']),'Registration off remains unsealed');config(['domains.enom_live.registration'=>true]);
$op=$new(placeholderComFixtureName('live-paid-placeholder.com'));$before=count($GLOBALS['enomWire']['forms']);
$worker->operation($op->id);same('pending',$op->fresh()->state,'Live registration acknowledged: '.json_encode([$op->fresh()->state,$op->fresh()->safe_code]));
same(['Check','PE_GetProductPrice','GetBalance','Purchase'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Identical registration preflight and single send');
$GLOBALS['testRegisteredName']=$op->domain->domain;config(['domains.enom_live.registration'=>false,'domains.enom_live.renewal'=>false,'domains.enom_live.management'=>false]);Cache::flush();$worker->operation($op->id);
same('succeeded',$op->fresh()->state,'Live registration confirmed by original charge and full-term expiry');
same(['ns1.provider.example','ns2.provider.example'],$op->domain->fresh()->observation['nameservers']['nameservers']??null,'Nameservers are read during ownership reconciliation');
same('10.00',$op->fresh()->cost['actual']['amount'],'Live registration actual USD charge');
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($op->id);same($before,count($GLOBALS['enomWire']['forms']),'Duplicate live registration worker sends nothing');
expect(!$ports->accepted($a->fresh()),'Finished sealed operations grant no recovery composition');
unset($GLOBALS['testRegisteredName']);config(['domains.enom_live.registration'=>true,'domains.enom_live.renewal'=>true,'domains.enom_live.management'=>true]);
$liveTable=Paymenter\Extensions\Servers\Domains\Services\EnomLiveCallSchema::TABLE;
same(10000000,(int)DB::table($liveTable)->value('spend_micros'),'One admitted registration reserves ten USD exactly');
// Limits refuse before both the fake wire and the durable seal.
foreach(['calls','spend'] as $limit){
    Cache::flush();$clock->at=$clock->at->modify('+61 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $blocked=$new(placeholderComFixtureName('live-'.$limit.'-placeholder.com'));$used=(int)DB::table($liveTable)->value('used');
    config(['domains.enom_live.accounts'=>['live-uid'=>[...$accountConfig,'daily_call_cap'=>$limit==='calls'?$used+3:1000,'daily_spend_limit_usd'=>$limit==='spend'?'10.00':'100.00']]]);
    $before=count($GLOBALS['enomWire']['forms']);$worker->operation($blocked->id);
    same('held',$blocked->fresh()->state,'Live limit holds unsent work');same([],array_values(array_filter(array_slice($GLOBALS['enomWire']['forms'],$before),fn($p)=>$p['Command']==='Purchase')),'Live limit sends no Purchase');
    expect(!isset($blocked->fresh()->attempt['dispatch_attempted_at']),'Limit precedes durable seal');
    config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);
}
// Management uses the same engine, pre-seal reservation and retained read-only recovery.
$d=$op->domain()->firstOrFail();$engine=$ports->managementEngine($binding);$driver=$ports->management($binding);
$ask=static fn(bool $locked)=>$engine->request($user,$d->id,'lock',['locked'=>$locked],bin2hex(random_bytes(32)),$driver);
config(['domains.enom_live.management'=>false]);$before=count($GLOBALS['enomWire']['forms']);$deny(fn()=>$ask(false));same($before,count($GLOBALS['enomWire']['forms']),'Management off refuses even a cached engine');config(['domains.enom_live.management'=>true]);
$s=(object)['locked'=>true,'fault'=>''];
$manageReply=static function(array $p)use($s,$d,$reply):string {
    same('https://reseller.enom.com/interface.asp',$GLOBALS['enomWire']['options'][CURLOPT_URL],'Live management endpoint');
    if($p['Command']===$s->fault){return 'malformed';}
    if($p['Command']==='SetRegLock'){$s->locked=$p['UnlockRegistrar']==='0';}
    $body=match($p['Command']){
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">'.$d->domain.'</DomainName><Status><Belongs-To>live-uid</Belongs-To><Expiration>2027-10-21</Expiration></Status></GetDomainInfo>',
        'SetRegLock'=>'','GetRegLock'=>'<Reg-Lock>'.(int)$s->locked.'</Reg-Lock>',default=>null,
    };return $body===null?$reply($p):'<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
Cache::flush();resetWire(['reply'=>$manageReply]);$change=$ask(false);$used=(int)DB::table($liveTable)->value('used');
config(['domains.enom_live.accounts'=>['live-uid'=>[...$accountConfig,'daily_call_cap'=>$used+1]]]);$worker->operation($change->id);
same(['held','absent'],[$change->fresh()->state,$change->fresh()->attempt['seal']],'Management cap refuses before seal');same(['GetDomainInfo'],array_column($GLOBALS['enomWire']['forms'],'Command'),'Management cap sends no mutation');
config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);$engine->retire($user,$change->id);
Cache::flush();resetWire(['reply'=>$manageReply]);$change=$ask(false);$GLOBALS['enomWire']['during']=static function(array $p)use($s):void {if($p['Command']==='SetRegLock'){$s->fault='GetRegLock';}};$worker->operation($change->id);same('uncertain',$change->fresh()->state,'Partial live management readback stays uncertain');
$s->fault='';Cache::flush();resetWire(['reply'=>$manageReply]);config(['domains.enom_live.registration'=>false,'domains.enom_live.renewal'=>false,'domains.enom_live.management'=>false]);$worker->operation($change->id);
same('succeeded',$change->fresh()->state,'Management opt-out preserves retained read-only reconciliation');expect(!in_array('SetRegLock',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Reconciliation never repeats mutation');config(['domains.enom_live.registration'=>true,'domains.enom_live.renewal'=>true,'domains.enom_live.management'=>true]);
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($change->id);same($before,count($GLOBALS['enomWire']['forms']),'Duplicate management completion sends nothing');
// A paid renewal uses the same native invoice, two assessments and captured-order reconciliation.
$clock->at=new DateTimeImmutable('2027-09-30T12:00:00Z');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());Cache::flush();
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$billing=new Paymenter\Extensions\Servers\Domains\Services\DomainBilling($bindings,app(Paymenter\Extensions\Servers\Domains\Services\NativeDomainGuard::class),$clock);
$invoice=$billing->invoice($op->domain_id,$user);same($invoice->id,$billing->invoice($op->domain_id,$user)->id,'Duplicate renewal reuses native invoice');
$renew=Operation::where('invoice_id',$invoice->id)->sole();App\Helpers\ExtensionHelper::addPayment($invoice->id,null,$renew->amount);
$state=(object)['name'=>$op->domain->domain,'after'=>false,'bill'=>''];
$renewReply=static function(array $p)use($state,$clock):string {
    same(0,DB::transactionLevel(),'Live renewal outside financial locks');same('https://reseller.enom.com/interface.asp',$GLOBALS['enomWire']['options'][CURLOPT_URL],'Live renewal endpoint');
    $body=match($p['Command']){
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">'.$state->name.'</DomainName><Status><Belongs-To>live-uid</Belongs-To><Expiration>'.($state->after?'2028-10-21':'10/21/2027 5:00:00 AM').'</Expiration></Status></GetDomainInfo>',
        'GetExtendInfo'=>'<Expiration>10/21/2027</Expiration><Price>10.00</Price><RegistrarHold>False</RegistrarHold><MaxExtension>8</MaxExtension>',
        'Check'=>'<Domains><Domain><Name>'.$state->name.'</Name><RRPCode>211</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Prices><Currency/><Renewal>10.00</Renewal></Prices></Domain></Domains>',
        'GetRenew'=>'<RenewName>0</RenewName>','GetBalance'=>'<AvailableBalance>100.00</AvailableBalance>',
        'Extend'=>'<RRPCode>200</RRPCode><OrderID>9002</OrderID>',
        'GetDomainExp'=>'<ExpirationDate>2028-10-21</ExpirationDate>',
        'GetOrderDetail'=>'<Order><OrderID>9002</OrderID><Result>true</Result><OrderBillAmount>$10.00</OrderBillAmount><BillDate>'.$state->bill.'</BillDate><OrderDetail><Description>'.$state->name.'</Description><ProductType>Renew</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>10.00</AmountPaid><ExpiryDateTime>2028-10-21</ExpiryDateTime></OrderDetail></Order>',
        default=>throw new LogicException('Unexpected live renewal command '.$p['Command']),
    };return '<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
$release=static function()use($renew,$clock):void {DB::transaction(static function()use($renew,$clock):void {$l=Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($renew->domain()->firstOrFail());Paymenter\Extensions\Servers\Domains\Services\StaffOperationWrites::release($l['operations']->firstWhere('id',$renew->id),$l,$clock->now());});};
resetWire(['reply'=>$renewReply]);Cache::flush();config(['domains.enom_live.renewal'=>false]);$worker->operation($renew->id);same([],$GLOBALS['enomWire']['forms'],'Renewal off sends no request');expect(!isset($renew->fresh()->attempt['dispatch_attempted_at']),'Renewal off remains unsealed');config(['domains.enom_live.renewal'=>true]);same('queued',$renew->fresh()->state,'Off renewal remains queued for fresh admission');
config(['domains.enom_live.accounts'=>['live-uid'=>[...$accountConfig,'daily_call_cap'=>1]]]);Cache::flush();$worker->operation($renew->id);same('held',$renew->fresh()->state,'Renewal call cap holds unsent intent');expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Renewal call cap sends no Extend');expect(!isset($renew->fresh()->attempt['dispatch_attempted_at']),'Renewal call refusal precedes seal');config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);$release();
config(['domains.enom_live.accounts'=>['live-uid'=>[...$accountConfig,'daily_spend_limit_usd'=>'0.00']]]);Cache::flush();$worker->operation($renew->id);same('held',$renew->fresh()->state,'Renewal spend limit holds unsent intent');expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Renewal spend limit sends no Extend');expect(!isset($renew->fresh()->attempt['dispatch_attempted_at']),'Renewal spend refusal precedes seal');config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);$release();
resetWire(['reply'=>$renewReply]);Cache::flush();$worker->operation($renew->id);
same('pending',$renew->fresh()->state,'Live renewal acknowledged');same(1,count(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='Extend')),'One live Extend');
same(10000000,(int)DB::table($liveTable)->where('day',EnomTestCalls::day())->value('spend_micros'),'Renewal reserves exact admitted USD cost on its UTC day');
$state->after=true;$state->bill=(new DateTimeImmutable($renew->fresh()->attempt['dispatch_attempted_at']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('n/j/Y g:i:s A');
config(['domains.enom_live.registration'=>false,'domains.enom_live.renewal'=>false,'domains.enom_live.management'=>false]);Cache::flush();$worker->operation($renew->id);same('succeeded',$renew->fresh()->state,'Live renewal confirmed by original charge and full-year expiry');
same('2028-10-21',$renew->domain()->firstOrFail()->provider_expires_at->format('Y-m-d'),'Live expiry advances one year');
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($renew->id);same($before,count($GLOBALS['enomWire']['forms']),'Duplicate completed renewal sends nothing');config(['domains.enom_live.registration'=>true,'domains.enom_live.renewal'=>true,'domains.enom_live.management'=>true]);
// Invalid limits retain usage, and a UTC rollover cannot reuse a reserved mutation.
$used=(int)DB::table($liveTable)->where('day',EnomTestCalls::day())->value('used');
foreach([['daily_call_cap'=>'1000'],['daily_call_cap'=>-1],['daily_spend_limit_usd'=>10.0],['daily_spend_limit_usd'=>'-1.00'],['daily_spend_limit_usd'=>'0.0000001']] as $invalid){config(['domains.enom_live.accounts'=>['live-uid'=>[...$accountConfig,...$invalid]]]);$deny(fn()=>EnomLiveCalls::reserve($binding));}
config(['domains.enom_live.accounts'=>['live-uid'=>$accountConfig]]);same($used,(int)DB::table($liveTable)->where('day',EnomTestCalls::day())->value('used'),'Invalid limits never consume a new unit');
$day=EnomTestCalls::day();$at=$clock->at;
Paymenter\Extensions\Servers\Domains\Services\EnomTestWriteReservation::run($binding,'renewal',$renew->id,static function()use($binding,$clock,$deny):void {
    Paymenter\Extensions\Servers\Domains\Services\EnomTestWriteReservation::reserve($binding,'renewal',new Paymenter\Extensions\Servers\Domains\Data\Money('0.000001','USD'));
    $clock->at=$clock->at->modify('+1 day');$deny(fn()=>Paymenter\Extensions\Servers\Domains\Services\EnomTestWriteReservation::prepare($binding,'renewal'));
});
$clock->at=$at;same(10000001,(int)DB::table($liveTable)->where('day',$day)->value('spend_micros'),'Unsent reservation retains one exact USD millionth');
file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));
echo "Installed live registration, renewal, separate acceptance and pre-send limits passed without sockets.\n";
