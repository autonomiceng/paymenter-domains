<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native locks and real Enom parsing must prove settlement after 25 physical reads.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-enom-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter;
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,HistoryAcceptances,Runtime,TransferPolling};
use Paymenter\Extensions\Servers\Domains\Presentation\TransferNotice;
$op=$prepare(placeholderComFixtureName('full-budget-transfer-placeholder.com'));$sent=$state->calls;
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,9*86400,'enom_transfer_v1'));
$observe->orders=17;$clock->instant=new DateTimeImmutable('@'.TransferPolling::deadline($op));
$r=$reconcile->run($op->id);
same('succeeded',$r->state,'A valid final pass requiring all 25 reads completes automatically');
same(25,count($observe->calls),'The terminal pass fits the original request budget, without a second scan');
same($sent,$state->calls,'Full-budget completion never resends the transfer');
$op=$prepare(placeholderComFixtureName('overdue-capacity-transfer-placeholder.com'));
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,9*86400,'enom_transfer_v1'));
$clock->instant=new DateTimeImmutable('@'.TransferPolling::deadline($op));app()->instance(Runtime::class,new Runtime);app()->instance(ReadLimiter::class,new AccountReadBudget($clock,1));
$r=$reconcile->run($op->id);$notice=TransferNotice::for($r,$clock->now()->getTimestamp());
same(true,$r->policy['reconciliation']['staff_alert'],'Unresolved overdue capacity alerts staff while safe retries continue');
expect(str_contains(strtolower($notice['body']),'support'),'Overdue capacity gives the owner and staff a resolution path');
expect(TransferPolling::due($r,$r->policy['reconciliation']['next_at']),'The overdue alert does not cancel automatic recovery');
app()->instance(ReadLimiter::class,new AccountReadBudget($clock,48));
echo "Full-budget terminal progress and overdue capacity escalation passed.\n";$nativeSuccessful=true;
