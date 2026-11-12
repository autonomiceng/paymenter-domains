<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
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
require __DIR__.'/enom-test-sales-fixture.php';
$reader=$bindings->reader($server->id,true);$generalReader=$bindings->reader($server->id);
$unpaidOp=Domain::where('domain','unpaid-test-placeholder.com')->sole()->operations()->sole();
$op=Domain::where('domain','paid-test-placeholder.com')->first()?->operations()->sole();
app()->instance(Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,new Paymenter\Extensions\Servers\Domains\Services\PslTransport(static fn()=>throw new Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed')));
config(['domains.enom_test_sends_per_day'=>500]);$beforeCalls=count($GLOBALS['enomWire']['forms']);$beforeUsed=enomTestSendCount();
try{EnomTestCalls::prepareSend($binding,true);throw new LogicException('Purchase requires an armed worker scope');}catch(EnomTestCallLimit){}
try{EnomTestCalls::withPurchase(new AccountBinding('enom',$binding->serverId,Environment::Live,'live-uid'),fn()=>null);throw new LogicException('Purchase scope is TEST-only');}catch(EnomTestCallLimit){}
$otherBinding=new AccountBinding('enom',$binding->serverId,Environment::Test,'another-test-uid');
try{EnomTestCalls::withPurchase($binding,function()use($binding,$otherBinding):void{EnomTestCalls::reservePurchase($binding);EnomTestCalls::prepareSend($otherBinding,true);});throw new LogicException('Purchase scope binds the fingerprint');}catch(EnomTestCallLimit){}
EnomTestCalls::withPurchase($binding,function()use($binding):void{
    try{EnomTestCalls::prepareSend($binding,true);throw new LogicException('An unarmed Purchase scope cannot send');}catch(EnomTestCallLimit){}
    expect(!EnomTestCalls::purchaseUnconsumed($binding->fingerprint),'An unarmed scope proves nothing unsent');
    EnomTestCalls::reservePurchase($binding);
    try{EnomTestCalls::reservePurchase($binding);throw new LogicException('A Purchase scope is armed once');}catch(EnomTestCallLimit){}
    $day=EnomTestCalls::prepareSend($binding,true);same('',$day,'An armed TEST Purchase has no UTC day');
    EnomTestCalls::authorizeSend($binding,true,$day);
    expect(!EnomTestCalls::purchaseUnconsumed($binding->fingerprint),'A Purchase handed to cURL is never proven unsent');
    try{EnomTestCalls::prepareSend($binding,true);throw new LogicException('Purchase scope is single-use');}catch(EnomTestCallLimit){}
    try{EnomTestCalls::authorizeSend($binding,true,$day);throw new LogicException('A consumed Purchase cannot be authorized again');}catch(EnomTestCallLimit){}
    expect(!EnomTestCalls::purchaseUnconsumed($binding->fingerprint),'Consumed stays sticky after a later refusal');
});
EnomTestCalls::withPurchase($binding,function()use($binding,$op):void{
    EnomTestCalls::reservePurchase($binding);
    expect(EnomTestCalls::purchaseUnconsumed($binding->fingerprint,(int)$op->id),'Unsent Purchase proof matches its operation scope');
    expect(!EnomTestCalls::purchaseUnconsumed($binding->fingerprint,(int)$op->id+1),'Unsent Purchase proof rejects a different operation');
},(int)$op->id);
same($beforeUsed,enomTestSendCount(),'Scope guard checks record no send attempt');
same($beforeCalls,count($GLOBALS['enomWire']['forms']),'Scope guard checks never reach transport');
$sealedLimitOp=$new(placeholderComFixtureName('sealed-limit-placeholder.com'));
$clock->at=new DateTimeImmutable('2026-10-21T23:59:59Z');Cache::flush();$write('import',['ending'=>'com']);$write('probe');Cache::flush();
$limiter=Runtime::limiter();expect($limiter instanceof Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter,'Installed TEST worker uses the shared account read limiter');
$heldRead=$limiter->acquire($binding,RequestOrigin::Background);$before=count($GLOBALS['enomWire']['forms']);
$worker->operation($sealedLimitOp->id);
same($before,count($GLOBALS['enomWire']['forms']),'Saturated account read slot defers before provider traffic');
same('queued',$sealedLimitOp->fresh()->state,'Local read-capacity refusal remains retryable');
same(1,count($sealedLimitOp->fresh()->attempt['capacity_deferrals']??[]),'First claim retains its bounded capacity deferral');
$limiter->release($binding,RequestOrigin::Background,$heldRead);Cache::flush();
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$sealedLimitOp->id]);DB::table('domains_runtime_operations')->where('operation_id',$sealedLimitOp->id)->update(['due_at'=>0]);
Queue::fake();app(RuntimeWork::class)->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$sealedLimitOp->id);
$sealedLimitUsed=enomTestSendCount();$sealedPurchases=enomTestSendCount('Purchase');
$before=count($GLOBALS['enomWire']['forms']);

$GLOBALS['enomWire']['during']=static function(array $form)use($clock,$sealedLimitOp):void {
    if(($form['Command']??null)!=='GetBalance'){return;}
    $GLOBALS['enomWire']['during']=null;
    $GLOBALS['enomWire']['before']=static function()use($clock,$sealedLimitOp):void {
        same('sealed',$sealedLimitOp->fresh()->attempt['seal']??null,'Limit hook runs after the paid operation is sealed');
        config(['domains.enom_test_sends_per_day'=>enomTestSendCount()]);
    };
};
$worker->operation($sealedLimitOp->id);
unset($GLOBALS['enomWire']['before']);$GLOBALS['enomWire']['during']=null;config(['domains.enom_test_sends_per_day'=>500]);
same(['Check','PE_GetProductPrice','GetBalance'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Limit reached after seal refuses before Purchase enters cURL');
same(['held','enom_test_call_cap'],[$sealedLimitOp->fresh()->state,$sealedLimitOp->fresh()->safe_code],'Post-seal unsent limit refusal becomes a retryable cap hold');
same('purchase',$sealedLimitOp->fresh()->attempt['test_call_refusals'][0]['phase']??null,'Post-seal refusal is recorded as Purchase phase');
same(1,count($sealedLimitOp->fresh()->attempt['capacity_deferrals']??[]),'Purchase cap retains earlier bounded capacity deferrals');
expect(!isset($sealedLimitOp->fresh()->attempt['claim_key'],$sealedLimitOp->fresh()->attempt['seal'],$sealedLimitOp->fresh()->attempt['execution']),'Refusal clears seal and execution evidence');
same([$sealedLimitUsed+3,$sealedPurchases],[enomTestSendCount(),enomTestSendCount('Purchase')],'Preflights stay counted; the refused Purchase records no attempt');
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
expect(Paymenter\Extensions\Servers\Domains\Services\OperationClaims::testCallRefusalRetryable($sealedLimitOp->fresh()),'Post-seal unsent refusal passes the staff release guard');
$sealedDomain=$sealedLimitOp->domain->fresh();
$sealedRelease=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$sealedDomain->id,(int)$sealedLimitOp->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($sealedLimitOp->fresh(),$sealedDomain),$clock);
app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$sealedDomain->id,(int)$sealedLimitOp->id,'release',$sealedRelease,'The send limit refusal was verified as unsent');
same('queued',$sealedLimitOp->fresh()->state,'Staff can release the post-seal unsent cap hold');
same(1,count($sealedLimitOp->fresh()->attempt['capacity_deferrals']??[]),'Release preserves capacity deferrals alongside refusal history');
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$sealedLimitOp->id]);DB::table('domains_runtime_operations')->where('operation_id',$sealedLimitOp->id)->update(['due_at'=>0]);
Cache::flush();Queue::fake();$before=count($GLOBALS['enomWire']['forms']);app(RuntimeWork::class)->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$sealedLimitOp->id);
same($before,count($GLOBALS['enomWire']['forms']),'Runtime scheduler queues released TEST write without provider traffic');
$rolloverStart=$clock->at;$rolloverUsed=enomTestSendCount();
EnomTestCalls::withPurchase($binding,function()use($binding,$clock):void{
    EnomTestCalls::reservePurchase($binding);$clock->at=$clock->at->modify('+1 day');
    same('',EnomTestCalls::prepareSend($binding,true),'An armed TEST Purchase has no UTC day to cross');
    expect(EnomTestCalls::purchaseUnconsumed($binding->fingerprint),'A day change alone does not consume the Purchase');
});
$clock->at=$rolloverStart;same($rolloverUsed,enomTestSendCount(),'Scope checks across a day change record no attempt');
Cache::flush();$deadlineUsed=enomTestSendCount();$slowCounter=false;
DB::listen(static function($query)use(&$slowCounter):void {
    if(!$slowCounter&&str_contains($query->sql,EnomTestSendLimitSchema::SENDS)){$slowCounter=true;$GLOBALS['enomWire']['nanoseconds']+=26000000000;}
});
$deadlineContext=new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,RequestOrigin::Interactive,
    $clock->now()->modify('+25 seconds'),new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1),$clock,Runtime::limiter());
$before=count($GLOBALS['enomWire']['forms']);$reader->balance($deadlineContext);
same($before,count($GLOBALS['enomWire']['forms']),'Send limit latency past the deadline refuses before read send');
same($deadlineUsed+1,enomTestSendCount(),'Deadline-expired read attempt stays counted');

$nativeSuccessful=true;
