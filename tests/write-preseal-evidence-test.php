<?php
// Synthetic post-seal guard regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/write-fixture.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Services\{WriteAcceptance};
$rate=$new(placeholderComFixtureName('rate-held-placeholder.com'));$preseal=[];
foreach(['charge','order_candidates','refund'] as $key){$preseal[$key]=$new(placeholderComFixtureName('preseal-'.str_replace('_','-',$key).'-placeholder.com'));}
foreach ($preseal as $key => $op) {
    $build($enomBinding); $preflight->during = static function ($claimed) use ($key) {
        $current = $claimed->fresh(); $field = $key === 'refund' ? 'funding' : 'attempt';
        $current->update([$field => [...$current->$field, $key => null]]);
    };
    $result = $engine->execute($op->id); same('held', $result->state, 'Evidence added during reads refuses seal');
    same('absent', $result->attempt['seal'], 'Presence of null evidence blocks seal'); same([], $transport->calls, 'No preseal evidence send');
}
$preflight->during = null;
$bridge = $build($enomBinding); Cache::put('domains-write-slot:'.$enomBinding->fingerprint, true, 60);
$result = $engine->execute($rate->id); same($rate->state, $result->state, 'Rate refusal preserves retryable unclaimed state'); same(null, $result->safe_code, 'Write contention is not a permanent hold');
same(null, $result->attempt, 'Rate refusal burns no claim or seal'); same([], $transport->calls, 'Rate budget has zero wire calls');
echo "Sealed bridge current authority checks passed\n"; $nativeSuccessful = true;
