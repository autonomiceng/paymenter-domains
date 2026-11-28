<?php
// Enom 444f31c reservation/provider-disposition safety; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/operation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionClosureKind, OperationAttempts};
use Paymenter\Extensions\Servers\Domains\Services\{DomainReservations, OperationTerminalProof, Refunds};
// The synthetic catalogue explicitly supports the reserved compound ending example.com.
$op = $new(reservedFixtureName('unsent.example.com')); [$claimed] = $claims->claim($op->id); $held = $claims->finish($claimed);
same('held', $held->state, 'Completed unsealed worker held');
same('pre_send_interrupted', $held->safe_code, 'Completed unsealed worker retains the expected refusal');
$result = OperationTerminalProof::encode($claims, $op->id); same('rejected', $result->state, 'Normalized definitely-unsent terminal proof');
$terminal = \Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationAttempts::evidence((int)$result->id, null, 'terminal');
same('definitely_unsent', $terminal['outcome'] ?? null, 'Terminal proof belongs to the operation ledger');
same($result->operation_key, $terminal['evidence']['operation_key'], 'Terminal proof identifies the retained operation');
same($held->acquisitionAttemptFacts()->token->attemptId, $terminal['evidence']['attempt_id'], 'Terminal proof identifies the exact closed worker');
same(AcquisitionClosureKind::Unsent, OperationAttempts::purchaseProof($result->fresh(), $clock->now())->closure,
    'The typed closure reads back through the released predecessor');
commerceReject(fn()=>DomainReservations::releaseTerminal($result->domain->domain));
expect((new Refunds($clock))->creditRejected($result->id)!==null,'Exact unsent account credit commits through engine');
DomainReservations::releaseTerminal($result->domain->domain); same(null, $result->domain->fresh()->reservation_name, 'Reservation service releases proven unsent only after credit');
foreach (['sealed', 'expired'] as $mode) {
    $op = $new(reservedFixtureName($mode.'.example.com'));
    if ($mode === 'sealed') { $op = $engine->execute($op->id); }
    else { [$claimed] = $claims->claim($op->id); $clock->instant = $clock->now()->modify('+361 seconds'); $op = $claims->finish($claimed); }
    $expected = $mode === 'sealed' ? ['uncertain', 'reconciliation_required', 'sealed'] : ['running', null, 'absent'];
    same($expected, [$op->state, $op->safe_code, $op->attempt['seal']], $mode.' worker is still awaiting the next job');
    commerceReject(fn () => OperationTerminalProof::encode($claims, $op->id));
    commerceReject(fn () => DomainReservations::releaseTerminal($op->domain->domain));
    same($op->domain->domain, $op->domain->fresh()->reservation_name, 'An unresolved or expired worker cannot release the reservation');
    $clock->instant = $now;
}
// The pre-apply drain must resolve a legacy inline claim on the old build. New writers cannot legitimize it.
$legacy = $new(reservedFixtureName('legacy-claimed.example.com'));
Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $legacy->id)->update([
    'state' => 'held', 'safe_code' => 'name_unavailable', 'started_at' => $now,
    'request_fingerprint' => str_repeat('a', 64),
    'attempt' => Illuminate\Support\Facades\Crypt::encryptString(json_encode([
        'claim_key' => str_repeat('b', 32), 'seal' => 'absent', 'send_state' => 'definitely_unsent',
    ], JSON_THROW_ON_ERROR)),
    'funding' => Illuminate\Support\Facades\Crypt::encryptString(json_encode(['claim' => []], JSON_THROW_ON_ERROR)),
]);
commerceReject(fn () => OperationTerminalProof::encode($claims, $legacy->id));
same(null, OperationAttempts::evidence((int) $legacy->id, null, 'terminal'), 'An inline claim cannot acquire a new typed terminal');
same('held', $legacy->fresh()->state, 'A drained-only legacy shape is left intact');

// All inline formats are rejected, even when the old reader could bind a completed closure.
$legacyProofs = [];
foreach ([
    'absent marker' => ['seal' => 'absent', 'send_state' => 'definitely_unsent'],
    'rejected marker' => ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent'],
    'bound inline terminal' => ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => 'rejected',
        'rejection_basis' => 'definitely_unsent', 'terminal' => ['operation_key' => 'legacy-terminal', 'generation' => 1]],
] as $shape => $attempt) {
    $legacy = new \Paymenter\Extensions\Servers\Domains\Models\Operation([
        'action' => 'register', 'registrar_key' => 'enom', 'state' => $shape === 'absent marker' ? 'held' : 'rejected',
        'authority_generation' => 1, 'operation_key' => 'legacy-terminal', 'attempt' => $attempt,
        'funding' => [], 'cost' => [], 'policy' => [],
    ]);
    $legacyProofs[$shape] = OperationAttempts::purchaseProof($legacy, $now)->phase->name;
}

// Remove only the attempt ID from an installed writer's proof. A bound kind alone cannot close another attempt.
$terminalQuery = Illuminate\Support\Facades\DB::table(\Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationSchema::EVIDENCE)
    ->where('operation_id', $result->id)->whereNull('attempt_id')->where('kind', 'terminal');
$savedTerminal = $terminalQuery->value('evidence');
$oldTerminal = OperationAttempts::decodeEvidence($savedTerminal);
unset($oldTerminal['attempt_id']);
$terminalQuery->update(['evidence' => Illuminate\Support\Facades\Crypt::encryptString(json_encode($oldTerminal, JSON_THROW_ON_ERROR))]);
try {
    $legacyProofs['omitted attempt ID'] = OperationAttempts::purchaseProof($result->fresh(), $now)->phase->name;
} finally { $terminalQuery->update(['evidence' => $savedTerminal]); }
same([
    'absent marker' => 'Invalid', 'rejected marker' => 'Invalid',
    'bound inline terminal' => 'Invalid', 'omitted attempt ID' => 'Invalid',
], $legacyProofs, 'Every pre-cutover purchase shape is refused: '.json_encode($legacyProofs, JSON_THROW_ON_ERROR));
echo "Terminal proof retains paid reservations until exact unsent credit commits\n"; $nativeSuccessful = true;
