<?php
// A real admitted Purchase must retain only safe failure facts. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom {
    function curl_errno($handle): int { return $GLOBALS['failureErrno'] ?? 0; }
}
namespace {
require __DIR__.'/Support/registration-fixture.php';
require __DIR__.'/Support/enom-wire.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Environment, Money};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, BalanceReads, BalanceSchema, EnomTestCallLimit, EnomTestCalls, EnomTestRegistration, EnomTestSendLimit, EnomTestSendLimitSchema, EnomTestTransfer, OperationClaims, Rdap, RenewalAcceptance, Runtime, StaffOperationReview, StaffOperations, TransferPrecheck, WriteAcceptance, WriteFeatures};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
app()->instance(Clock::class,$clock);app()->instance(Runtime::class,new Runtime);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new AccountReadBudget($clock));
EnomTestSendLimitSchema::install();BalanceSchema::install();\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$binding=$bindingFor(0);
BalanceReads::record($binding,new Money('100.00','USD'),$clock->now(),'manual');
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.transfer'=>true,
    'domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>100000]);
expect(EnomTestRegistration::enabled($binding)&&EnomTestTransfer::enabled($binding),'Real TEST registration and transfer policy enabled');
$factory=RuntimeFactory::make($binding,$freshBindings,$clock,new WriteFeatures(registration:true),new WriteAcceptance($binding,true,true,25,catalogue: $GLOBALS['caps']??null),new RenewalAcceptance($binding),
    new TransferPrecheck(new Rdap(static fn()=>null)),static fn()=>['uid'=>'account-enom','token'=>'SYNTHETIC-TOKEN']);
$wire=(new ReflectionFunction($factory))->getStaticVariables()['wire'];
$exchange=(new ReflectionMethod($wire,'exchange'))->invoke($wire,'registration');
$transfer=(new ReflectionMethod($wire,'exchange'))->invoke($wire,'transfer');
// Preflight reads stay on the fixture's socket-free registrar, so only the write reaches the counted wire.
$preflightRegistry=new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$read=static fn($b,$c)=>$registry->reader($b,$c);
$preflightRegistry->register('enom',['token'],$verifier,$read,registrationPreflightFactory:$read);
$preflightBindings=new \Paymenter\Extensions\Servers\Domains\Services\AccountBindings($preflightRegistry);
$features=new WriteFeatures(registration:true);$accepted=new WriteAcceptance($binding,true,true,25,catalogue: $GLOBALS['caps']??null);
$protocol=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($binding,$exchange);
$fresh=new \Paymenter\Extensions\Servers\Domains\Services\RegistrationPreflight($preflightBindings,$features,$accepted);
$bridge=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Writer($binding,$features,$accepted,static function()use(&$engine){return $engine;},$protocol);
$engine=new \Paymenter\Extensions\Servers\Domains\Services\DomainOperations($claims,$fresh,$bridge,$clock);
Cache::forget('domains-write-slot:'.$binding->fingerprint);
$sends=static fn(?string $command=null):int=>$command===null?DB::table(EnomTestSendLimitSchema::SENDS)->count():DB::table(EnomTestSendLimitSchema::SENDS)->where('command',$command)->count();
$purchaseForms=static fn():int=>count(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='Purchase'));
$accepts=static fn($p)=>'<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate></interface-response>';

require __DIR__.'/Support/staff-panel.php';staffPanel();
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.secrets-metadata']);
Illuminate\Support\Facades\Auth::setUser($staff);
foreach ([['timeout',28,200,false],['connect',7,200,false],['tls',60,200,false],['http 503',0,503,false],['parse',0,200,true],['other',0,200,false]] as $index=>[$category,$errno,$status,$parse]) {
    Cache::flush();$clock->instant=$clock->now()->modify('+61 seconds');
    $op=$new(placeholderComFixtureName('lost-reply-'.$index.'-placeholder.com'));
    $GLOBALS['failureErrno']=$errno;
    $expectedTimeout=$parse?24900:25000;
    resetWire(['configure'=>$parse?static function():void{$GLOBALS['enomWire']['nanoseconds']=100000000;}:null,'execFail'=>!$parse&&$status===200,'status'=>$status,'chunks'=>['<invalid private="SENTINEL">']]);
    $result=$runRegistration($op)->fresh();
    Illuminate\Support\Facades\Auth::setUser($staff);
    same(25,$GLOBALS['enomWire']['options'][CURLOPT_LOW_SPEED_TIME],'A quiet Purchase is allowed its full call limit');
    same(['category'=>$category,'timeout_ms'=>$expectedTimeout],$result->attempt['send_failure']??null,'The original attempt keeps the fixed reason and actual call limit');
    same('uncertain',$result->state,'Failure never authorizes another Purchase');
    same(null,$result->attempt['execution'],'Lost reply keeps recorded-null execution');
    same(1,$purchaseForms(),'One Purchase only');
    $engine->execute($op->id);same(1,$purchaseForms(),'No replay');
    $facts=Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($result);
    same($category.'; time limit '.$expectedTimeout.' ms',$facts['send_failure']??null,'Staff sees safe send failure');
    $page=Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$result->id]);
    $page->assertSee('Registrar reply')->assertSee($category.'; time limit '.$expectedTimeout.' ms')->assertDontSee('SENTINEL');
    commerceReject(fn()=>$result->update(['attempt'=>array_replace($result->attempt,['send_failure'=>['category'=>'other','timeout_ms'=>1]])]));
}
$nativeSuccessful=true;echo "Safe Purchase failure retained and shown without replay or raw response.\n";
}
