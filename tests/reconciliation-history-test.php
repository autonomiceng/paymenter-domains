<?php
// Enom 444f31c reconciliation-registration/provider-disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/reconciliation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, Disposition, DriverResult, Evidence, ExpiryPrecision, HistoryAcceptance, RegistrationEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances, ReconciliationEvidence, RenewalAuthority};
// Captured acceptance can retain execution facts without an order reference; history must still run.
[$op, $binding] = $prepare(reservedFixtureName('history.example.com'), 'absent');
$op = $claims->finish($op, new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
    new Evidence(registration: new RegistrationEvidence(\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op, $op->domain),
        null, new DateEvidence('2027-10-18', ExpiryPrecision::Date, 'synthetic_purchase'), null, []))));
HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1'));
$clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
$attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
expect($attempt->executionFacts !== null, 'Null-reference acceptance retains execution facts');
same(null, $attempt->providerReference, 'Null-reference acceptance has no captured order ID');
same(null, $op->attempt['evidence']['registration']['orderReference'], 'Registration evidence retained the null reference');
expect($attempt->historyAcceptance?->covers($attempt, $clock->now()), 'Saved history acceptance covers null-reference execution');
$money = $nativeMoney(); $writes = $writer->calls;
$result = $reconcile->run($op->id);
same('succeeded', $result->state, 'History completes accepted execution without a captured order reference');
expect(in_array(ReadCommand::Orders, $observation->calls, true), 'Null-reference execution reads Enom history');
same('accepted_history', $result->attempt['reconciliation']['source'], 'Completion uses accepted history rather than captured order');
same('9001', $result->provider_order_id, 'Unique history supplies the missing order reference');
same($writes, $writer->calls, 'Null-reference history recovery never replays a write');
same($money, $nativeMoney(), 'Null-reference history recovery preserves native finance');
// Null-reference acceptance never substitutes for a covering, unique history proof.
foreach (['no-acceptance', 'window', 'zero', 'multiple'] as $fault) {
    [$op, $binding] = $prepare(reservedFixtureName('null-history-'.$fault.'.example.com'), 'absent');
    $op = $claims->finish($op, new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
        new Evidence(registration: new RegistrationEvidence(\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op, $op->domain),
            null, new DateEvidence('2027-10-18', ExpiryPrecision::Date, 'synthetic_purchase'), null, []))));
    App\Models\Server::findOrFail($binding->serverId)->settings()->where('key', HistoryAcceptances::KEY)->delete();
    if ($fault !== 'no-acceptance') {
        HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', $fault === 'window' ? 600 : 0, 86400, 'enom_registration_v1'));
    }
    $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    $attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
    expect($attempt->executionFacts !== null && $attempt->providerReference === null, 'Negative retains accepted null-reference execution: '.$fault);
    same(!in_array($fault, ['no-acceptance', 'window'], true), $attempt->historyAcceptance?->covers($attempt, $clock->now()) ?? false,
        'Negative history acceptance coverage: '.$fault);
    $money = $nativeMoney(); $calls = [$preflight->calls, $writer->calls];
    if (in_array($fault, ['zero', 'multiple'], true)) {
        $observation->fault = 'history-'.$fault;
        $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
            \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
            new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock);
        $facts = $readBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
        same([true, $fault === 'zero' ? 0 : 2], [$facts->historyComplete, count($facts->candidates)], 'Negative has complete empty or competing history: '.$fault);
        $observation->calls = [];
    }
    $result = $reconcile->run($op->id); $engine->execute($op->id); $result = $result->fresh();
    same('pending', $result->state, 'Null-reference history refuses completion: '.$fault);
    same([null, null, null, null, $op->domain->domain], [$result->cost['actual'] ?? null, $result->confirmed_expires_at,
        $result->domain->provider_expires_at, $result->domain->renewal_policy['billing_anchor'] ?? null,
        $result->domain->reservation_name], 'Refused history retains reservation without charge or expiry anchor: '.$fault);
    same($calls, [$preflight->calls, $writer->calls], 'Refused history and execution never replay a write: '.$fault);
    same($money, $nativeMoney(), 'Refused history leaves native money unchanged: '.$fault);
    same(in_array($fault, ['zero', 'multiple'], true), in_array(ReadCommand::Orders, $observation->calls, true), 'Only accepted covering history reaches discovery: '.$fault);
    if (in_array($fault, ['no-acceptance', 'window'], true)) { same([], $observation->calls, 'Unaccepted history makes no provider reads: '.$fault); }
}
[$op] = $prepare(reservedFixtureName('timestamp-boundary.example.com'));
$beforeUpdate = Illuminate\Support\Carbon::parse($op->getRawOriginal('updated_at'));
// Eloquent samples time for the model, then again for the UPDATE when the first
// timestamp equals the original and is omitted from the dirty attributes.
$nativeDates = new class($beforeUpdate) extends Carbon\Factory {
    public int $reads = 0;
    public function __construct(private Illuminate\Support\Carbon $start) { parent::__construct(); }
    public function now($timezone = null): Illuminate\Support\Carbon
    {
        return $this->start->copy()->addSeconds($this->reads++ === 0 ? 0 : 1);
    }
};
$observation->during = static function () use ($op, $beforeUpdate, $nativeDates): void {
    same(2, $nativeDates->reads, 'Model and query builder sampled opposite sides of a second boundary');
    same($beforeUpdate->copy()->addSecond()->format('Y-m-d H:i:s'), $op->fresh()->getRawOriginal('updated_at'),
        'Unresolved alert persisted the later query-builder timestamp');
};
Illuminate\Support\Facades\Date::useFactory($nativeDates);
try {
    $result = $reconcile->run($op->id);
    same('succeeded', $result->state, 'Real original charge and expiry anchor survive timestamp boundary');
    same('8.95', $result->cost['actual']['amount'], 'Boundary completion retains proven charge');
    same('2027-10-18', $result->domain->renewal_policy['billing_anchor']['expiry'], 'Boundary completion retains proven anchor');
} finally { Illuminate\Support\Facades\Date::useDefault(); }
echo "Null-reference history and timestamp-boundary completion passed\n"; $nativeSuccessful = true;
