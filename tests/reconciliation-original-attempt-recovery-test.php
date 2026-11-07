<?php
// Enom 444f31c reconciliation-registration/provider-disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/reconciliation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, Disposition, DriverResult, Evidence, ExpiryPrecision, HistoryAcceptance, RegistrationEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances, ReconciliationEvidence, RenewalAuthority};
foreach (['late', 'absent', 'null'] as $mode) {
    [$op, $binding] = $prepare(reservedFixtureName('recover-'.$mode.'.example.com'), $mode);
    $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    if ($mode !== 'late') { HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1')); }
    $result = $reconcile->run($op->id); same('succeeded', $result->state, 'Late facts/read-only provider history recover original unique attempt');
    if ($mode === 'absent') { expect(!array_key_exists('execution', $result->attempt), 'Recovery never fabricates missing execution'); }
    if ($mode === 'null') { same(null, $result->attempt['execution'], 'Recorded-null distinction preserved'); }
    if ($mode === 'null') {
        $afterRecovery = [$observation->calls, $preflight->calls, $writer->calls]; $money = $nativeMoney();
        $retained = [$result->operation_key, $result->getRawOriginal(), $result->domain->getRawOriginal()];
        $engine->execute($result->id);
        same($afterRecovery, [$observation->calls, $preflight->calls, $writer->calls], 'M10 execute after lost-result recovery adds no reads or writes');
        same([$money, $retained], [$nativeMoney(), [$result->fresh()->operation_key, $result->fresh()->getRawOriginal(), $result->domain->fresh()->getRawOriginal()]],
            'M10 execute retains the recovered key, charge, expiry, anchor and native finance');
        $reconcile->run($result->id);
        same($afterRecovery, [$observation->calls, $preflight->calls, $writer->calls], 'M10 reconcile after lost-result recovery adds no reads or writes');
        same([$money, $retained], [$nativeMoney(), [$result->fresh()->operation_key, $result->fresh()->getRawOriginal(), $result->domain->fresh()->getRawOriginal()]],
            'M10 reconcile retains the recovered key, charge, expiry, anchor and native finance');
    }
}
$nativeSuccessful=true;echo "Native reconciliation scenario passed.\n";
