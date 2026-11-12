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
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$reconcileCapOp=$new(placeholderComFixtureName('reconcile-cap-placeholder.com'));$worker->operation($reconcileCapOp->id);same('pending',$reconcileCapOp->fresh()->state,'Second Purchase ACK remains Processing');
$GLOBALS['testRegisteredName']=$reconcileCapOp->domain->domain;$used=enomTestSendCount();config(['domains.enom_test_sends_per_day'=>$used+3]);
$before=count($GLOBALS['enomWire']['forms']);(new RuntimeWork($clock,new RuntimePorts))->operation($reconcileCapOp->id);
same(3,count($GLOBALS['enomWire']['forms'])-$before,'Mid-reconciliation cap permits only three reads');same('pending',$reconcileCapOp->fresh()->state,'Cap during reconciliation remains Processing');same('enom_test_reconciliation_call_cap',$reconcileCapOp->fresh()->safe_code,'Staff sees the reconciliation cap reason');same('enom_test_reconciliation_call_cap',$reconcileCapOp->fresh()->policy['reconciliation']['reason'],'Reconciliation retains the cap reason');
same('Registrar has not confirmed the result',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('enom_test_reconciliation_call_cap'),'Staff wording reflects cap recovery and possible sent Purchase');
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($reconcileCapOp->id);same($before,count($GLOBALS['enomWire']['forms']),'Duplicate reconciliation cannot resend Purchase or exceed cap');same('pending',$reconcileCapOp->fresh()->state,'Duplicate remains Processing');
config(['domains.enom_test_sends_per_day'=>enomTestSendCount()+7]);
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();
$GLOBALS['enomWire']['execFail']=true;$before=count($GLOBALS['enomWire']['forms']);$worker->operation($reconcileCapOp->id);$GLOBALS['enomWire']['execFail']=false;
expect(count($GLOBALS['enomWire']['forms'])>$before,'Unrelated reconciliation failure consumes its reserved reads');
same('reconciliation_required',$reconcileCapOp->fresh()->safe_code,'A later non-cap read failure clears the stale cap safe code');
same('reconciliation_required',$reconcileCapOp->fresh()->policy['reconciliation']['reason'],'A later non-cap read failure updates its reconciliation reason');
config(['domains.enom_test_sends_per_day'=>enomTestSendCount()+8]);Cache::flush();
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
if($runtimeTurn){DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);}
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$reconcileCapOp->id]);
DB::table('domains_runtime_operations')->where('operation_id',$reconcileCapOp->id)->update(['due_at'=>0]);
$before=count($GLOBALS['enomWire']['forms']);Cache::flush();Queue::fake();(new RuntimeWork($clock,new RuntimePorts))->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$reconcileCapOp->id);
same($before,count($GLOBALS['enomWire']['forms']),'Runtime scheduler queues paused reconciliation without provider traffic');
Queue::pushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$reconcileCapOp->id)->sole()->handle(new RuntimeWork($clock,new RuntimePorts));
$resumeCommands=array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command');same(['GetDomainInfo','GetRenew','GetRegLock','GetWPPSInfo','GetDomainInfo','GetDomainExp','GetOrderDetail','GetDNS'],$resumeCommands,'Raised cap resumes bounded proof and post-proof nameserver reads: '.json_encode($resumeCommands));same('succeeded',$reconcileCapOp->fresh()->state,'Resumed reconciliation completes without a new Purchase: '.json_encode([$resumeCommands,$reconcileCapOp->fresh()->safe_code,$reconcileCapOp->fresh()->policy['reconciliation']??null]));same(['ns1.provider.example','ns2.provider.example'],$reconcileCapOp->domain->fresh()->observation['nameservers']['nameservers']??null,'Resumed reconciliation stores observed nameservers');unset($GLOBALS['testRegisteredName']);

$nativeSuccessful=true;
