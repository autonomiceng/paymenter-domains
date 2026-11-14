<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HoldingLocks, ManagementEvidence, RenewalFunding};

// Retained sealed requests whose original reply was lost and whose desired record never read back.
$unknown = static function ($d, string $action, string $basis = 'non_billable_management'): Operation {
    return Operation::create([
        'domain_id' => $d->id, 'service_id' => $d->service_id, 'server_id' => $d->server_id,
        'registrar_key' => $d->registrar_key, 'account_fingerprint' => $d->account_fingerprint,
        'authority_generation' => $d->authority_generation, 'action' => $action, 'term' => 1,
        'operation_key' => 'unconfirmed-record:'.$d->id, 'funding_basis' => $basis,
        'state' => 'uncertain', 'amount' => $basis === 'non_billable_management' ? '0.00' : '1.00', 'currency' => 'USD',
        'payload' => [], 'policy' => ['management' => ['generation' => $d->authority_generation, 'input' => []]],
        'attempt' => ['seal' => 'sealed', 'send_state' => 'possibly_sent'],
    ]);
};
$failures = [];
foreach (['dns', 'srv', 'ds_add', 'ds_delete', 'glue_add', 'glue_update', 'glue_delete'] as $action) {
    $peer = null;
    try {
        [$op, $d, $b] = $makeRenewal(placeholderComFixtureName('renew-unknown-'.str_replace('_', '-', $action).'-placeholder.com'), beforeBill:
            static function ($d) use ($action, $unknown, &$peer): void {
                $peer = $unknown($d, $action);
                expect(!ManagementEvidence::resolved($peer, $d), 'Record request has no confirmed outcome');
            });
        $before = $peer->fresh()->getRawOriginal();
        DB::transaction(static fn () => RenewalFunding::requireCurrent($op, HoldingLocks::take($d)));
        [$engine, $writer, $completion] = $compose($b); $writes = $state->writes;
        same('pending', $engine->run($op->id, $writer)->state, 'Paid renewal dispatches beside unconfirmed '.$action);
        same($writes + 1, $state->writes, 'Exactly one renewal write');
        $state->expiry = '2028-10-18'; $state->charge = '8.00';
        same('succeeded', $completion->run($op->id)->state, 'Exact renewal charge and expiry complete beside '.$action);
        same($before, $peer->fresh()->getRawOriginal(), 'Record outcome and evidence stay unchanged');
        echo 'PASS renewal beside unconfirmed '.$action."\n";
    } catch (Throwable $error) {
        $failures[] = $action.': '.$error->getMessage();
        echo 'FAIL renewal beside unconfirmed '.$action.': '.$error->getMessage()."\n";
    }
    Cache::flush();
}
// The exemption must not accept financial record requests or disabling registrar auto-renewal.
foreach (['paid-record' => ['dns', 'paid_invoice'], 'auto-renew' => ['auto_renew_off', 'non_billable_management']] as $case => [$action, $basis]) {
    [$op, $d, $b] = $makeRenewal(placeholderComFixtureName('renew-blocked-'.$case.'-placeholder.com'));
    $peer = $unknown($d, $action, $basis);
    DB::transaction(static function () use ($op, $d, $bindings): void {
        $l = HoldingLocks::take($d);
        commerceReject(fn () => RenewalFunding::requireCurrent($op, $l));
        $l['operations'] = $l['operations']->reject(fn ($row) => $row->id === $op->id)->values();
        commerceReject(fn () => DomainBilling::requireAuthority($l, $bindings, manual: true));
    });
    [$engine, $writer] = $compose($b); $writes = $state->writes;
    $engine->run($op->id, $writer);
    same($writes, $state->writes, $case.' still prevents renewal dispatch');
}
if ($failures) { throw new RuntimeException(implode("\n", $failures)); }
$nativeSuccessful = true;
