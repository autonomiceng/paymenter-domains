<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-send-bound.php';

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

$nativeSuccessful = true;
echo "Management read ownership and expiry passed.
";
