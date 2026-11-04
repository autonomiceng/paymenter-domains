<?php
// Native checkout, funding, seal and physical-send guards need the real Paymenter fixture; no sockets.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance,RequestOrigin,SaleSelection};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,EnomLive,EnomLiveCalls,EnomLiveCallSchema,EnomTestTransfer as Policy,HistoryAcceptances,Installation,RuntimeWork,TransferInputs,TransferPrecheck,TransferReadBudget};
config(['domains.enom_identity.environment'=>'live','app.url'=>'https://billing.example.com']);
(new Installation)->install();
$transferEnvironment='live';
$transferConfigured=static function():void {
    global $binding,$server,$transferState;
    app(AccountBindings::class)->confirmLiveSite($server->id,'https://billing.example.com');
    config(['domains.enom_live.site'=>'https://billing.example.com','domains.enom_live.accounts'=>['live-uid'=>['fingerprint'=>$binding->fingerprint,'daily_call_cap'=>1000,'daily_spend_limit_usd'=>'100.00']],'domains.enom_live.catalogue'=>true]);
    expect(!Policy::enabled($binding),'Live transfer defaults off despite Live catalogue and TEST opt-in');
    foreach([false,'true',1,null] as $off){config(['domains.enom_live.transfer'=>$off]);expect(!Policy::enabled($binding),'Live transfer requires boolean true');}
    config(['domains.enom_live.transfer'=>true]);
    expect(Policy::enabled($binding),'Live transfer has independent deployment authority');
    $transferState->environment='live';$transferState->uid='live-uid';
};
require __DIR__.'/Support/enom-transfer-setup.php';
$deny=static function(callable $work):void {try{$work();throw new RuntimeException('Expected refusal');}catch(DomainException){}};
// Synthetic provider answer and pilot, for this offline fixture only. Real P35/P37 evidence is still absent.
$evidence=['fingerprint'=>$binding->fingerprint,'site'=>'https://billing.example.com','confirmed_on'=>'2026-10-20','reference'=>'OFFLINE synthetic transfer answer','sha256'=>hash('sha256','OFFLINE answer'),'currency'=>'USD','complete_history'=>true,
    'history'=>['registration'=>['timezone'=>'America/Los_Angeles','lag'=>0,'retention'=>86400],'renewal'=>['timezone'=>'America/Los_Angeles','lag'=>0,'retention'=>86400],'transfer'=>['timezone'=>'America/Los_Angeles','lag'=>0,'retention'=>86400]],
    'transfer_proof'=>['confirmed_on'=>'2026-10-20','reference'=>'OFFLINE synthetic pilot','sha256'=>hash('sha256','OFFLINE pilot')]];
config(['domains.enom_live.accounts.live-uid.evidence'=>$evidence,'domains.support_url'=>'https://support.example.com']);
DB::table('domains_runtime_turns')->where('server_id',$server->id)->update(['finished_at'=>$clock->now()->getTimestamp()]);
$activate=static fn()=>$write('activate',['registration'=>false,'renewal'=>false,'management'=>false,'transfer'=>true,'confirmed'=>true]);

$deny(fn()=>$write('live_transfer',['accept'=>true,'verified'=>true]));
expect(!Policy::accepted($binding),'Activate is required even with verified input');
$publicOffers=static function(bool $expected,string $stage):void {
    $store=app(\Paymenter\Extensions\Servers\Domains\Services\Storefront::class);$calls=$GLOBALS['enomWire']['forms'];Cache::flush();
    same($expected,$store->hasTransferOffers(),$stage.' cold Live transfer offer');
    $rows=array_column($store->endings(),null,'tld');same($expected,($rows['com']['transfer']??null)!==null,$stage.' full Live transfer price');
    same($expected,($rows['com']['transfersOn']??false),$stage.' Live transfer entry fallback');
    same($expected,$store->hasTransferOffers(),$stage.' warm Live transfer offer');
    same($expected?['com']:[],$store->offeredTransferEndings(['public-transfer.example.com']),$stage.' Live transfer entry link');
    same($calls,$GLOBALS['enomWire']['forms'],$stage.' public projection never calls provider');
};
$publicOffers(false,'Before acceptance');
$selection=new SaleSelection('com',$server->id,'enom',$binding->fingerprint,0,'transfer',1,'USD',0,0);
$deny(fn()=>TransferPrecheck::rules($selection));
$accept=static function(string $key,array $protocol)use($server,$binding):void {
    $server->settings()->updateOrCreate(['key'=>$key],['encrypted'=>true,'type'=>'string','value'=>json_encode(['fingerprint'=>$binding->fingerprint,...$protocol],JSON_THROW_ON_ERROR)]);
};
$protocol=['route'=>EnomLive::route(),'command'=>'TP_CreateOrder','interval'=>25];
$accept(Policy::KEY,$protocol);expect(!Policy::accepted($binding),'TEST acceptance cannot authorize Live');
$accept('domains_enom_live_transfer',[...$protocol,'route'=>'https://resellertest.enom.com/interface.asp']);expect(!Policy::accepted($binding),'TEST route cannot authorize Live');
$deny(fn()=>$write('test_transfer',['accept'=>true,'verified'=>true]));
$activate();expect(Policy::accepted($binding),'Independent Live acceptance');$write('publish',[],$tld->id);$transferProduct=app(\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class)->product($tld,'transfer');expect($transferProduct!==null,'Reviewed active Live transfer has its published native product');$publicOffers(true,'After acceptance');config(['domains.enom_live.transfer'=>false]);$publicOffers(false,'Deployment off');config(['domains.enom_live.transfer'=>true]);$publicOffers(true,'Deployment restored');
$write('live_transfer',['accept'=>false]);expect(!Policy::accepted($binding),'Live acceptance can be revoked');same(false,app(\Paymenter\Extensions\Servers\Domains\Services\Storefront::class)->hasTransferOffers(),'Revocation invalidates the warm Live offer');$publicOffers(false,'After revoke');
$write('live_transfer',['accept'=>true]);
same([60,60],TransferPrecheck::rules($selection),'What you sell is used only after Live acceptance');
$server->settings()->create(['key'=>'domains_transfer_endings','value'=>'net']);
$deny(fn()=>TransferPrecheck::rules($selection));
$server->settings()->where('key','domains_transfer_endings')->delete();
foreach(['feature_off','evidence_removed'] as $missing){
    $blockedCart=commerceCart($user);commerceAdd($blockedCart,$transferProduct,placeholderComFixtureName('live-transfer-'.str_replace('_','-',$missing).'-placeholder.com'));
    $sale=app(\Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class)->selection('com','transfer',1,$clock->now());
    if($missing==='feature_off'){$write('live_transfer',['accept'=>false]);}else{config(['domains.enom_live.accounts.live-uid.evidence'=>null]);}
    $debts=[App\Models\Order::count(),App\Models\Invoice::count(),Operation::count()];
    commerceCheckout($user,$blockedCart)->assertDispatched('notify',fn($event,$params)=>$params[0]['type']==='error');
    same($debts,[App\Models\Order::count(),App\Models\Invoice::count(),Operation::count()],$missing.' transfer has no payable debt');
    try{DB::transaction(fn()=>app(\Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class)->admit([$sale],$clock->now()));throw new LogicException('Locked transfer admission must refuse');}catch(DomainException){}
    config(['domains.enom_live.accounts.live-uid.evidence'=>$evidence]);if($missing==='feature_off'){$write('live_transfer',['accept'=>true]);}
}
$cart=commerceCart($user);commerceAdd($cart,$transferProduct,placeholderComFixtureName('live-transfer-placeholder.com'));commerceCheckout($user,$cart)->assertHasNoErrors();
$op=Domain::where('domain',placeholderComFixtureName('live-transfer-placeholder.com'))->sole()->operations()->sole();
foreach(['feature_off','evidence_removed'] as $missing){
    if($missing==='feature_off'){$write('live_transfer',['accept'=>false]);}else{config(['domains.enom_live.accounts.live-uid.evidence'=>null]);}
    $calls=count($GLOBALS['enomWire']['forms']);
    expect(app(\Paymenter\Extensions\Servers\Domains\Services\PayCheck::class)->stop(App\Models\Invoice::findOrFail($op->invoice_id))!==null,$missing.' blocks payment on an issued transfer invoice');
    same($calls,count($GLOBALS['enomWire']['forms']),'Transfer payment refusal uses local facts only');
    config(['domains.enom_live.accounts.live-uid.evidence'=>$evidence]);if($missing==='feature_off'){$write('live_transfer',['accept'=>true]);}
}
// Partial credit must not authorize collecting the rest after Live acceptance is removed.
$pay=static fn()=>Livewire\Livewire::actingAs($user)->test(App\Livewire\Invoices\Show::class,['invoice'=>App\Models\Invoice::findOrFail($op->invoice_id)])->set('selectedMethod','credit')->call('processPayment');
App\Models\Credit::updateOrCreate(['user_id'=>$user->id,'currency_code'=>'USD'],['amount'=>'0.01']);$pay();
same(['pending',1],[App\Models\Invoice::findOrFail($op->invoice_id)->status,App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->count()],'Native credit pays only part of the Live invoice');
App\Models\Credit::where('user_id',$user->id)->update(['amount'=>'500.00']);$write('live_transfer',['accept'=>false]);
$paymentRows=static fn()=>[App\Models\Invoice::findOrFail($op->invoice_id)->getRawOriginal(),App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all(),$op->fresh()->getRawOriginal(),App\Models\Credit::where('user_id',$user->id)->sole()->getRawOriginal()];
$beforePayment=$paymentRows();$calls=count($GLOBALS['enomWire']['forms']);$refusedPayment=$pay();
same($beforePayment,$paymentRows(),'Refused remainder preserves the invoice, partial receipt, operation and credit');
$refusedPayment->assertDispatched('notify',fn($event,$params)=>$params[0]===['message'=>'Domains can’t be ordered right now. Please try again later.','type'=>'error']);
same($calls,count($GLOBALS['enomWire']['forms']),'Partial-payment refusal makes no name read');
$write('live_transfer',['accept'=>true]);$pay();same('paid',App\Models\Invoice::findOrFail($op->invoice_id)->status,'Restored acceptance allows the remaining native credit payment');
$write('live_transfer',['accept'=>false]);$calls=count($GLOBALS['enomWire']['forms']);
same(null,app(\Paymenter\Extensions\Servers\Domains\Services\PayCheck::class)->stop(App\Models\Invoice::findOrFail($op->invoice_id)),'A fully paid Live invoice stays outside the pay gate');
same($calls,count($GLOBALS['enomWire']['forms']),'A fully paid Live invoice makes no name read');$write('live_transfer',['accept'=>true]);
$request=Illuminate\Http\Request::create('/code','POST',['transfer_code'=>'Live:Case-Sensitive + %']);$request->setUserResolver(fn()=>$user);app(TransferInputs::class)->submitCode($request,$op->id);
$run=static fn()=>app(RuntimeWork::class)->operation($op->id);
$reset=static function()use($server,$setupReply):void {Cache::flush();DB::table('domains_read_budgets')->where('server_id',$server->id)->delete();\Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire(['reply'=>$setupReply]);};
$reflection=new ReflectionClass(\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::class);$wire=$reflection->newInstanceWithoutConstructor();$credentialReads=0;
$reflection->getConstructor()->invoke($wire,$binding,static function()use(&$credentialReads):array {$credentialReads++;return ['uid'=>'live-uid','token'=>'SYNTHETIC-LIVE-TOKEN'];});
$exchange=$reflection->getMethod('exchange')->invoke($wire,'transfer');
foreach(['config','acceptance','calls','spend'] as $missing){
    $reset();$accountConfig=config('domains.enom_live.accounts');
    if($missing==='config'){config(['domains.enom_live.transfer'=>false]);}
    if($missing==='acceptance'){$write('live_transfer',['accept'=>false]);}
    if($missing==='calls'){config(['domains.enom_live.accounts.live-uid.daily_call_cap'=>1]);}
    if($missing==='spend'){config(['domains.enom_live.accounts.live-uid.daily_spend_limit_usd'=>'0.00']);}
    if(in_array($missing,['config','acceptance'],true)){
        try{$exchange(EnomLive::route(),'TP_CreateOrder',[],25000);throw new RuntimeException('Physical transfer should refuse');}catch(\Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit){}
        same(0,$credentialReads,'Physical transfer refuses before credentials: '.$missing);
    }
    $run();
    expect(!in_array('TP_CreateOrder',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'No Live transfer without '.$missing);
    expect(!isset($op->fresh()->attempt['dispatch_attempted_at']),'Refusal is before seal: '.$missing);
    config(['domains.enom_live.transfer'=>true,'domains.enom_live.accounts'=>$accountConfig]);$write('live_transfer',['accept'=>true]);
    if($op->fresh()->state==='held'){
        DB::transaction(static function()use($op,$clock):void {$l=\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($op->domain()->firstOrFail());\Paymenter\Extensions\Servers\Domains\Services\StaffOperationWrites::release($l['operations']->firstWhere('id',$op->id),$l,$clock->now());});
    }
}
$reset();$run();same('uncertain',$op->fresh()->state,'Live transfer pending status');
same(['Check','PE_GetProductPrice','GetBalance','Check','PE_GetProductPrice','GetBalance','TP_CreateOrder'],array_column($GLOBALS['enomWire']['forms'],'Command'),'Two fresh preflights and one Live send');
same(10000000,(int)DB::table(EnomLiveCallSchema::TABLE)->value('spend_micros'),'Transfer reserves its exact cost under the Live guard');
same(null,$op->fresh()->provider_order_id,'Transfer reference is never a billing ID');
// Unknown status remains visible and never retries the purchase; opting out still permits recovery reads.
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'enom_transfer_v1'));
config(['domains.enom_live.transfer'=>false]);$writeOff=$server->settings()->where('key','domains_enom_live_transfer')->first();$writeOff->value='null';$writeOff->save();
$transferState->pending=false;
$reset();$GLOBALS['enomWire']['reply']=static fn(array $p):string=>$p['Command']==='TP_GetOrder'?str_replace('<StatusID>3</StatusID>','<StatusID>999</StatusID>',$setupReply($p)):$setupReply($p);
$run();same('uncertain',$op->fresh()->state,'Unknown Live status holds visibly');same(true,$op->fresh()->policy['reconciliation']['staff_alert']??false,'Unknown status raises a visible review alert');expect(!in_array('TP_CreateOrder',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Unknown status cannot replay');
$clock->at=(new DateTimeImmutable('@'.$op->fresh()->policy['reconciliation']['next_at']))->setTimezone(new DateTimeZone('UTC'));Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$reset();$run();same('succeeded',$op->fresh()->state,'Live status, original history charge and full-year expiry reconcile after opt-out');
expect(in_array('TP_GetOrder',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Live status was read');expect(in_array('GetOrderDetail',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Live charge history was read');
$reset();$run();same([],$GLOBALS['enomWire']['forms'],'Duplicate completion sends nothing');
// A real provider may use a reply shape we have never seen. Preserve the sent attempt without guessing an ID.
DB::table('domains_runtime_turns')->where('server_id',$server->id)->update(['finished_at'=>$clock->now()->getTimestamp()]);
config(['domains.enom_live.transfer'=>true]);$write('live_transfer',['accept'=>true]);
$cart=commerceCart($user);$lostName=placeholderComFixtureName('live-transfer-lost-placeholder.com');commerceAdd($cart,$transferProduct,$lostName);commerceCheckout($user,$cart)->assertHasNoErrors();
$lost=Domain::where('domain',$lostName)->sole()->operations()->sole();App\Helpers\ExtensionHelper::addPayment($lost->invoice_id,null,$lost->amount);
$request=Illuminate\Http\Request::create('/code','POST',['transfer_code'=>'Another:Case + %']);$request->setUserResolver(fn()=>$user);app(TransferInputs::class)->submitCode($request,$lost->id);
$reset();$GLOBALS['enomWire']['reply']=static function(array $p)use($setupReply):string {$reply=$setupReply($p);return $p['Command']==='TP_CreateOrder'?'<interface-response><Command>TP_CreateOrder</Command><ErrCount>0</ErrCount><Done>true</Done><UnknownOrder>7002</UnknownOrder></interface-response>':$reply;};
app(RuntimeWork::class)->operation($lost->id);same('uncertain',$lost->fresh()->state,'Unknown Live acknowledgement is uncertain');
same(null,$lost->fresh()->attempt['transfer_reference']??null,'Unknown acknowledgement cannot invent a reference');
same(1,count(array_filter($GLOBALS['enomWire']['forms'],static fn(array $p)=>$p['Command']==='TP_CreateOrder')),'Unknown acknowledgement still represents one physical send');
$reset();app(RuntimeWork::class)->operation($lost->id);same('uncertain',$lost->fresh()->state,'Unknown acknowledgement never completes by guess');expect(!in_array('TP_CreateOrder',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Unknown acknowledgement cannot charge twice');
// Another worker can reserve after admission; only the atomic physical guard can preserve checkout headroom.
EnomLiveCalls::reserve($binding);
$ledger=DB::table(EnomLiveCallSchema::TABLE)->where(['account'=>hash('sha256','live-uid'),'day'=>\Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::day()]);
$ledger->update(['used'=>499]);$budget=new TransferReadBudget($clock);$origin=RequestOrigin::Background;$token=$budget->acquire($binding,$origin);
try {
    EnomLiveCalls::reserve($binding);$refused=false;
    try{$budget->run($binding,static fn()=>EnomLiveCalls::reserve($binding));}catch(\Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit){$refused=true;}
    expect($refused,'Live transfer physical reservation preserves checkout headroom after admission');
    same(500,(int)$ledger->value('used'),'Refused background reservation spends no checkout headroom');
}finally{$budget->release($binding,$origin,$token);}
EnomLiveCalls::reserve($binding);same(501,(int)$ledger->value('used'),'Checkout retains its allowance after the background scope throws');
// Price readiness uses the same entry time as the other activation checks, even across its age boundary.
$activation=\Paymenter\Extensions\Servers\Domains\Services\EnomLiveActivation::class;
$entry=$clock->now();$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server->id)->sole();
DB::table('domains_prices')->where('server_id',$server->id)->where('action','renew')
    ->update(['observed_at'=>$entry->modify('-172799 seconds')->format('Y-m-d H:i:s')]);
$missingPrice=static fn(array $problems):bool=>(bool)array_filter($problems,static fn(string $problem):bool=>str_starts_with($problem,'Costs and prices Missing.'));
$ready=$activation::problems($account,['renewal'=>true]);
expect(!$missingPrice($ready),'Renewal price at age 172799 seconds is ready');
$clock->at=$entry->modify('+1 second');
expect($missingPrice($activation::problems($account,['renewal'=>true])),'Renewal price at age 172800 seconds is missing');
$advancing=new class($entry) implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public function __construct(private DateTimeImmutable $at) {}
    public function now():DateTimeImmutable {$at=$this->at;$this->at=$this->at->modify('+1 second');return $at;}
};
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$advancing);
try{same($ready,$activation::problems($account,['renewal'=>true]),'Advancing clock preserves the activation-entry price readiness');}
finally{app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);$clock->at=$entry;}
echo "Live transfer gates, one guarded send and opt-out recovery passed without sockets.\n";$nativeSuccessful=true;
