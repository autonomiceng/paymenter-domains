<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-send-bound.php';

use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationSchema;
use Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainHistory;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement, WriteFeatures};

$other = new DomainManagement($bindings, $clock, new WriteFeatures(management: true), true);
// A worker owns the read before I/O even with spare budget. A restart can take over only after expiry.
[$d, $driver, $op] = $uncertain('bound-read-owner');
$before = count($state->calls);
$overlap = $reserved = $successor = null;
$state->during = static function () use ($other, $op, $driver, $state, $clock, &$overlap, &$reserved, &$successor): void {
    $inside = count($state->calls);
    $other->reconcile($op->id, $driver);
    $overlap = count($state->calls) - $inside;
    $reserved = $op->fresh()->attempt['management_observation_attempts'];
    $clock->instant = $clock->now()->modify('+251 seconds');
    $state->locked = false;
    $successor = $other->reconcile($op->id, $driver)->state;
};
$final = $management->reconcile($op->id, $driver)->state;
same(0, $overlap, 'Live read claim blocks a competing read before the budget is exhausted');
same(2, $reserved, 'Competing worker spends no slot');
same('succeeded', $successor, 'A successor confirms after the prior read expires');
same('succeeded', $final, 'Stale finisher preserves the successor result');
same(3, $op->fresh()->attempt['management_observation_attempts'], 'Two distinct read claims plus the original inline read');
same(2, count($op->fresh()->attempt['management_reads']), 'Only the current read can append the second result');
same($before + 3, count($state->calls), 'Successor uses only its two read wires');

// The expired sender fails while the successor owns a recovery read and waits on the registrar.
[$d, $driver] = $holding(placeholderComFixtureName('bound-stale-send-placeholder.com'));
$op = $submit($d, $driver, 'lock', ['locked' => false]);
$recovery = new Fiber(fn () => $other->reconcile($op->id, $driver));
$state->afterWrite = static function () use ($state, $clock, $recovery): void {
    $clock->instant = $clock->now()->modify('+361 seconds');
    $state->during = static fn () => Fiber::suspend();
    $recovery->start();
    throw new RuntimeException('Synthetic expired send reply');
};
$stale = $management->run($op->id, $driver);
expect($recovery->isSuspended(), 'Successor is waiting on the registrar with its read reserved');
$recovery->resume();
$findings = [];
if ($recovery->getReturn()->state !== 'succeeded' || $stale->state !== 'running'
    || $op->fresh()->attempt['management_observation_attempts'] !== 2) {
    $findings[] = 'The stale sender invalidated its successor’s confirmed read';
}
if ($op->fresh()->state !== 'succeeded') {
    $clock->instant = $clock->now()->modify('+61 seconds');
    $other->reconcile($op->id, $driver);
}

// Keep the same holding and acquisition while growing the management history.
$readHistory = static function ($holding, array $requests): int {
    DB::enableQueryLog();
    DB::flushQueryLog();
    try { $entries = DomainHistory::entries($holding); $queries = DB::getQueryLog(); }
    finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    foreach ($requests as $request) {
        expect(in_array('sent-'.$request->id, array_column(array_filter($entries, 'is_array'), '__key'), true),
            'Every sealed management request retains its send event');
    }
    return count(array_filter($queries, static fn ($query) => str_contains($query['query'], OperationSchema::ATTEMPTS)
        || str_contains($query['query'], OperationSchema::EVIDENCE)));
};
$small = $readHistory($d, [$op]);
$requests = [$op];
foreach ([true, false, true] as $locked) {
    Cache::flush();
    $change = $submit($d, $driver, 'lock', ['locked'=>$locked]);
    $requests[] = $management->run($change->id, $driver);
}
$large = $readHistory($d, $requests);
if ($large !== $small) { $findings[] = "Holding history added ledger reads per request ($small to $large)"; }
expect($findings === [], implode(PHP_EOL, $findings));

$nativeSuccessful = true;
echo "Management read ownership and expiry passed.
";
