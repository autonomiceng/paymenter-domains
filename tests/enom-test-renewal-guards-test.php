<?php
// Local opt-in and reservation boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestRenewal,EnomTestCalls,EnomTestCallSchema,EnomTestWriteReservation as Reservation,EnomTestCallLimit};
app()->instance(Clock::class,$clock);EnomTestCallSchema::install();
// An explicitly supplied pre-existing runtime keeps its transient-cap behavior with opt-in off.
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-off-cap-placeholder.com'));[$engine,$writer]=$compose($b);
config(['domains.enom_writes.renewal'=>false,'domains.enom_test_daily_call_cap'=>0]);
$state->during=static fn()=>EnomTestCalls::reserve(Environment::Test);
same('queued',$engine->run($op->id,$writer)->state,'Off preserves retryable normalized reads');same('renewal_reads_unavailable',$op->fresh()->safe_code,'Off retains original reason');
config(['domains.enom_test_daily_call_cap'=>1000]);Cache::flush();same('pending',$engine->run($op->id,$writer)->state,'Off runtime recovers without a new release predicate');
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
$clock->instant=new DateTimeImmutable('2028-01-01T23:59:59Z');
Reservation::run($b,'renewal',1,function()use($b,$foreign,$clock,$refuse){
    expect(Reservation::active($b,'renewal'),'Exact scope active');expect(!Reservation::active($foreign,'renewal')&&!Reservation::active($b,'management'),'Active scope matches fingerprint and action');
    $refuse(fn()=>Reservation::prepare($b,'renewal'));
    $refuse(fn()=>Reservation::run($b,'renewal',2,fn()=>null));
    $refuse(fn()=>Reservation::run($foreign,'renewal',1,fn()=>null));
    Reservation::reserve($b,'renewal');$day=Reservation::prepare($b,'renewal');same('2028-01-01',$day,'Reservation binds UTC day');
    $refuse(fn()=>Reservation::reserve($b,'renewal'));$refuse(fn()=>Reservation::prepare($foreign,'renewal'));$refuse(fn()=>Reservation::prepare($b,'management'));
    $refuse(fn()=>Reservation::consume($b,'renewal','2028-01-02'));
    $clock->instant=$clock->instant->modify('+1 second');$refuse(fn()=>Reservation::prepare($b,'renewal'));$clock->instant=$clock->instant->modify('-1 second');
    Reservation::consume($b,'renewal',$day);expect(Reservation::active($b,'renewal'),'Consumed scope retains its no-send exception identity');$refuse(fn()=>Reservation::consume($b,'renewal',$day));
});
same(1,(int)DB::table(EnomTestCallSchema::TABLE)->where('day','2028-01-01')->value('used'),'Only one reservation, never refunded');same(null,Reservation::prepare($b,'renewal'),'Scope closes after work');
$refuse(fn()=>Reservation::run($b,'renewal',2,static function()use($b){Reservation::reserve($b,'renewal');throw new EnomTestCallLimit;}));same(null,Reservation::prepare($b,'renewal'),'Scope closes after exception');same(2,(int)DB::table(EnomTestCallSchema::TABLE)->where('day','2028-01-01')->value('used'),'Interrupted reservation remains spent');
echo "TEST renewal opt-in, default-off parity, acceptance and one-use day reservation passed\n";$nativeSuccessful=true;
