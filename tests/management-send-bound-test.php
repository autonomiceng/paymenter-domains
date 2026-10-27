<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Offline native operation tests. All provider responses are local protocol fakes.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement, ManagementEvidence, WriteFeatures};

$uncertain = static function (string $name) use ($holding, $submit, $management, $state): array {
    [$d, $driver] = $holding(placeholderComFixtureName($name.'-placeholder.com'));
    $state->apply = false;
    $op = $submit($d, $driver, 'lock', ['locked' => false]);
    same('uncertain', $management->run($op->id, $driver)->state, 'Unconfirmed write remains uncertain');
    same(1, $op->fresh()->attempt['management_observation_attempts'], 'Inline readback is reserved before I/O');
    return [$d, $driver, $op];
};

// A throwing second read consumes both wires, but never appends completed history.
[$d, $driver, $op] = $uncertain('bound-throw');
$driver = new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management(
    $driver->capabilities()->binding,
    static function ($origin, $command, $parameters, $timeout) use ($exchange): string {
        $reply = $exchange($origin, $command, $parameters, $timeout);
        if ($command === 'GetRegLock') { throw new RuntimeException('Offline transport failure.'); }
        return $reply;
    });
for ($i = 2; $i <= 20; $i++) {
    $before = count($state->calls);
    $management->reconcile($op->id, $driver);
    same($before + 2, count($state->calls), 'Throwing read reaches both provider fakes');
    same($i, $op->fresh()->attempt['management_observation_attempts'], 'Throwing read consumes one durable observation');
    same(1, count($op->fresh()->attempt['management_reads']), 'Throwing read adds no completed history');
}
$before = count($state->calls);
for ($i = 0; $i < 2; $i++) { $management->reconcile($op->id, $driver); $management->run($op->id, $driver); }
same($before, count($state->calls), 'Lifetime limit refuses repeated entries before I/O');
same(42, $before, 'No-deferral case uses 1 prepare + 1 write + 20 pairs');
commerceReject(fn () => $op->fresh()->update(['attempt' => [...$op->fresh()->attempt, 'management_observation_attempts' => 19]]));
commerceReject(fn () => $op->fresh()->update(['attempt' => array_diff_key($op->fresh()->attempt, ['management_observation_attempts' => true])]));
commerceReject(fn () => $op->fresh()->update(['attempt' => [...$op->fresh()->attempt, 'management_observation_attempts' => 21]]));

// Five preparation deferrals retain their own durable history. A sixth can send.
[$d, $driver] = $holding(placeholderComFixtureName('bound-defer-placeholder.com'));
$state->apply = false;
$op = $submit($d, $driver, 'lock', ['locked' => false]);
for ($i = 1; $i <= 5; $i++) {
    $state->during = static function () use ($op): void { Cache::put('domains-write-slot:'.$op->account_fingerprint, true, 25); };
    same('queued', $management->run($op->id, $driver)->state, 'Preparation defers before seal');
    same($i, count($op->fresh()->attempt['management_deferrals']), 'Deferral is durable');
    same(false, isset($op->fresh()->attempt['management_observation_attempts']), 'Unsealed preparation has no readback');
    same($i, count($state->calls), 'Each deferred preparation used one ownership wire');
    Cache::flush();
}
same('uncertain', $management->run($op->id, $driver)->state, 'Sixth preparation can perform one write');
for ($i = 1; $i < 20; $i++) { $management->reconcile($op->id, $driver); }
same(47, count($state->calls), 'Worst case is 6 prepares + 1 write + 20 pairs');
same(20, count($op->fresh()->attempt['management_reads']), 'Completed history remains bounded at twenty');
$before = count($state->calls);
$management->reconcile($op->id, $driver);
same($before, count($state->calls), 'Full history refuses before I/O');
// An inconsistent restored fixture must also honor the independent history gate.
$attempt = [...$op->fresh()->attempt, 'management_observation_attempts' => 19];
DB::table('domains_operations')->where('id', $op->id)->update([
    'attempt' => $op->fresh()->forceFill(['attempt' => $attempt])->getAttributes()['attempt']]);
$management->reconcile($op->id, $driver);
same($before, count($state->calls), 'Full completed history refuses independently of the attempt counter');

[$d, $driver] = $holding(placeholderComFixtureName('bound-defer-exhausted-placeholder.com'));
$op = $submit($d, $driver, 'lock', ['locked' => false]);
for ($i = 0; $i < 6; $i++) {
    $state->during = static function () use ($op): void { Cache::put('domains-write-slot:'.$op->account_fingerprint, true, 25); };
    $management->run($op->id, $driver); Cache::flush();
}
same('held', $op->fresh()->state, 'Sixth deferral stops preparation');
same(5, count($op->fresh()->attempt['management_deferrals']), 'Deferral history cannot grow past five');
same(true, ManagementEvidence::idle($op->fresh()), 'Exhausted unsent operation retains its renewal-safe proof');
$before = count($state->calls); $management->run($op->id, $driver);
same($before, count($state->calls), 'Held preparation cannot retry');

// The second observer enters while the first is outside locks at its last slot.
[$d, $driver, $op] = $uncertain('bound-concurrent');
for ($i = 1; $i < 19; $i++) { $management->reconcile($op->id, $driver); }
$other = new DomainManagement($bindings, $clock, new WriteFeatures(management: true), true);
$before = count($state->calls);
$state->during = static function () use ($other, $op, $driver, $state): void {
    same(20, $op->fresh()->attempt['management_observation_attempts'], 'Last slot committed before provider callback');
    $before = count($state->calls); $other->reconcile($op->id, $driver);
    same($before, count($state->calls), 'Concurrent observer cannot spend the same last slot');
};
$management->reconcile($op->id, $driver);
same($before + 2, count($state->calls), 'Exactly one final observation reaches provider fakes');

// Old sealed attempts cannot claim a zero balance from an incomplete history.
[$d, $driver, $op] = $uncertain('bound-legacy');
$attempt = array_diff_key($op->fresh()->attempt, ['management_observation_attempts' => true]);
DB::table('domains_operations')->where('id', $op->id)->update(['attempt' => $op->fresh()->forceFill(['attempt' => $attempt])->getAttributes()['attempt']]);
$before = count($state->calls); $management->reconcile($op->id, $driver);
same($before, count($state->calls), 'Unknown legacy usage refuses before I/O');
commerceReject(fn () => $op->fresh()->update(['attempt' => [...$op->fresh()->attempt, 'management_observation_attempts' => 1]]));
$nativeSuccessful = true;
echo "Management lifetime send bound: throwing read, deferrals, full history, concurrent observer, legacy refusal, 47-send maximum passed.\n";
