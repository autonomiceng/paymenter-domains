<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';

Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
$queueRoot = Illuminate\Support\Facades\Queue::getFacadeRoot();
try {
    foreach (['queued', 'awaiting_payment'] as $state) {
        Illuminate\Support\Facades\Queue::fake([Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class]);
        $op = $new(reservedFixtureName('rate-'.str_replace('_', '-', $state).'.example.com')); $op->update(['state' => $state]);
        $entry = $clock->now(); $writer->blockedUntil = $entry->modify('+60 seconds');
        $before = $writer->calls; $reads = $preflight->calls; $money = $nativeMoney();
        $original = $op->getRawOriginal();
        $runtime = new Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch($clock);
        $runtime->enqueue($op->id);
        same(1, Illuminate\Support\Facades\Queue::pushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class)->count(), 'Durable rate retry waits for its 60-second interval');
        $engine->execute($op->id); $result = $op->fresh();
        same($state, $result->state, 'Preclaim rate refusal retains resumable state');
        foreach (['safe_code', 'started_at', 'lease_expires_at', 'request_fingerprint', 'attempt', 'funding'] as $field) {
            same($original[$field], $result->getRawOriginal($field), 'Preclaim rate refusal preserves '.$field);
        }
        same($reads, $preflight->calls, 'Rate refusal occurs before preflight');
        same($before, $writer->calls, 'Rate refusal sends nothing');
        same($money, $nativeMoney(), 'Rate refusal leaves native funding intact');
        (new Illuminate\Bus\UniqueLock(Illuminate\Support\Facades\Cache::store()))->release(new Paymenter\Extensions\Servers\Domains\Jobs\RunOperation($op->id));
        $clock->instant = $entry->modify('+59 seconds'); $runtime->enqueue($op->id);
        same(1, Illuminate\Support\Facades\Queue::pushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class)->count(), 'Durable rate retry waits for its 60-second interval');
        $clock->instant = $entry->modify('+60 seconds');
        (new Illuminate\Bus\UniqueLock(Illuminate\Support\Facades\Cache::store()))->release(new Paymenter\Extensions\Servers\Domains\Jobs\RunOperation($op->id));
        $runtime->enqueue($op->id);
        same(2, Illuminate\Support\Facades\Queue::pushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class)->count(), 'Durable rate retry queues when the interval expires');
        same('uncertain', $engine->execute($op->id)->state, 'Expired rate slot allows ordinary dispatch');
        expect($op->fresh()->started_at !== null, 'Retry claims after the rate interval');
        $engine->execute($op->id);
        same($before + 1, $writer->calls, 'Rate retry and replay send exactly once');
        $clock->instant = $entry; $writer->blockedUntil = null;
    }
    $op = $new(reservedFixtureName('write-disabled-release.example.com')); $writer->disabled = true; $before = $writer->calls;
    $held = $engine->execute($op->id);
    same('held', $held->state, 'Disabled writes still require a hold');
    same('write_disabled', $held->safe_code, 'Disabled write hold retains its reason');
    same(null, $held->started_at, 'Disabled write remains unclaimed');
    same($before, $writer->calls, 'Disabled write sends nothing');
    $writer->disabled = false;
    DB::transaction(function () use ($op, $clock): void {
        $locks = Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($op->domain);
        Paymenter\Extensions\Servers\Domains\Services\StaffOperationWrites::release($locks['operations']->firstWhere('id', $op->id), $locks, $clock->now());
    });
    same('queued', $op->fresh()->state, 'Staff release restores an ordinary disabled-write hold');
    same(null, $op->fresh()->safe_code, 'Staff release clears the disabled-write hold reason');
} finally {
    $writer->disabled = false; $writer->blockedUntil = null;
    $clock->instant = $now;
    Illuminate\Support\Facades\Queue::swap($queueRoot);
}
