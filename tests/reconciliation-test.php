<?php
// Enom 444f31c reconciliation-registration/provider-disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding = 'example.com';
require __DIR__.'/Support/reconciliation-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, Disposition, DriverResult, Evidence, ExpiryPrecision, HistoryAcceptance, RegistrationEvidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances, ReconciliationEvidence};
// A sealed unknown attempt is otherwise eligible; current native funding must refuse before any read.
foreach (['pending', 'paid'] as $invoiceStatus) {
    [$op, $binding] = $prepare(reservedFixtureName('initially-unfunded-'.$invoiceStatus.'.example.com'), 'null');
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1'));
    $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    $attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
    expect($attempt->historyAcceptance?->covers($attempt, $clock->now()), 'M9 '.$invoiceStatus.' history gate permits observation');
    Illuminate\Support\Facades\DB::table('invoice_transactions')->where('invoice_id', $op->invoice_id)->delete();
    Illuminate\Support\Facades\DB::table('invoices')->where('id', $op->invoice_id)->update(['status' => $invoiceStatus]);
    $money = $nativeMoney(); $writes = $writer->calls;
    $result = $reconcile->run($op->id);
    same([[], $writes], [$observation->calls, $writer->calls], 'M9 '.$invoiceStatus.' reconciliation makes zero provider calls');
    same(['uncertain', 'reconciliation_required', 'registration_pending', $op->domain->domain, null, null, null, null],
        [$result->state, $result->safe_code, $result->domain->status, $result->domain->reservation_name,
            $result->domain->provider_expires_at, $result->confirmed_expires_at, $result->cost['actual'] ?? null,
            $result->domain->renewal_policy['billing_anchor'] ?? null], 'M9 '.$invoiceStatus.' remains unresolved and reserved');
    same($money, $nativeMoney(), 'M9 '.$invoiceStatus.' preserves invoices, items, transactions, credits and jobs');
}
[$op] = $prepare(reservedFixtureName('unique-completion.example.com'));
same('registration_correction_required', $op->safe_code, 'Correction selector retained');
$result = $reconcile->run($op->id);
same('succeeded', $result->state, 'Unique original ID and actual charge complete');
same('8.95', $result->cost['actual']['amount'], 'Actual charge differs from authorized cost without floor');
same('active', $result->domain->status, 'Owned only on financial proof');
same('2027-10-18', $result->domain->provider_expires_at->format('Y-m-d'), 'Dedicated expiry and order corroborate despite purchase discrepancy');
same('2027-10-18', $result->domain->renewal_policy['billing_anchor']['expiry'], 'Financial anchor uses proven expiry');
same(null, App\Models\Service::find($op->service_id)->expires_at, 'Native annual expiry remains disabled');
same('paid', App\Models\Invoice::find($op->invoice_id)->status, 'Invoice untouched');
same(true, $result->policy['reconciliation']['staff_alert'], 'Corrections/changed cost alert staff');
same('authorization_required', $result->attempt['corrections']['nameservers']['state'], 'No implicit management write');
$before = $observation->calls; $reconcile->run($op->id); same($before, $observation->calls, 'Completed operation repeats without provider calls');
commerceReject(fn () => $result->update(['cost' => [...$result->cost, 'actual' => ['amount' => '0.00', 'currency' => 'USD']]]));
foreach (['zero', 'multiple', 'term'] as $fault) {
    [$op] = $prepare(reservedFixtureName('unresolved-'.$fault.'.example.com')); $observation->fault = $fault;
    $result = $reconcile->run($op->id); same('pending', $result->state, 'Missing/ambiguous/wrong-term evidence stays pending');
    same(null, $result->cost['actual'] ?? null, 'No manufactured actual charge');
    same(null, $result->domain->provider_expires_at, 'No anchor from provider observation alone');
    same(true, $result->policy['reconciliation']['staff_alert'], 'Durable unresolved alert');
}
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
echo "Original-attempt unique completion, correction selection and crash recovery passed\n"; $nativeSuccessful = true;
