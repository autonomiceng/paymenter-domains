<?php
// Enom 444f31c reconciliation-registration/provider-disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/reconciliation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, Disposition, DriverResult, Evidence, ExpiryPrecision, HistoryAcceptance, RegistrationEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances, ReconciliationEvidence, RenewalAuthority};
// The captured ID identifies the order even when its local clock has no accepted zone.
[$op, $binding] = $prepare(reservedFixtureName('local-clock.example.com'));
$observation->billDate = '10/18/2026 6:02:00 AM'; // UTC-6 fits; LA interprets this one hour late.
$result = $reconcile->run($op->id);
same('succeeded', $result->state, 'Captured order local clock completes independently of an assumed provider zone');
$proof = $result->attempt['reconciliation'];
same(['2026-10-18T06:02:00', 'local', 'enom_order_bill_date', null],
    [$proof['processed_at'], $proof['processed_precision'], $proof['processed_source'], $proof['processed_timezone']], 'Observed wall clock retains unknown zone');
same(['8.95', '2027-10-18'], [$result->cost['actual']['amount'], $proof['expiry']], 'Exact charge and full-term expiry still required');
expect(!in_array(ReadCommand::Orders, $observation->calls, true), 'Captured ID bypasses history discovery');
expect(RenewalAuthority::completed($result, $result->domain, $binding), 'Local-clock registration remains a valid future renewal anchor');
foreach (['processed_at' => '2026-10-19T02:05:01', 'order_reference' => '9002', 'source' => 'accepted_history'] as $key => $value) {
    $tampered = clone $result; $tampered->attempt = [...$result->attempt, 'reconciliation' => [...$proof, $key => $value]];
    expect(!RenewalAuthority::completed($tampered, $result->domain, $binding), 'Retained anchor rechecks local bounds and captured identity: '.$key);
}
// Bounds are asymmetric: local = UTC + an offset from -12 through +14 hours.
foreach (['lower' => '10/18/2026 12:00:00 AM', 'upper' => '10/19/2026 2:05:00 AM'] as $edge => $billDate) {
    [$op] = $prepare(reservedFixtureName('clock-'.$edge.'.example.com')); $observation->billDate = $billDate;
    same('succeeded', $reconcile->run($op->id)->state, 'Inclusive offset boundary completes: '.$edge);
}
foreach (['too-late', 'too-early', 'wrong-id', 'history'] as $fault) {
    [$op, $binding] = $prepare(reservedFixtureName('clock-'.$fault.'.example.com'), $fault === 'history' ? 'null' : 'accepted');
    $observation->billDate = match ($fault) {
        'too-late' => '10/19/2026 2:05:01 AM', 'too-early' => '10/17/2026 11:59:59 PM', default => '10/18/2026 6:02:00 AM',
    };
    if ($fault === 'wrong-id') { $observation->reference = '9002'; }
    if ($fault === 'history') {
        HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1'));
        $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    }
    $money = $nativeMoney(); $writes = $writer->calls; $result = $reconcile->run($op->id);
    expect($result->state !== 'succeeded', 'Unproven clock/identity refuses completion: '.$fault);
    same([null, null, null], [$result->cost['actual'] ?? null, $result->confirmed_expires_at,
        $result->domain->renewal_policy['billing_anchor'] ?? null], 'Refusal creates no financial anchor: '.$fault);
    same([$money, $writes], [$nativeMoney(), $writer->calls], 'Clock reconciliation preserves finance and never writes: '.$fault);
    if ($fault === 'history') { expect(in_array(ReadCommand::Orders, $observation->calls, true), 'Skewed negative really used strict history'); }
}
echo "Captured-order clock bounds and retained anchors passed\n"; $nativeSuccessful = true;
