<?php
// Local opt-in and reservation boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/WireLimit.php';
set_exception_handler(static function (Throwable $error): never {fwrite(STDERR,$error::class.': '.$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});
\Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire();
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestRenewal,EnomTestSendLimit,EnomTestSendLimitSchema,EnomTestWriteReservation as Reservation,EnomTestCallLimit,Rdap,RenewalAcceptance,RenewalAdmission,RenewalExecution,RenewalWriter,Runtime,TransferPrecheck,WriteAcceptance,WriteFeatures};
app()->instance(Clock::class,$clock);EnomTestSendLimitSchema::install();
$checkoutPolicy=['domains.enom_identity.environment'=>config('domains.enom_identity.environment'),'domains.enom_catalogue.enabled'=>config('domains.enom_catalogue.enabled')];
// An explicitly supplied pre-existing runtime keeps its transient-cap behavior with opt-in off.
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-off-cap-placeholder.com'));[$engine,$writer]=$compose($b);
config(['domains.enom_writes.renewal'=>false,'domains.enom_test_sends_per_day'=>0]);
$state->during=static fn()=>EnomTestSendLimit::admit(Environment::Test,'account-enom','GetDomainInfo');
same('queued',$engine->run($op->id,$writer)->state,'Off preserves retryable normalized reads');same('renewal_reads_unavailable',$op->fresh()->safe_code,'Off retains original reason');
config(['domains.enom_test_sends_per_day'=>1000]);Cache::flush();same('pending',$engine->run($op->id,$writer)->state,'Off runtime recovers without a new release predicate');
[$wireOp,$wireDomain,$wireBinding]=$makeRenewal(placeholderComFixtureName('renew-off-wire-placeholder.com'));[$wireEngine,$wireWriter]=$compose($wireBinding);
$state->afterWrite=static fn()=>throw new EnomTestCallLimit;
same('uncertain',$wireEngine->run($wireOp->id,$wireWriter)->state,'Off preserves uncertainty at the old explicit-runtime wire boundary');
[$onCapOp,$onCapDomain,$onCapBinding]=$makeRenewal(placeholderComFixtureName('renew-on-wire-cap-placeholder.com'));[$onCapEngine,$onCapWriter]=$compose($onCapBinding);
[$termOp,$termDomain,$termBinding]=$makeRenewal(placeholderComFixtureName('renew-test-annual-only-placeholder.com'),true,2);[$termEngine,$termWriter]=$compose($termBinding);
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true]);
foreach([false,'true',1,null] as $off){config(['domains.enom_writes.renewal'=>$off]);expect(!EnomTestRenewal::enabled($b),'Strict boolean opt-in');}
config(['domains.enom_writes.renewal'=>true]);expect(EnomTestRenewal::enabled($b),'TEST opt-in opens');
$foreign=new AccountBinding('enom',$b->serverId,Environment::Test,'foreign');$live=new AccountBinding('enom',$b->serverId,Environment::Live,'live');
expect(!EnomTestRenewal::enabled($live),'Live binding refused');
foreach(['live','',null] as $env){config(['domains.enom_identity.environment'=>$env]);expect(!EnomTestRenewal::enabled($b),'Explicit TEST identity required');}
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>false]);expect(!EnomTestRenewal::enabled($b),'Catalogue gate required');config(['domains.enom_catalogue.enabled'=>true]);
same('held',$termEngine->run($termOp->id,$termWriter)->state,'TEST composition refuses a fixed multi-year term');same('renewal_term_refused',$termOp->fresh()->safe_code,'Annual-only guard precedes provider reads');same([],$state->calls,'Annual-only guard sends nothing');
$state->name=$onCapDomain->domain;$state->afterWrite=static fn()=>throw new EnomTestCallLimit;Cache::flush();
same('held',$onCapEngine->run($onCapOp->id,$onCapWriter)->state,'Active reserved runtime retains proven unsent wire refusal');same('renewal_definitely_unsent',$onCapOp->fresh()->safe_code,'Only reserved cap refusal can be proven unsent');
$setting=$product->server->settings()->firstOrNew(['key'=>EnomTestRenewal::KEY]);$setting->encrypted=true;$setting->type='string';$setting->value=json_encode(['fingerprint'=>$b->fingerprint,...EnomTestRenewal::protocol()]);$setting->save();
expect(EnomTestRenewal::accepted($b),'Exact encrypted acceptance');$setting->encrypted=false;$setting->value=json_encode(['fingerprint'=>$b->fingerprint,...EnomTestRenewal::protocol()]);$setting->save();expect(!EnomTestRenewal::accepted($b),'Unencrypted review refused');$setting->encrypted=true;$setting->value=json_encode(['fingerprint'=>$b->fingerprint,...EnomTestRenewal::protocol()]);$setting->save();expect(!EnomTestRenewal::accepted($foreign),'Fingerprint bound acceptance');
$accepted=EnomTestRenewal::acceptance($b);$accepted->requireCurrent($b);$setting->value='null';$setting->save();commerceReject(fn()=>$accepted->requireCurrent($b));
$setting->value=json_encode(['fingerprint'=>$b->fingerprint,...EnomTestRenewal::protocol(),'interval'=>26]);$setting->save();expect(!EnomTestRenewal::accepted($b),'Protocol drift invalidates review');
$refuse=static function(Closure $f):void {try{$f();throw new LogicException('Reservation must refuse');}catch(EnomTestCallLimit){}};
expect(!Reservation::active($b,'renewal'),'No active scope');same(null,Reservation::prepare($b,'renewal'),'No scope preserves explicit runtime wire behavior');
$refuse(fn()=>Reservation::run($live,'renewal',1,fn()=>null));$refuse(fn()=>Reservation::run($b,'renewal',0,fn()=>null));$refuse(fn()=>Reservation::run($b,'unsupported',1,fn()=>null));
Reservation::run($b,'renewal',1,function()use($b,$foreign,$refuse){
    expect(Reservation::active($b,'renewal'),'Exact scope active');expect(!Reservation::active($foreign,'renewal')&&!Reservation::active($b,'management'),'Active scope matches fingerprint and action');
    $refuse(fn()=>Reservation::prepare($b,'renewal'));
    $refuse(fn()=>Reservation::run($b,'renewal',2,fn()=>null));
    $refuse(fn()=>Reservation::run($foreign,'renewal',1,fn()=>null));
    expect(!Reservation::unconsumed($b,'renewal',1),'An unarmed scope proves nothing');
    Reservation::reserve($b,'renewal');$day=Reservation::prepare($b,'renewal');same('',$day,'TEST arms the scope without a day');
    expect(Reservation::unconsumed($b,'renewal',1)&&!Reservation::unconsumed($b,'renewal',2)&&!Reservation::unconsumed($b,'management',1),'Armed proof is bound to action and operation');
    $refuse(fn()=>Reservation::reserve($b,'renewal'));$refuse(fn()=>Reservation::prepare($foreign,'renewal'));$refuse(fn()=>Reservation::prepare($b,'management'));
    $refuse(fn()=>Reservation::consume($b,'renewal','2028-01-02'));
    Reservation::consume($b,'renewal',$day);expect(Reservation::active($b,'renewal'),'Consumed scope retains its no-send exception identity');
    expect(!Reservation::unconsumed($b,'renewal',1),'Consumed is sticky: a later refusal never proves unsent');
    $refuse(fn()=>Reservation::consume($b,'renewal',$day));$refuse(fn()=>Reservation::prepare($b,'renewal'));$refuse(fn()=>Reservation::reserve($b,'renewal'));
});
$sends=DB::table(EnomTestSendLimitSchema::SENDS)->count();same(0,$sends,'Arming records no send, and the refused read recorded none');same(null,Reservation::prepare($b,'renewal'),'Scope closes after work');
$refuse(fn()=>Reservation::run($b,'renewal',2,static function()use($b){Reservation::reserve($b,'renewal');throw new EnomTestCallLimit;}));same(null,Reservation::prepare($b,'renewal'),'Scope closes after exception');same($sends,DB::table(EnomTestSendLimitSchema::SENDS)->count(),'An interrupted armed scope records no send');
// The production exchange: a send-limit refusal proves the renewal unsent only before cURL.
$setting->value=json_encode(['fingerprint'=>$b->fingerprint,...EnomTestRenewal::protocol()]);$setting->save();
config(['domains.enom_test_sends_per_minute'=>100000]);
// Only the runs bind the offline production runtime.
$wired=static function($binding)use($renewBindings,$authority,$bindings,$clock):array {
    $accepted=new RenewalAcceptance($binding,true,true,25,true);$features=new WriteFeatures(renewal:true);
    $factory=RuntimeFactory::make($binding,$bindings,$clock,$features,new WriteAcceptance($binding),$accepted,new TransferPrecheck(new Rdap(static fn()=>null)),
        static fn()=>['uid'=>'account-enom','token'=>'synthetic-offline-token']);
    $wire=(new ReflectionFunction($factory))->getStaticVariables()['wire'];$exchange=(new ReflectionMethod($wire,'exchange'))->invoke($wire,'renewal');
    $engine=new RenewalExecution($renewBindings,$authority,new RenewalAdmission($authority,$renewBindings,$accepted,$clock),$accepted,$features,$clock);
    return [$engine,new RenewalWriter($binding,$features,$accepted,fn()=>$engine,$exchange)];
};
// Checkout runs under the default policy; the TEST policy applies only to the runs.
$make=static function(string $name)use($makeRenewal,$checkoutPolicy):array {$test=array_map(static fn($k)=>config($k),array_combine(array_keys($checkoutPolicy),array_keys($checkoutPolicy)));config($checkoutPolicy);try{return $makeRenewal(placeholderComFixtureName($name));}finally{config($test);}};
$bound=static function(Closure $run){app()->instance(Runtime::class,new Runtime);app()->instance(ReadLimiter::class,new WireLimit(1000));try{return $run();}finally{app()->offsetUnset(Runtime::class);}};
$extends=static fn():array=>array_values(array_filter(array_column($GLOBALS['enomWire']['forms'],'Command'),static fn($c)=>$c==='Extend'));
$ack=static fn(array $p):string=>'<interface-response><Command>'.$p['Command'].'</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>9002</OrderID></interface-response>';
$recent=static fn():int=>EnomTestSendLimit::usage('account-enom')['day'];
// (a) A read refused before the seal: held for staff release, unsealed.
[$readOp,,$readBinding]=$make('renew-limit-read-placeholder.com');[$readEngine,$readWriter]=$wired($readBinding);
resetWire(['reply'=>$ack]);config(['domains.enom_test_sends_per_day'=>$recent()]);
$state->during=static fn()=>EnomTestSendLimit::admit(Environment::Test,'account-enom','GetDomainInfo');Cache::flush();
$readOp=$bound(fn()=>$readEngine->run($readOp->id,$readWriter))->fresh();
same(['held','enom_test_renewal_call_cap',[]],[$readOp->state,$readOp->safe_code,(array)$readOp->attempt],'A refused read holds the renewal unsealed and releasable');same([],$extends(),'No Extend after a refused read');
// (a) The Extend itself is refused: sealed, armed and not consumed, so proven unsent and refundable.
config(['domains.enom_test_sends_per_day'=>1000]);
[$limitOp,,$limitBinding]=$make('renew-limit-write-placeholder.com');[$limitEngine,$limitWriter]=$wired($limitBinding);
resetWire(['reply'=>$ack]);config(['domains.enom_test_sends_per_day'=>$recent()]);$before=DB::table(EnomTestSendLimitSchema::SENDS)->count();Cache::flush();
$limitOp=$bound(fn()=>$limitEngine->run($limitOp->id,$limitWriter))->fresh();
same([],$GLOBALS['enomWire']['forms'],'The refused Extend never reaches cURL');same($before,DB::table(EnomTestSendLimitSchema::SENDS)->count(),'The refused Extend is not counted');
same(['held','renewal_definitely_unsent','sealed'],[$limitOp->state,$limitOp->safe_code,$limitOp->attempt['seal']],'A refused Extend is held as proven unsent');
same('definitely_unsent',$limitOp->attempt['execution']['send_state']??null,'The driver result records the write as unsent');expect($limitOp->policy['renewal_execution']['refundable_later'],'The paid unsent renewal can be refunded');
$bound(fn()=>$limitEngine->run($limitOp->id,$limitWriter));same([],$extends(),'A held unsent renewal never sends on its own');
config(['domains.enom_test_sends_per_day'=>1000]);
// (b) The Extend reached cURL, then a refusal is raised: consumed is sticky, so it is never unsent.
[$sentOp,,$sentBinding]=$make('renew-limit-sent-placeholder.com');[$sentEngine,$sentWriter]=$wired($sentBinding);
resetWire(['reply'=>$ack,'during'=>static function(array $p):void {if($p['Command']==='Extend'){throw new EnomTestCallLimit;}}]);Cache::flush();
$sentOp=$bound(fn()=>$sentEngine->run($sentOp->id,$sentWriter))->fresh();same(['Extend'],$extends(),'The Extend reached cURL once');
same(['uncertain','renewal_confirmation_required','possibly_sent'],[$sentOp->state,$sentOp->safe_code,$sentOp->attempt['send_state']],'A refusal after the Extend was sent stays uncertain');
echo "TEST renewal opt-in, default-off parity, acceptance, armed scope and send-limit unsent proof passed\n";$nativeSuccessful=true;
