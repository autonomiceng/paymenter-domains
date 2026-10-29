<?php
// Funded native operation through the physical sender, without sockets. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';
require __DIR__.'/Support/enom-wire.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainQueries,Labels};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,BalanceReads,BalanceSchema,CatalogueAcceptances,EnomTestCallSchema,EnomTestRegistration,OperationClaims,Rdap,RenewalAcceptance,Runtime,TransferPrecheck,WriteAcceptance,WriteFeatures};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
app()->instance(Clock::class,$clock);app()->instance(Runtime::class,new Runtime);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new AccountReadBudget($clock));
EnomTestCallSchema::install();BalanceSchema::install();\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$binding=$bindingFor(0);
$op=$new(placeholderComFixtureName('guard-held-placeholder.com'));
$catalogue=CatalogueAcceptances::load($binding);
DB::transaction(static fn()=>CatalogueAcceptances::saveLocked(new CatalogueAcceptance($binding,$catalogue->revision,$catalogue->endings,'https://resellertest.enom.com/interface.asp',25)));
BalanceReads::record($binding,new \Paymenter\Extensions\Servers\Domains\Data\Money('100.00','USD'),$clock->now(),'manual');
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_test_daily_call_cap'=>1000]);
expect(EnomTestRegistration::enabled($binding),'Real TEST policy enabled for the operation');
$factory=RuntimeFactory::make($binding,$freshBindings,$clock,new WriteFeatures(registration:true),new WriteAcceptance($binding,true,true,25),new RenewalAcceptance($binding),
    new TransferPrecheck(new Rdap(static fn()=>null)),static fn()=>['uid'=>'account-enom','token'=>'SYNTHETIC-TOKEN']);
// The private exchange is exercised inside the genuine admitted Registration protocol and engine.
$wire=(new ReflectionFunction($factory))->getStaticVariables()['wire'];
$exchange=(new ReflectionMethod($wire,'exchange'))->invoke($wire,'registration');
// Reuse the fixture's socket-free reads through the explicit paid-preflight factory seam.
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
$events=[];config(['domains.provider_send_guard'=>static function($registrar,$environment,$command,$at)use(&$events,$op):void {
    same('sealed',$op->fresh()->attempt['seal'],'Guard runs after durable financial seal');
    $events[]=[$registrar,$environment,$command];throw new RuntimeException('PRIVATE-DEPLOYMENT-REASON');
}]);
resetWire();$money=$nativeMoney();$result=$runRegistration($op);
same([['enom','test','Purchase']],$events,'Guard sees exact physical purchase');
same([],$GLOBALS['enomWire']['forms'],'Refused physical Purchase sends nothing');
same('held',$result->state,'Refused operation is held, never unknown');
same('enom_test_call_cap',$result->safe_code,'Existing not-sent failure reaches caller');
same('purchase',$result->attempt['test_call_refusals'][0]['phase'],'Not-sent proof retained for sealed refusal');
expect(!array_key_exists('execution',$result->attempt)&&!array_key_exists('dispatch_attempted_at',$result->attempt),'Refusal retains no possible-send evidence');
// Staff History reads this same operation row; the refusal writes no separate staff audit.
$historyOperation=DomainQueries::operations($op->domain->fresh())->whereKey($op->id)->sole();
expect($historyOperation->id>0,'Staff History contains a persisted request');
same([$op->id,$op->domain_id,$op->service_id,$op->operation_key],
    [$historyOperation->id,$historyOperation->domain_id,$historyOperation->service_id,$historyOperation->operation_key],'Staff History retains the exact funded request');
same(['register','held','enom_test_call_cap'],[$historyOperation->action,$historyOperation->state,$historyOperation->safe_code],'Staff History retains the not-sent refusal');
same(1,count($historyOperation->attempt['test_call_refusals']),'Persisted refusal evidence is nonempty and singular');
$refusal=$historyOperation->attempt['test_call_refusals'][0];
same(['purchase',$clock->now()->format(DATE_ATOM),['amount'=>'99.00','currency'=>'USD']],
    [$refusal['phase'],$refusal['refused_at'],$refusal['authorized_cost']],'Persisted refusal retains the purchase time and authorized USD cost');
expect(is_string($refusal['dispatch_attempted_at'])&&$refusal['dispatch_attempted_at']!=='','Persisted refusal retains the prior seal time');
expect(is_array($historyOperation->policy['checkout'])&&$historyOperation->policy['checkout']!==[],'Persisted hidden policy retains checkout evidence');
same('Not sent: our daily call cap reached',Labels::requestStatus($historyOperation)['reason'],'Staff History shows the safe refusal reason');
// Reuse the held operation to exercise the real account mode lookup used by staff History.
DB::table('domains_accounts')->where('server_id',$historyOperation->server_id)->update(['environment'=>'live']);
app('request')->attributes->remove('domains.account.environment.'.$historyOperation->server_id);
same('Not sent: our daily call cap reached',Labels::requestStatus($historyOperation)['reason'],'Live History keeps ownership of our safety cap');
DB::table('domains_accounts')->where('server_id',$historyOperation->server_id)->update(['environment'=>'test']);
app('request')->attributes->remove('domains.account.environment.'.$historyOperation->server_id);
foreach(['write_rate_limited','renewal_rate_limited'] as $code){
    $limited=clone $historyOperation;$limited->safe_code=$code;
    same('Not sent: our request safety limit reached',Labels::requestStatus($limited)['reason'],'Local request guards keep ownership of their limit');
}
// getAttribute decrypts hidden attempt/policy fields that toArray would omit.
$attributes=[];
foreach(array_keys($historyOperation->getAttributes()) as $attribute){$attributes[$attribute]=$historyOperation->getAttribute($attribute);}
expect(!str_contains(json_encode($attributes,JSON_THROW_ON_ERROR),'PRIVATE-DEPLOYMENT-REASON'),'No persisted operation or staff History attribute retains the private guard message');
same($money,$nativeMoney(),'Guard refusal changes no native money');
// Staff can release this held request and dispatch later without replacing its financial obligation.
require __DIR__.'/Support/staff-panel.php';staffPanel();
$staff=staffUser(['admin.domains.view','admin.domains.manage',\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::permission('release')]);
$key=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket($staff->id,$result->domain_id,$result->id,'release',
    \Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($result,$result->domain->fresh()),$clock);
(new \Paymenter\Extensions\Servers\Domains\Services\StaffOperations($clock))->run($staff,$result->domain_id,$result->id,'release',$key,'Release hold');
same('queued',$op->fresh()->state,'Not-sent refusal is retryable through staff release');
config(['domains.provider_send_guard'=>null]);$clock->instant=$clock->now()->modify('+61 seconds');Cache::flush();
resetWire(['reply'=>static fn($p)=>'<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate></interface-response>']);
same('pending',$runRegistration($op->fresh())->state,'Null guard permits the same funded request later');
same(1,count(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='Purchase')),'Exactly one actual purchase across refusal and retry');
echo "Guard refusal preserves sealed acquisition as unsent, funded and retryable.\n";
$nativeSuccessful=true;
