<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real persisted manual authorization and shared capacity must survive dispatch deferral.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter;
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,HistoryAcceptances,Runtime,TransferPolling};
$op=$prepare('manual-admission');$deadline=TransferPolling::deadline($op);HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,9*86400,'namesilo_transfer_v1'));
$observe->status='Pending at Registry';$clock->instant=new DateTimeImmutable('@'.TransferPolling::deadline($op));$r=$reconcile->run($op->id);
same('done',$r->policy['reconciliation']['final_check'],'Original final check is already spent');
$reconcile->resume($r->id,'provider_facts_updated');$budget=new AccountReadBudget($clock);
for($i=0;$i<25;$i++){$token=$budget->acquire($binding,RequestOrigin::Background);$budget->release($binding,RequestOrigin::Background,$token);}
$observe->calls=[];$observe->status='Transfer Completed';$r=$reconcile->run($op->id);
same([],$observe->calls,'Our capacity refusal performs no read');
same(true,$r->policy['reconciliation']['manual_check'],'Capacity refusal retains the authorized recovery check');
same(0,count($r->policy['reconciliation_resumptions']??[]),'No-read refusal spends no resumption from the twenty');
$clock->instant=$clock->now()->modify('+61 seconds');expect(TransferPolling::due($r,$clock->now()->getTimestamp()),'Authorized recovery becomes due without another staff action');
app()->instance(Runtime::class,new Runtime);app()->instance(ReadLimiter::class,new AccountReadBudget($clock,2));$r=$reconcile->run($op->id);
same(true,$r->policy['reconciliation']['manual_check'],'Partial admission also retains the authorized bounded recovery');
same(1,count($r->policy['reconciliation_resumptions']??[]),'A recovery that really read is counted once');
$clock->instant=new DateTimeImmutable('@'.$r->policy['reconciliation']['next_at']);app()->instance(ReadLimiter::class,new AccountReadBudget($clock,48));
$r=$reconcile->run($op->id);same('succeeded',$r->state,'Authorized recovery completes automatically when capacity returns');
same(false,$r->policy['reconciliation']['manual_check'],'Completed recovery consumes its authorization');
same(1,count($r->policy['reconciliation_resumptions']),'Partial retries do not consume another resumption');
same($deadline,TransferPolling::deadline($r),'Manual recovery and its capacity retries never move the sealed window');
echo "Manual recovery survives capacity refusals without spending unused resumptions.\n";$nativeSuccessful=true;
