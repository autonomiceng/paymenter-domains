<?php
// The TEST send limit keeps the unsent proof for Purchase and TP_CreateOrder. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';
require __DIR__.'/Support/enom-wire.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueAcceptance,Environment,Money};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,BalanceReads,BalanceSchema,CatalogueAcceptances,EnomTestCallLimit,EnomTestCalls,EnomTestRegistration,EnomTestSendLimit,EnomTestSendLimitSchema,EnomTestTransfer,OperationClaims,Rdap,RenewalAcceptance,Runtime,StaffOperationReview,StaffOperations,TransferPrecheck,WriteAcceptance,WriteFeatures};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
app()->instance(Clock::class,$clock);app()->instance(Runtime::class,new Runtime);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new AccountReadBudget($clock));
EnomTestSendLimitSchema::install();BalanceSchema::install();\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$binding=$bindingFor(0);
$catalogue=CatalogueAcceptances::load($binding);
DB::transaction(static fn()=>CatalogueAcceptances::saveLocked(new CatalogueAcceptance($binding,$catalogue->revision,$catalogue->endings,'https://resellertest.enom.com/interface.asp',25)));
BalanceReads::record($binding,new Money('100.00','USD'),$clock->now(),'manual');
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.transfer'=>true,
    'domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>100000]);
expect(EnomTestRegistration::enabled($binding)&&EnomTestTransfer::enabled($binding),'Real TEST registration and transfer policy enabled');
$factory=RuntimeFactory::make($binding,$freshBindings,$clock,new WriteFeatures(registration:true),new WriteAcceptance($binding,true,true,25),new RenewalAcceptance($binding),
    new TransferPrecheck(new Rdap(static fn()=>null)),static fn()=>['uid'=>'account-enom','token'=>'SYNTHETIC-TOKEN']);
$wire=(new ReflectionFunction($factory))->getStaticVariables()['wire'];
$exchange=(new ReflectionMethod($wire,'exchange'))->invoke($wire,'registration');
$transfer=(new ReflectionMethod($wire,'exchange'))->invoke($wire,'transfer');
// Preflight reads stay on the fixture's socket-free registrar, so only the write reaches the counted wire.
$preflightRegistry=new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$read=static fn($b,$c)=>$registry->reader($b,$c);
$preflightRegistry->register('enom',['token'],$verifier,$read,registrationPreflightFactory:$read);
$preflightBindings=new \Paymenter\Extensions\Servers\Domains\Services\AccountBindings($preflightRegistry);
$features=new WriteFeatures(registration:true);$accepted=new WriteAcceptance($binding,true,true,25);
$protocol=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($binding,$exchange);
$fresh=new \Paymenter\Extensions\Servers\Domains\Services\RegistrationPreflight($preflightBindings,$features,$accepted);
$bridge=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Writer($binding,$features,$accepted,static function()use(&$engine){return $engine;},$protocol);
$engine=new \Paymenter\Extensions\Servers\Domains\Services\DomainOperations($claims,$fresh,$bridge,$clock);
Cache::forget('domains-write-slot:'.$binding->fingerprint);
$sends=static fn(?string $command=null):int=>$command===null?DB::table(EnomTestSendLimitSchema::SENDS)->count():DB::table(EnomTestSendLimitSchema::SENDS)->where('command',$command)->count();
$purchaseForms=static fn():int=>count(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='Purchase'));
$accepts=static fn($p)=>'<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate></interface-response>';
$fullAfterThis=static function()use($sends):void{config(['domains.enom_test_sends_per_day'=>$sends()]);};

// (a) Purchase: the limit refuses the sealed Purchase before cURL, so the operation is held as proven unsent.
$op=$new(placeholderComFixtureName('limit-unsent-placeholder.com'));
config(['domains.provider_send_guard'=>static function($registrar,$environment,$command)use($op,$fullAfterThis):void {
    same(['test','Purchase','sealed'],[$environment,$command,$op->fresh()->attempt['seal']],'The limit is reached after the seal, right before the Purchase send');
    $fullAfterThis();
}]);
resetWire(['reply'=>$accepts]);$before=$sends('Purchase');
$result=$runRegistration($op);
same([],$GLOBALS['enomWire']['forms'],'Refused Purchase sends nothing');
same($before,$sends('Purchase'),'Refused Purchase records no attempt');
same(['held','enom_test_call_cap'],[$result->state,$result->safe_code],'Limit refusal of the sealed Purchase is held, not unknown');
same('purchase',$result->attempt['test_call_refusals'][0]['phase'],'Refusal keeps the sealed unsent proof');
expect(!array_key_exists('execution',$result->attempt)&&!array_key_exists('dispatch_attempted_at',$result->attempt),'Refusal keeps no possible-send evidence');
expect(OperationClaims::testCallRefusalRetryable($result),'Proven-unsent refusal can be released');
require __DIR__.'/Support/staff-panel.php';staffPanel();
$staff=staffUser(['admin.domains.view','admin.domains.manage',StaffOperationReview::permission('release')]);
$release=static function($op)use($staff,$clock):void {
    $key=StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,'release',StaffOperationReview::stamp($op,$op->domain->fresh()),$clock);
    (new StaffOperations($clock))->run($staff,$op->domain_id,$op->id,'release',$key,'The send limit refusal was verified as unsent');
};
$release($result);
same('queued',$op->fresh()->state,'Staff release requeues the unsent Purchase');
config(['domains.provider_send_guard'=>null,'domains.enom_test_sends_per_day'=>1000]);$clock->instant=$clock->now()->modify('+61 seconds');Cache::flush();
resetWire(['reply'=>$accepts]);
same('pending',$runRegistration($op->fresh())->state,'The released request sends once the limit has room');
same([1,$before+1],[$purchaseForms(),$sends('Purchase')],'Exactly one Purchase across refusal and retry');

// (b) Purchase: a refusal after the Purchase left (the scope is consumed) is never treated as unsent.
$clock->instant=$clock->now()->modify('+61 seconds');Cache::flush();
$sent=$new(placeholderComFixtureName('limit-after-send-placeholder.com'));
resetWire(['reply'=>$accepts,'during'=>static function(array $form)use($fullAfterThis,$binding,$sent):void {
    if($form['Command']!=='Purchase'){return;}
    same(false,EnomTestCalls::purchaseUnconsumed($binding->fingerprint,(int)$sent->id),'The Purchase scope is consumed before cURL');
    $fullAfterThis();EnomTestSendLimit::admit(Environment::Test,'account-enom','GetOrderDetail');
}]);
$result=$runRegistration($sent);
same(1,$purchaseForms(),'The Purchase was handed to cURL');
expect($result->state!=='held'&&$result->safe_code!=='enom_test_call_cap','A refusal after the Purchase left is not held as unsent');
same(['sealed','possibly_sent'],[$result->attempt['seal']??null,$result->attempt['send_state']??null],'The sent Purchase stays possibly sent');
expect(!array_key_exists('test_call_refusals',$result->attempt),'No unsent refusal is recorded after the send');
expect(!OperationClaims::testCallRefusalRetryable($result),'A possibly sent Purchase cannot be released for resend');
$state=$result->state;
try{$release($result->fresh());}catch(\Throwable){}
same($state,$sent->fresh()->state,'Staff release does not requeue a possibly sent Purchase');
config(['domains.enom_test_sends_per_day'=>1000]);$clock->instant=$clock->now()->modify('+61 seconds');Cache::flush();resetWire(['reply'=>$accepts]);
$engine->execute($sent->id);
same(0,$purchaseForms(),'A later run never resends the possibly sent Purchase');

// TP_CreateOrder uses the same armed scope in TEST, so the same proof applies at the send.
$origin='https://resellertest.enom.com/interface.asp';
$order=['OrderType'=>'Autoverification','DomainCount'=>1,'SLD1'=>'limit-transfer-placeholder','TLD1'=>'com','AuthInfo1'=>'SYNTHETIC-EPP','PreConfig'=>0,'Lock'=>1,'Renew'=>0,'UseCreditCard'=>'no'];
$refused=static function(\Closure $send):bool{try{$send();return false;}catch(EnomTestCallLimit){return true;}};
// (a) Transfer: refused before sending leaves the armed scope unconsumed, so it may still send once.
resetWire(['reply'=>$accepts]);$creates=$sends('TP_CreateOrder');
EnomTestCalls::withPurchase($binding,function()use($binding,$transfer,$origin,$order,$refused,$fullAfterThis,$sends,$creates):void {
    EnomTestCalls::reservePurchase($binding);$fullAfterThis();
    expect($refused(fn()=>$transfer($origin,'TP_CreateOrder',$order,25000)),'The limit refuses TP_CreateOrder with the typed unsent refusal');
    same([[],$creates],[$GLOBALS['enomWire']['forms'],$sends('TP_CreateOrder')],'Refused TP_CreateOrder sends and records nothing');
    expect(EnomTestCalls::purchaseUnconsumed($binding->fingerprint,424242),'Refused TP_CreateOrder stays proven unsent');
    config(['domains.enom_test_sends_per_day'=>1000]);$transfer($origin,'TP_CreateOrder',$order,25000);
    same([1,$creates+1],[count($GLOBALS['enomWire']['forms']),$sends('TP_CreateOrder')],'The unsent transfer sends once when the limit has room');
    expect(!EnomTestCalls::purchaseUnconsumed($binding->fingerprint,424242),'The sent transfer consumes the scope');
},424242);
// (b) Transfer: a refusal after TP_CreateOrder left keeps it possibly sent and blocks a resend.
$clock->instant=$clock->now()->modify('+61 seconds');
resetWire(['reply'=>$accepts,'during'=>static function(array $form)use($fullAfterThis):void {
    if($form['Command']==='TP_CreateOrder'){$fullAfterThis();EnomTestSendLimit::admit(Environment::Test,'account-enom','TP_GetOrder');}
}]);$creates=$sends('TP_CreateOrder');
EnomTestCalls::withPurchase($binding,function()use($binding,$transfer,$origin,$order,$refused,$sends,$creates):void {
    EnomTestCalls::reservePurchase($binding);
    expect($refused(fn()=>$transfer($origin,'TP_CreateOrder',$order,25000)),'A later refusal in the same scope surfaces as typed');
    same([1,$creates+1],[count($GLOBALS['enomWire']['forms']),$sends('TP_CreateOrder')],'TP_CreateOrder was handed to cURL');
    same(false,EnomTestCalls::purchaseUnconsumed($binding->fingerprint,424243),'A refusal after the send is not unsent');
    config(['domains.enom_test_sends_per_day'=>1000]);$GLOBALS['enomWire']['during']=null;
    expect($refused(fn()=>$transfer($origin,'TP_CreateOrder',$order,25000)),'A consumed scope refuses a second TP_CreateOrder');
    same([1,$creates+1],[count($GLOBALS['enomWire']['forms']),$sends('TP_CreateOrder')],'No resend after a possibly sent transfer');
},424243);
echo "Send limit refusal before Purchase or TP_CreateOrder is unsent and retryable; after the send it stays possibly sent.\n";
$nativeSuccessful=true;
