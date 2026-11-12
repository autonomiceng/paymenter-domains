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
// Reach the send limit after preflight: the Purchase is armed and sealed, then refused at send. Unsent evidence stays staff-retryable.
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$capOp=$new(placeholderComFixtureName('cap-held-placeholder.com'));
expect(EnomTestRegistration::applies($capOp),'TEST deployment opts in only Enom registration operations');
$otherRegistrar=clone $capOp;$otherRegistrar->registrar_key='namesilo';
expect(!EnomTestRegistration::applies($otherRegistrar),'Enom TEST opt-in leaves NameSilo registration on its own path');
$used=enomTestSendCount();$purchasesBefore=enomTestSendCount('Purchase');config(['domains.enom_test_sends_per_day'=>$used+3]);
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($capOp->id);same(['Check','PE_GetProductPrice','GetBalance'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Cap refuses Purchase before send');same('held',$capOp->fresh()->state,'Cap held');same('enom_test_call_cap',$capOp->fresh()->safe_code,'Safe staff reason');
same(1,count($capOp->fresh()->attempt['test_call_refusals']??[]),'Refused claim retained as retryable cap evidence');expect(!isset($capOp->fresh()->attempt['claim_key'],$capOp->fresh()->attempt['seal']),'No uncertain or sealed attempt remains');
same('purchase',$capOp->fresh()->attempt['test_call_refusals'][0]['phase']??null,'The limit refused the Purchase itself, at send');same([$used+3,$purchasesBefore],[enomTestSendCount(),enomTestSendCount('Purchase')],'Three preflights counted; the refused Purchase records no attempt');
try{$capOp->fresh()->update(['attempt'=>[]]);throw new LogicException('Refusal history cannot be erased.');}catch(DomainException){}
same(1,count($capOp->fresh()->attempt['test_call_refusals']??[]),'Cap refusal history remains append-only');
same('Not sent: stopped by our test send check',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('enom_test_call_cap'),'Staff wording directs safe cap recovery and states the USD evidence requirement');
expect(str_contains(EnomTestSendLimit::facts('test-uid'),'Limit reached. Try again later.'),'Staff sees the reached limit');
$capViewer=Auth::user();Auth::setUser($staff);
expect(OperationActions::relevant('release',$capOp->fresh()),'Retained registration cap proof offers release');
same('release',RequestFacts::notice($capOp->fresh())['action']??null,'Retained registration cap notice offers release');
Auth::setUser($capViewer);
$capDomain=$capOp->domain->fresh();$releaseTicket=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$capDomain->id,(int)$capOp->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($capOp->fresh(),$capDomain),$clock);
app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$capDomain->id,(int)$capOp->id,'release',$releaseTicket,'Capacity is available after review');
same('queued',$capOp->fresh()->state,'Staff release preserves the proven-unsent TEST retry');$used=enomTestSendCount();
config(['domains.enom_test_sends_per_day'=>$used+8]);$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$GLOBALS['testNameUnavailable']=true;$before=count($GLOBALS['enomWire']['forms']);$worker->operation($capOp->id);unset($GLOBALS['testNameUnavailable']);
same(['Check','PE_GetProductPrice','GetBalance'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Retried preflight may refuse a taken name');
same(['held','name_unavailable'],[$capOp->fresh()->state,$capOp->fresh()->safe_code],'A taken name keeps its cause and stays a reviewed unsent hold');
same(1,count($capOp->fresh()->attempt['test_call_refusals']??[]),'Retry retains original cap refusal history');
expect(Paymenter\Extensions\Servers\Domains\Services\OperationClaims::testCallRefusalRetryable($capOp->fresh()),'Prior cap refusal preserves narrow retry and refund proof');
$exhausted=clone $capOp->fresh();$attempt=$exhausted->attempt;$attempt['capacity_deferrals']=array_fill(0,6,$clock->now()->format(DATE_ATOM));$exhausted->setAttribute('attempt',$attempt);
expect(!Paymenter\Extensions\Servers\Domains\Services\OperationClaims::testCallRefusalRetryable($exhausted),'Six capacity deferrals exhaust the TEST retry budget');
Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireUnsent($exhausted,$capDomain);
try{Paymenter\Extensions\Servers\Domains\Services\StaffOperationWrites::release($exhausted,['account'=>$a,'domain'=>$capDomain],$clock->now());throw new LogicException('Exhausted TEST retries cannot be released.');}catch(DomainException $e){same('This hold requires its original recovery or evidence path.',$e->getMessage(),'Exhausted TEST retries refuse readmission with complete domain facts');}
same('held',$capOp->fresh()->state,'Exhausted TEST capacity proof remains held and refundable');
$clock->at=$clock->at->modify('+300 seconds');
$releaseTicket=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$capDomain->id,(int)$capOp->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($capOp->fresh(),$capDomain),$clock);
app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$capDomain->id,(int)$capOp->id,'release',$releaseTicket,'Registry availability will be rechecked after a delayed safe readmission');
$used=enomTestSendCount();config(['domains.enom_test_sends_per_day'=>$used+5]);
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();if($runtimeTurn){DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);}
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$capOp->id]);DB::table('domains_runtime_operations')->where('operation_id',$capOp->id)->update(['due_at'=>0]);
// Facts refreshes are covered separately; the synthetic fixture costs cannot rebuild renewal terms, so keep them not due here.
$offerQueue=DB::table('domains_observation_queues')->where('server_id',$server->id);$work=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($offerQueue->value('offer_work'));
foreach(array_keys($work['facts']??[]) as $ending){$work['facts'][$ending]['due_at']=$clock->now()->getTimestamp()+86400;}$offerQueue->update(['offer_work'=>json_encode($work,JSON_THROW_ON_ERROR)]);
$before=count($GLOBALS['enomWire']['forms']);Cache::flush();Queue::fake();app(RuntimeWork::class)->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$capOp->id);
$worker->operation($capOp->id);
same(['Check','PE_GetProductPrice','GetBalance','Purchase'],array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),'Released retry performs fresh admission and one Purchase without pre-proof nameserver reads: '.json_encode([array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command'),$capOp->fresh()->state,$capOp->fresh()->safe_code,$capOp->fresh()->attempt]));
same('pending',$capOp->fresh()->state,'Recovered operation has one acknowledged Purchase');
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();
config(['domains.enom_test_sends_per_day'=>500]);$refundOp=$new(placeholderComFixtureName('cap-refund-placeholder.com'));$used=enomTestSendCount();config(['domains.enom_test_sends_per_day'=>$used+3]);
$worker->operation($refundOp->id);same(['held','enom_test_call_cap'],[$refundOp->fresh()->state,$refundOp->fresh()->safe_code],'Refund case starts from a proven-unsent cap hold');
same(['amount'=>$refundOp->amount,'currency'=>'USD'],app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class)->refundHeld($staff,$refundOp->id,'enom_test_call_cap'),'Cap-held operation can be refunded exactly once');
expect(isset($refundOp->fresh()->funding['refund']),'Cap refusal history remains valid through account credit');

$nativeSuccessful=true;
