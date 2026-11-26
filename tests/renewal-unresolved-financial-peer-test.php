<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HoldingLocks, RenewalFunding};

// Every financial action retains both its new-debt and existing-funded-debt refusal.
foreach (['register', 'renew', 'transfer', 'privacy_purchase', 'privacy_renew'] as $action) {
    [$op, $d, $b] = $makeRenewal(placeholderComFixtureName('renew-blocked-'.str_replace('_', '-', $action).'-placeholder.com'));
    $peer = Operation::create([
        'domain_id' => $d->id, 'service_id' => $d->service_id, 'server_id' => $d->server_id,
        'registrar_key' => $d->registrar_key, 'account_fingerprint' => $d->account_fingerprint,
        'authority_generation' => $d->authority_generation, 'action' => $action, 'term' => 1,
        'operation_key' => 'unresolved-financial:'.$d->id, 'funding_basis' => 'paid_invoice',
        'state' => 'uncertain', 'amount' => '8.95', 'currency' => 'USD', 'payload' => [], 'policy' => [],
        'attempt' => $action === 'renew' ? [] : ['seal' => 'sealed', 'send_state' => 'possibly_sent'],
    ]);
    if ($action === 'renew') { sealRenewal($peer); }
    DB::transaction(static function () use ($op, $d, $bindings): void {
        $l = HoldingLocks::take($d);
        commerceReject(fn () => RenewalFunding::requireCurrent($op, $l));
        // Prospective billing sees the anchor and unresolved financial peer, before the new debt exists.
        $l['operations'] = $l['operations']->reject(fn ($row) => $row->id === $op->id)->values();
        commerceReject(fn () => DomainBilling::requireAuthority($l, $bindings, manual: true));
    });
    [$engine, $writer] = $compose($b); $writes = $state->writes;
    $engine->run($op->id, $writer);
    same($writes, $state->writes, 'Unresolved '.$action.' prevents renewal dispatch');
    same('uncertain', $peer->fresh()->state, 'Financial peer remains unresolved');
}
echo "Unresolved registration, renewal, transfer and paid privacy still block renewal\n";
$nativeSuccessful = true;
