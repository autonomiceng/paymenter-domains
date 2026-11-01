<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real guards/dispatch, synthetic registrar only.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
use Illuminate\Support\Facades\Queue;
use Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter;
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances,RuntimeSchema,RuntimeDispatch,RuntimeWork,RuntimePorts,TransferInputs,AccountReadBudget,Runtime};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
RuntimeSchema::install();$dispatch=new RuntimeDispatch($clock);$cohort=[];
// Build genuine sent holds on one account; then exercise the shared ledger without resetting it.
for($i=0;$i<7;$i++){
    $op=$prepare('capacity-'.$i);$observe->status='Domain is Locked';$observe->fault='reject';
    $cohort[]=$reconcile->run($op->id);
}
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,8*86400,'namesilo_transfer_v1'));
$clock->instant=new DateTimeImmutable('@'.$cohort[0]->policy['reconciliation']['next_at']);
// Six one-read polls fit; the seventh still exercises the real shared-capacity refusal.
app()->instance(Runtime::class,new Runtime);app()->instance(ReadLimiter::class,new AccountReadBudget($clock,6));
$observe->status='Pending at Registry';$observe->fault='';$totals=array_fill(0,7,0);
$turn=static function()use($cohort,$dispatch,$reconcile,$observe,&$totals):void {
    Queue::fake();
    foreach($cohort as $i=>$op){
        $dispatch->enqueue($op->id);
        if(!Queue::pushed(RunOperation::class)->contains(fn($job)=>$job->operationId===$op->id)){continue;}
        $observe->name=$op->domain->domain;$before=count($observe->calls);$reconcile->run($op->id);
        $totals[$i]+=count($observe->calls)-$before;
    }
};
for($round=0;$round<3;$round++){
    $before=$totals;$turn();
    for($minute=0;$minute<2;$minute++){$clock->instant=$clock->now()->modify('+61 seconds');$turn();}
    foreach($totals as $i=>$count){expect($count>$before[$i],'Every transfer gets checked despite ascending-ID contention: '.json_encode($totals));}
    $clock->instant=new DateTimeImmutable('@'.max(array_map(fn($op)=>$op->fresh()->policy['reconciliation']['next_at'],$cohort)));
}
same([3,3,3,3,3,3,3],$totals,'All seven get three one-wire status checks under a tighter shared guard');
// An odd status requires full reads; competing work leaves a partial second pass and zero-wire peers.
$observe->status='unknown';
$budget=new AccountReadBudget($clock);$token=$budget->acquire($binding,RequestOrigin::Background);$budget->release($binding,RequestOrigin::Background,$token);
$turn();$partial=$cohort[1]->fresh();$last=$cohort[6]->fresh();
expect(isset($partial->policy['reconciliation']['capacity_wait_since']),'A partial capacity refusal also retains its fair place');
expect(isset($last->policy['reconciliation']['capacity_wait_since']),'Last transfer has a real zero-wire refusal');
$polls=$partial->policy['reconciliation']['polls'];
// Both can wait through an outage; partial reads keep backoff, but win their next fair turn.
$clock->instant=$clock->now()->modify('+12 hours');Queue::fake();
app()->instance(RuntimeDispatch::class,$dispatch);app()->instance(TransferInputs::class,$inputs);
(new RuntimeWork($clock,new RuntimePorts))->tickOperations();
same($partial->id,Queue::pushed(RunOperation::class)->first()->operationId,'Oldest partial capacity waiter queues before newly due lower IDs');
$observe->name=$partial->domain->domain;$r=$reconcile->run($partial->id);
same($polls+1,$r->policy['reconciliation']['polls'],'Previously partial transfer obtains a complete reconciliation pass');
echo "Seven-transfer shared-capacity fairness and prompt automatic retries passed.\n";$nativeSuccessful=true;
