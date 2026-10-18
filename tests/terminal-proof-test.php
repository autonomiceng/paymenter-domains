<?php
// Enom 444f31c reservation/provider-disposition safety; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Services\{DomainReservations, OperationTerminalProof};
$op = $new('safe-terminal.com'); [$claimed] = $claims->claim($op->id); $held = $claims->finish($claimed);
same('held', $held->state, 'Completed unsealed worker held');
$result = OperationTerminalProof::encode($claims, $op->id); same('rejected', $result->state, 'Normalized definitely-unsent terminal proof');
DomainReservations::releaseTerminal($result->domain->domain); same(null, $result->domain->fresh()->reservation_name, 'Reservation service releases proven unsent');
foreach (['sealed', 'expired'] as $mode) {
    $op = $new('unsafe-terminal-'.$mode.'.com');
    if ($mode === 'sealed') { $op = $engine->execute($op->id); }
    else { [$claimed] = $claims->claim($op->id); $clock->instant = $clock->now()->modify('+361 seconds'); $op = $claims->finish($claimed); }
    commerceReject(fn () => OperationTerminalProof::encode($claims, $op->id));
    commerceReject(fn () => DomainReservations::releaseTerminal($op->domain->domain));
    same($op->domain->domain, $op->domain->reservation_name, 'Uncertain/expired facts cannot release');
    $clock->instant = $now;
}
echo "Terminal proof encoder releases only completed definitely-unsent attempts\n"; $nativeSuccessful = true;
