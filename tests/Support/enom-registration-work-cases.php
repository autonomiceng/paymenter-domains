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
// Default processing off holds a native paid order without opening a provider request.
$paused=$new(placeholderComFixtureName('processing-off-placeholder.com'));$write('processing',['enabled'=>false,'confirmed'=>true]);$before=count($GLOBALS['enomWire']['forms']);$pausedUsed=enomTestSendCount();$worker->operation($paused->id);
same(['held','processing'],[$paused->fresh()->state,$paused->fresh()->safe_code],'Paid TEST order reports the staff processing hold');
same($before,count($GLOBALS['enomWire']['forms']),'Processing-off queued registration sends no Enom request');same($pausedUsed,enomTestSendCount(),'Processing-off does not reserve a TEST call');
$write('processing',['enabled'=>true,'confirmed'=>true]);expect($a->fresh()->processing_enabled,'Staff deliberately enabled processing');
$write('publish',[],$tld->id); // Resume invalidates the old public offer fence.
$pausedDomain=$paused->domain->fresh();$releaseTicket=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket((int)$staff->id,(int)$pausedDomain->id,(int)$paused->id,'release',
    Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($paused->fresh(),$pausedDomain),$clock);
$before=count($GLOBALS['enomWire']['forms']);app(Paymenter\Extensions\Servers\Domains\Services\StaffOperations::class)->run($staff,(int)$pausedDomain->id,(int)$paused->id,'release',$releaseTicket,'Processing is enabled and the unsent registration can be reviewed again');
same('queued',$paused->fresh()->state,'Paid TEST order held before claim can be released after processing is enabled');same($before,count($GLOBALS['enomWire']['forms']),'Staff release sends no TEST request');
// Runtime route evidence is compiled; cross-environment and interval refusal remains in enom-protocol-test.php.
$op=$new(placeholderComFixtureName('paid-test-placeholder.com'));$before=count($GLOBALS['enomWire']['forms']);$usedBeforePurchasePath=enomTestSendCount();$GLOBALS['advanceDuringPreflight']=true;$worker->operation($op->id);
$purchaseCommands=array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command');
same(['Check','PE_GetProductPrice','GetBalance','Purchase'],$purchaseCommands,'Three preflights then one sealed Purchase; no reconciliation read before completion proof: '.json_encode([$purchaseCommands,$op->fresh()->state,$op->fresh()->safe_code,DB::table(Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::TABLE)->where('server_id',$server->id)->first()]));same('pending',$op->fresh()->state,'ACK remains Processing');
same($usedBeforePurchasePath+4,enomTestSendCount(),'Only preflight and Purchase reserve four call units before completion proof');
$before=count($GLOBALS['enomWire']['forms']);$GLOBALS['testRegisteredName']=$op->domain->domain;
$offlinePsl=static fn():never=>throw new Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed');
app()->instance(Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,new Paymenter\Extensions\Servers\Domains\Services\PslTransport($offlinePsl));
$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
if(!$runtimeTurn){DB::table('domains_runtime_turns')->insert(['server_id'=>$server->id,'account_fingerprint'=>$binding->fingerprint,'operation_cursor'=>0,'domain_cursor'=>0,'tld_cursor'=>0,'turn'=>0,'due_at'=>0,'finished_at'=>0]);$runtimeTurn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();}
DB::table('domains_runtime_turns')->where('id',$runtimeTurn->id)->update(['operation_cursor'=>0,'due_at'=>0]);
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$op->id]);DB::table('domains_runtime_operations')->where('operation_id',$op->id)->update(['due_at'=>0]);
Cache::flush();Queue::fake();app(RuntimeWork::class)->tick();
Queue::assertPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$op->id);
same($before,count($GLOBALS['enomWire']['forms']),'Scheduler queues captured-order reconciliation without making reads in the scheduler turn');
(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same('succeeded',$op->fresh()->state,'Restarted worker reconciles captured OrderID with history closed');
same(8,count($GLOBALS['enomWire']['forms'])-$before,'Captured OrderID uses seven proof reads and one post-proof nameserver read: '.json_encode(array_column(array_slice($GLOBALS['enomWire']['forms'],$before),'Command')));$d=$op->domain->fresh();
same('active',$d->status,'Ownership confirmed');same('10.00',$op->fresh()->cost['actual']['amount'],'Actual charge');expect(isset($d->renewal_policy['billing_anchor']),'Billing anchor established');same(null,$d->service->expires_at,'Native expiry stays null');
same(['ns1.provider.example','ns2.provider.example'],$d->observation['nameservers']['nameservers']??null,'Confirmed TEST registration stores provider nameservers as observed');
$before=count($GLOBALS['enomWire']['forms']);$worker->operation($op->id);same($before,count($GLOBALS['enomWire']['forms']),'Completed duplicate no calls');unset($GLOBALS['testRegisteredName']);
same('registration_pending',$paused->domain->fresh()->status,'Processing-off paid TEST reservation remains registration pending');
same('active',$op->domain->fresh()->status,'Captured-order registration completes to active before scheduler check');
app(ObservationWork::class)->enroll((int)$server->id);$usedBeforePoll=enomTestSendCount();
$queuedBefore=DB::table('domains_observation_work')->where('server_id',$server->id)->get(['id','domain_id','tries'])->map(fn($r)=>(array)$r)->all();
$before=count($GLOBALS['enomWire']['forms']);$observation=app(ObservationWork::class)->tick();
same('read_deferred',$observation[$server->id]['holding']??null,'Opted-in TEST registration skips general domain observation');
// Activation queues one first read of the new domain; the general tick adds none and runs none.
$queued=DB::table('domains_observation_work')->where('server_id',$server->id)->get(['id','domain_id','tries'])->map(fn($r)=>(array)$r)->all();
same($queuedBefore,$queued,'General observation creates no queued domain reads');
same([[(int)$op->domain_id,0]],array_map(fn(array $r)=>[(int)$r['domain_id'],(int)$r['tries']],$queued),'Only the untried activation read of the new domain is queued');
same($before,count($GLOBALS['enomWire']['forms']),'Observation tick sends no Enom reads');same($usedBeforePoll,enomTestSendCount(),'Observation tick reserves no Enom calls');
$turn=DB::table('domains_runtime_turns')->where('server_id',$server->id)->first();
if(!$turn){DB::table('domains_runtime_turns')->insert(['server_id'=>$server->id,'account_fingerprint'=>$binding->fingerprint,'operation_cursor'=>0,'domain_cursor'=>0,'tld_cursor'=>0,'turn'=>0,'due_at'=>0,'finished_at'=>0]);}
else{DB::table('domains_runtime_turns')->where('id',$turn->id)->update(['operation_cursor'=>0,'domain_cursor'=>0,'tld_cursor'=>0,'due_at'=>0]);}
DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$unpaidOp->id]);DB::table('domains_runtime_operations')->where('operation_id',$unpaidOp->id)->update(['attempts'=>0,'due_at'=>0]);
$usedBeforePoll=enomTestSendCount();$before=count($GLOBALS['enomWire']['forms']);Cache::flush();Queue::fake();app(RuntimeWork::class)->tick();
Queue::assertNotPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,static fn($job)=>$job->operationId===$unpaidOp->id);
same('rejected',$unpaidOp->fresh()->state,'Retired unpaid TEST operation remains terminal and is not requeued');
same($before,count($GLOBALS['enomWire']['forms']),'Runtime tick sends no Enom reads');same($usedBeforePoll,enomTestSendCount(),'Runtime tick reserves no Enom calls');

$nativeSuccessful=true;
