<?php
// Synthetic post-seal guard regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/write-fixture.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, WriteAcceptance};
$ops = [];
foreach (['funding', 'owner', 'generation', 'reservation', 'pause', 'peer', 'acceptance', 'lease', 'context', 'budget', 'reference', 'charge', 'candidates', 'refund', 'cost', 'policy'] as $change) { $ops[$change] = $new('boundary-'.$change.'.com'); }
$rate = $new('rate-held.com'); $preseal = [];
foreach (['charge', 'order_candidates', 'refund'] as $key) { $preseal[$key] = $new('preseal-'.str_replace('_', '-', $key).'.com'); }
foreach ($ops as $change => $op) {
    $build($enomBinding);
    $proxy->before = static function ($admitted, $context) use ($change, $op, $bindings, $enomBinding, $clock) {
        $domain = $op->domain;
        match ($change) {
            'funding' => InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => '-0.01', 'fee' => '0.00', 'status' => 'succeeded']),
            'owner' => DB::table('services')->where('id', $op->service_id)->update(['user_id' => 99999]),
            'generation' => DB::table('domains_domains')->where('id', $domain->id)->update(['authority_generation' => 2]),
            'reservation' => DB::table('domains_domains')->where('id', $domain->id)->update(['reservation_name' => null]),
            'pause' => $bindings->setProcessing($op->server_id, false),
            'peer' => DB::table('domains_operations')->insert(['operation_key' => 'peer-'.$op->id, 'domain_id' => $domain->id, 'service_id' => $op->service_id,
                'server_id' => $op->server_id, 'registrar_key' => 'enom', 'account_fingerprint' => $op->account_fingerprint, 'authority_generation' => 1, 'action' => 'contacts', 'state' => 'uncertain']),
            'acceptance' => CatalogueAcceptances::save($enomBinding, 1, []),
            'lease' => $clock->instant = $clock->now()->modify('+26 seconds'),
            'reference', 'charge', 'candidates' => (static function () use ($change, $op) {
                $current = $op->fresh(); $key = ['reference' => 'provider_reference', 'charge' => 'charge', 'candidates' => 'order_candidates'][$change];
                $current->update(['attempt' => [...$current->attempt, $key => null]]);
            })(),
            'refund' => (static function () use ($op) { $current = $op->fresh(); $current->update(['funding' => [...$current->funding, 'refund' => null]]); })(),
            'cost' => (static function () use ($op) { $current = $op->fresh(); $current->update(['cost' => [...$current->cost, 'actual' => null]]); })(),
            'policy' => (static function () use ($op) { $current = $op->fresh(); $current->update(['policy' => [...$current->policy, 'dispatch_attempted_at' => null]]); })(),
            'context' => null, // exact identity refusal below
            'budget' => (static function () use ($context) { while ($context->budget->remaining()) { $context->claimRead($context->binding); } })(),
        };
    };
    if ($change === 'context') { $proxy->before = static function ($admitted, $context) use (&$engine) {
        commerceReject(fn () => $engine->consume($admitted, clone $context, new \Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($admitted->binding)));
        throw new RuntimeException('Refuse wrong scope');
    }; }
    $before = count($transport->calls); $result = $engine->execute($op->id);
    same($before, count($transport->calls), 'Post-seal '.$change.' refuses transport'); same('uncertain', $result->state, 'Sealed refusal preserves uncertainty');
    expect(array_key_exists('execution', $result->attempt), 'Sealed refusal records execution null');
    if ($change === 'pause') { $bindings->setProcessing($op->server_id, true); }
    if ($change === 'acceptance') { CatalogueAcceptances::save($enomBinding, 2, ['com' => ['register' => [1, 3], 'renew' => [1], 'transfer' => [1], 'registry' => true, 'renewal_safe' => true]]); }
    if ($change === 'lease') { $clock->instant = $clock->now()->modify('-26 seconds'); }
}
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
