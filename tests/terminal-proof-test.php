<?php
// Enom 444f31c reservation/provider-disposition safety; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Services\{DomainReservations, OperationTerminalProof, Refunds};
// The operation fixture's commerce catalogue publishes only .com; use explicit example placeholders.
$op = $new('example.com'); [$claimed] = $claims->claim($op->id); $held = $claims->finish($claimed);
same('held', $held->state, 'Completed unsealed worker held');
same('pre_send_interrupted', $held->safe_code, 'Completed unsealed worker retains the expected refusal');
$result = OperationTerminalProof::encode($claims, $op->id); same('rejected', $result->state, 'Normalized definitely-unsent terminal proof');
commerceReject(fn()=>DomainReservations::releaseTerminal($result->domain->domain));
expect((new Refunds($clock))->creditRejected($result->id)!==null,'Exact unsent account credit commits through engine');
DomainReservations::releaseTerminal($result->domain->domain); same(null, $result->domain->fresh()->reservation_name, 'Reservation service releases proven unsent only after credit');
foreach (['sealed', 'expired'] as $mode) {
    $op = $new('example-'.$mode.'.com');
    if ($mode === 'sealed') { $op = $engine->execute($op->id); }
    else { [$claimed] = $claims->claim($op->id); $clock->instant = $clock->now()->modify('+361 seconds'); $op = $claims->finish($claimed); }
    same('uncertain', $op->state, $mode.' worker is uncertain before terminal proof');
    same($mode === 'sealed' ? 'reconciliation_required' : 'stale_claim', $op->safe_code, $mode.' worker retains its expected reason');
    commerceReject(fn () => OperationTerminalProof::encode($claims, $op->id));
    commerceReject(fn () => DomainReservations::releaseTerminal($op->domain->domain));
    same($op->domain->domain, $op->domain->fresh()->reservation_name, 'Uncertain/expired facts cannot release');
    $clock->instant = $now;
}
echo "Terminal proof retains paid reservations until exact unsent credit commits\n"; $nativeSuccessful = true;
