<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$transferEnvironment = Paymenter\Extensions\Servers\Domains\Data\Environment::Test;
$fixtureRetainedTransfer = true;
require __DIR__.'/Support/transfer-completion-enom-fixture.php';

use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, EnomTestSendLimitSchema, HistoryAcceptances, RegistrarRegistry, OperationReconciliation, RuntimeDispatch, RuntimeSchema, TransferPolling};

RuntimeSchema::install();
EnomTestSendLimitSchema::install();
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class, $clock);
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], $verifier, static function ($binding, $credentials) use ($wire) {
    $driver = new Enom\Driver(new Enom\Api\Client($binding, 'account-enom', $credentials['token'], $wire), ['com']);
    return new Enom\EnomTestReconciliationReader(new Enom\CataloguePreview($driver), $driver);
});
$readBindings = new AccountBindings($registry);
$reconcile = new OperationReconciliation($claims, $readBindings, $clock);
$pending = function (string $label) use ($prepare, $reconcile, $observe, $clock, &$binding) {
    config(['domains.enom_writes.transfer' => false]);
    $op = $prepare(placeholderComFixtureName($label.'-placeholder.com'));
    config(['domains.enom_writes.transfer' => true, 'domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true]);
    $observe->charge = $op->attempt['authorized_cost']['amount'];
    $observe->billDate = (new DateTimeImmutable($op->attempt['dispatch_attempted_at']))->setTimezone(new DateTimeZone('America/Denver'))
        ->modify('+1 second')->format('m/d/Y g:i:s A');
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 16*86400, 'enom_transfer_v1'));
    $observe->fault = 'pending';
    $observe->unowned = true;
    $observe->orders = 0;
    $clock->instant = new DateTimeImmutable('@'.TransferPolling::deadline($op));
    $op = $reconcile->run($op->id);
    same('done', $op->policy['reconciliation']['final_check'], 'The existing final check ran');
    $observe->calls = [];
    $clock->instant = $clock->now()->modify('+1 day');
    return $op;
};

// The legacy row has the original attempt-bound checked result and the old, inoperative backoff.
$op = $pending('overdue-legacy');
$p = $op->policy;
unset($p['reconciliation']['last_transfer_read']);
$p['status_check']['next_at'] = null;
$p['reconciliation']['next_at'] = $clock->now()->modify('+30 days')->getTimestamp();
$op->update(['policy' => $p]);
$now = $clock->now()->getTimestamp();
$other = $p;
$other['status_check']['attempt_identity'] = str_repeat('0', 64);
$op->update(['policy' => $other]);
same(false, TransferPolling::due($op, $now), 'A pending flag and elapsed time cannot adopt another attempt’s status');
$op->update(['policy' => $p]);
Queue::fake();
(new RuntimeDispatch($clock))->enqueue($op->id);
expect(Queue::pushed(RunOperation::class)->contains(fn($job) => $job->operationId === $op->id), 'Scheduler admits a proven old pending attempt inside the window');
$view = static fn($op, int $now) => TransferPolling::schedule($op, $now);
$expected = static fn($last, $result, $next, $reason) => [
    'last_read_at' => $last, 'last_result' => $result, 'next_read_at' => $next, 'stop_reason' => $reason,
];
same($expected($now-86400, 'pending', $now, null), $view($op, $now), 'Legacy schedule comes from a real checked result');
$notice = Paymenter\Extensions\Servers\Domains\Presentation\TransferNotice::class;
same(false, $notice::for($op, $now)['contact'] ?? true, 'A pending transfer with a scheduled check needs no support request');
same(true, $notice::for($op, TransferPolling::cutoff($op))['contact'] ?? true, 'When automatic checks expire, support is available');
$budget = new AccountReadBudget($clock);
for ($i=0; $i<25; $i++) {
    $token = $budget->acquire($binding, RequestOrigin::Background);
    $budget->release($binding, RequestOrigin::Background, $token);
}
$r = $reconcile->run($op->id);
same([], $observe->calls, 'Capacity refusal made no exchange');
same($op->policy['reconciliation']['checks'], $r->policy['reconciliation']['checks'], 'No-wire refusal spends no check');
same($expected($now-86400, 'pending', $now+60, null), $view($r, $now), 'Capacity retry preserves the prior observed result');
$clock->instant = $clock->now()->modify('+60 seconds');
$restart = new OperationReconciliation($claims, $readBindings, $clock);
$observe->during = function () use ($restart, $op) {
    $restart->resume($op->id, 'provider_facts_updated');
    $restart->run($op->id);
};
$r = $restart->run($op->id);
same(['TP_GetOrder'], $observe->calls, 'Overlapping staff and worker checks share the reserved read');
$now = $clock->now()->getTimestamp();
same($expected($now, 'pending', $now+86400, null), $view($r, $now), 'The worker records the winning read and its daily due time');
same(false, Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::readLimit($r),
    'A scheduled daily read cannot be restarted through audited recovery');
$clock->instant = $clock->now()->modify('+86399 seconds');
$restart->run($op->id);
same(['TP_GetOrder'], $observe->calls, 'Worker restart preserves daily pacing');
$clock->instant = $clock->now()->modify('+1 second');
$observe->fault = ''; $observe->unowned = false; $observe->orders = 1;
$sent = $state->calls;
// One status wire fits, but the remaining evidence cannot fit this account minute.
for ($i=0; $i<24; $i++) {
    $token = $budget->acquire($binding, RequestOrigin::Background);
    $budget->release($binding, RequestOrigin::Background, $token);
}
$r = $restart->run($op->id);
$now = $clock->now()->getTimestamp();
same($expected($now, 'incomplete', $now+86400, null), $view($r, $now), 'A partial pass spends the day and retries automatically');
$clock->instant = $clock->now()->modify('+1 day');
$r = $restart->run($op->id);
same('succeeded', $r->state, 'Late completion requires matching ownership, term and charge');
same($sent, $state->calls, 'Follow-up never resends a transfer');

$op = $pending('overdue-outage');
$observe->fault = 'down';
$r = $restart->run($op->id);
same(['TP_GetOrder'], $observe->calls, 'An overdue outage does not scan ownership or charges');
same($expected($clock->now()->getTimestamp(), 'unavailable', null, 'transfer_status_unavailable'), $view($r, $clock->now()->getTimestamp()), 'Outage is visible and escalates');
same(null, $refunds->creditRejected($r->id), 'An outage cannot authorize credit');

$op = $pending('overdue-funding');
DB::table('invoice_transactions')->where('invoice_id', $op->invoice_id)->increment('amount', 1);
$r = $restart->run($op->id);
same([], $observe->calls, 'A still-paid invoice with a changed funding receipt cannot authorize an overdue read');
same('transfer_authority_changed', $view($r, $clock->now()->getTimestamp())['stop_reason'], 'Changed funding stops the timer');

$op = $pending('overdue-rejection');
$before = nativeFinancialSnapshot($op); $sent = $state->calls;
$observe->fault = 'reject';
$r = $restart->run($op->id);
same('transfer_rejection_unreconciled', $view($r, $clock->now()->getTimestamp())['stop_reason'], 'Rejection with empty history needs review');
same(null, $refunds->creditRejected($r->id), 'A rejection hint does not authorize a credit');
same([$sent, $before], [$state->calls, nativeFinancialSnapshot($r)], 'A rejection hint leaves payments, credits and sends unchanged');

$op = $pending('overdue-disabled');
config(['domains.enom_writes.transfer' => false]);
$r = $restart->run($op->id);
same([], $observe->calls, 'Disabled TEST transfer reads stop at the installed reader guard');
same(null, $view($r, $clock->now()->getTimestamp())['next_read_at'], 'The installed reader refusal needs review');

$op = $pending('overdue-price');
$observe->fault = ''; $observe->unowned = false; $observe->orders = 1; $observe->charge = '10.95';
$r = $restart->run($op->id);
same('uncertain', $r->state, 'A late status and a different charge cannot settle automatically');
unset($observe->charge);

$op = $pending('overdue-short-term');
$observe->fault = 'short'; $observe->unowned = false; $observe->orders = 1;
$r = $restart->run($op->id);
same('uncertain', $r->state, 'Completed status with a contradictory term cannot settle');
same(null, TransferPolling::schedule($r, $clock->now()->getTimestamp())['next_read_at'], 'Unproved completion goes to review');

$op = $pending('overdue-generation');
DB::table('domains_domains')->where('id', $op->domain_id)->increment('authority_generation');
same('transfer_authority_changed', $view($op->fresh(), $clock->now()->getTimestamp())['stop_reason'], 'Changed holding generation stops the published schedule');
$restart->run($op->id);
same([], $observe->calls, 'A stale generation cannot read');

$op = $pending('overdue-cutoff');
$clock->instant = new DateTimeImmutable('@'.(TransferPolling::deadline($op)+7*86400));
$r = $restart->run($op->id);
same([], $observe->calls, 'Restart at cutoff never reads or backfills missed days');
same('transfer_followup_expired', TransferPolling::schedule($r, $clock->now()->getTimestamp())['stop_reason'], 'Cutoff exposes the review reason');
same(null, $refunds->creditRejected($r->id), 'Elapsed time cannot authorize a refund');
$restart->resume($op->id, 'provider_facts_updated');
$r = $restart->run($op->id);
expect(in_array('GetDomainInfo', $observe->calls, true), 'An explicit check after cutoff keeps full reconciliation');
same(1, count($r->policy['reconciliation_resumptions']), 'Explicit post-cutoff read is audited once');
same(null, $view($r, $clock->now()->getTimestamp())['next_read_at'], 'A staff check cannot extend the timer');

// Leave the locked reservation in storage, as if the worker died after a delayed wire.
$op = $pending('overdue-crash');
$reservedAt = $clock->now()->getTimestamp();
$claims->locked($op->id, fn($row) => TransferPolling::reserve($row, $reservedAt));
$wireDeadline = $op->fresh()->policy['reconciliation']['reading_until'];
$clock->instant = new DateTimeImmutable('@'.($wireDeadline - 1));
$attempt = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($op, $op->domain, $binding);
$context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding, RequestOrigin::Background,
    new DateTimeImmutable('@'.$wireDeadline), new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock);
$readBindings->reader($op->server_id)->reconcile($attempt, $context, overdue: true);
$clock->instant = new DateTimeImmutable('@'.($reservedAt + 86400));
$restart = new OperationReconciliation($claims, $readBindings, $clock);
$restart->run($op->id);
same(['TP_GetOrder'], $observe->calls, 'A day from reservation is too early after a delayed physical read');
$next = TransferPolling::schedule($op->fresh(), $clock->now()->getTimestamp())['next_read_at'];
expect($next >= $wireDeadline - 1 + 86400 && $next <= $wireDeadline + 86400, 'Restart waits a full day from the last possible admission');
$clock->instant = new DateTimeImmutable('@'.$next);
$restart->run($op->id);
same(['TP_GetOrder','TP_GetOrder'], $observe->calls, 'The abandoned check can run again at its safe due time');
echo "Bounded overdue transfer checks passed.\n";
$nativeSuccessful = true;
