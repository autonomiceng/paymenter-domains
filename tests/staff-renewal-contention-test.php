<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';

use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{MoneyDecisions, MoneyWorkSchema, RegistrarCheck};
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalCompletion;

MoneyWorkSchema::install();
$target = null; $failures = 0; $attempts = 0; $failCapture = false;
Operation::updated(static function (Operation $op) use (&$target, &$failCapture, &$failures): void {
    $audit = $op->policy['staff_actions'] ?? [];
    if (!$failCapture || $op->domain_id !== $target || (end($audit)['phase'] ?? null) !== 'read_requested') { return; }
    $failCapture = false;
    // The staff request audit commits before the renewal's first money decision.
    DB::afterCommit(static function () use (&$failures): void { $failures = 2; });
});
Domain::retrieved(static function (Domain $domain) use (&$target, &$failures, &$attempts): void {
    if ($domain->id !== $target || DB::transactionLevel() !== 1 || $failures < 1) { return; }
    $attempts++; $failures--;
    $cause = new PDOException('Synthetic renewal lock wait'); $cause->errorInfo = ['HY000', 1205, 'Synthetic'];
    throw new QueryException('sqlite', 'synthetic staff renewal lock', [], $cause);
});
// Each locked phase must refuse honestly, including when the registrar read already finished.
foreach (['capture', 'record'] as $phase) {
    [$op, $domain, $binding] = $makeRenewal(placeholderComFixtureName('staff-busy-'.$phase.'-placeholder.com'));
    $runRenewal($op, $binding);
    $state->after = '2027-10-18';
    $completion = app(RenewalCompletion::class);
    $completion->run($op->id);
    same('pending', $op->fresh()->state, 'The registrar has not confirmed another year');
    same('checked', RegistrarCheck::status($op->fresh())['outcome'], 'The original pending renewal has an earlier checked result');
    $before = [renewalRecord($op), $op->fresh()->funding, $op->fresh()->policy['renewal_polls']];
    $reads = count($state->calls); $target = $domain->id; $attempts = 0;
    $failCapture = $phase === 'capture';
    if (!$failCapture) { $state->during = static function () use (&$failures): void { $failures = 2; }; }
    $page = Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class, ['record'=>$op->id]);
    $page->callAction('recheck');
    same(2, $attempts, 'The staff read exhausts the one fresh retry');
    $history = $op->fresh()->policy['staff_actions'];
    same(['read_requested', 'read_failed'], array_column($history, 'phase'), 'A deferred staff read is never audited as finished');
    same($before, [renewalRecord($op), $op->fresh()->funding, $op->fresh()->policy['renewal_polls']], 'The failed decision preserves send, money and read-count evidence');
    same(0, DB::table(MoneyWorkSchema::TABLE)->count(), 'Interactive contention queues no automatic work');
    $page->assertNotified(Notification::make()->danger()->persistent()->title(OperationActions::FAILED)->body(MoneyDecisions::BUSY))
        ->assertNotNotified(RegistrarCheck::for($op->fresh())['title']);
    same($phase === 'record', count($state->calls) > $reads, 'Only contention after capture performs a provider read');
}
echo "Staff renewal capture and record contention refuse without a finished audit or stale success notification.\n";
$nativeSuccessful = true;
