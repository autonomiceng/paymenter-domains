<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-claims.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
// Each preflight fault starts from the one prepared claim fixture and its own paid native checkout.
// Capability and premium refusals, then short-lived and stale preflight answers; the reset restores the fakes.
foreach (['capability', 'premium', 'short', 'old'] as $change) {
    $cases[$change] = static function () use ($change, $new, $preflight, $assertNoSend, $engine): void {
        $op = $new('invalid-'.$change.'.example.com');
        if ($change === 'capability') { $preflight->supported = false; } elseif ($change === 'premium') { $preflight->premium = null; } elseif ($change === 'short') { $preflight->freshSeconds = 24; } else { $preflight->ageSeconds = 1; }
        $assertNoSend($op); $preflight->supported = true; $preflight->premium = false; $preflight->freshSeconds = 120; $preflight->ageSeconds = 0;
        if ($change !== 'old') { return; }
        $job = new ExecuteOperation($op->id); same(1, $job->tries, 'One queue attempt'); same(300, $job->timeout, 'Ten bounded requests and worker margin');
        same($op->id, unserialize(serialize($job))->operationId, 'Readonly job identifier survives queue serialization');
        same(360, ExecuteOperation::LEASE_SECONDS, 'Lease outlives worker by cleanup margin'); expect($job->failOnTimeout, 'Worker timeout fails job');
        commerceReject(fn () => DB::transaction(fn () => $engine->execute($op->id)));
    };
}
nativeFixtureCases($cases, compact('clock', 'preflight', 'writer', 'engine', 'claims', 'user'));
$nativeSuccessful=true;echo "Native operation claim scenario passed.\n";
