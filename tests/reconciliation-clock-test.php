<?php
// Enom 444f31c reconciliation-registration/provider-disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/acquisition-evidence-fixture.php';
$fixtureEnding = 'example.com';
require __DIR__.'/Support/reconciliation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, Disposition, DriverResult, Evidence, ExpiryPrecision, HistoryAcceptance, RegistrationEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{OperationAttempts, OperationSchema, StepKey};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances, OperationReconciliation, ReconciliationEvidence, RenewalAuthority};
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
    $restore = changeAcquisitionEvidence($result, 'settlement', static function (array $facts) use ($key, $value): array {
        $facts['reconciliation'][$key] = $value;
        return $facts;
    });
    try {
        expect(!RenewalAuthority::completed($result->fresh(), $result->domain, $binding), 'Retained anchor rechecks local bounds and captured identity: '.$key);
    } finally { $restore(); }
}
// Bounds are asymmetric: local = UTC + an offset from -12 through +14 hours.
foreach (['lower' => '10/18/2026 12:00:00 AM', 'upper' => '10/19/2026 2:05:00 AM'] as $edge => $billDate) {
    [$op] = $prepare(reservedFixtureName('clock-'.$edge.'.example.com')); $observation->billDate = $billDate;
    same('succeeded', $reconcile->run($op->id)->state, 'Inclusive offset boundary completes: '.$edge);
}
foreach (['too-late', 'too-early', 'wrong-id', 'history'] as $fault) {
    [$op, $binding] = $prepare(reservedFixtureName('clock-'.$fault.'.example.com'), $fault === 'history' ? 'null' : 'accepted');
    $observation->billDate = match ($fault) {
        'too-late' => '10/19/2026 2:05:01 AM', 'too-early' => '10/17/2026 11:59:59 PM',
        // Enom TEST bills in Mountain time, so 7:02 AM is 13:02 UTC: an hour after the 12:00..12:05 window.
        'history' => '10/18/2026 7:02:00 AM', default => '10/18/2026 6:02:00 AM',
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
// The full proof arrived in time, but settlement lost the race with the deadline while under lock.
[$op] = $prepare(reservedFixtureName('expired-charge-proof.example.com'));
DB::listen(static function ($query) use ($clock): void {
    if (str_starts_with($query->sql, 'insert into') && str_contains($query->sql, OperationSchema::EVIDENCE)
        && in_array('observation', $query->bindings, true)) {
        $clock->instant = $clock->now()->modify('+'.OperationReconciliation::READ_SECONDS.' seconds');
    }
});
$result = $reconcile->run($op->id);
same('pending', $result->state, 'Expired read does not settle the purchase');
$charge = OperationAttempts::sealedEvidence($op->id, new StepKey('register', 1), 'acquisition_charge');
same(['exact', '9001', '8.95'], [$charge['outcome'] ?? null, $charge['evidence']['orders'][0]['reference'] ?? null,
    $charge['evidence']['orders'][0]['amount'] ?? null], 'A timely matching charge survives expiry before settlement');
echo "Captured-order clock bounds and retained anchors passed\n"; $nativeSuccessful = true;
