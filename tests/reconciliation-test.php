<?php
// Enom 444f31c reconciliation-registration/provider-disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances;
[$op] = $prepare('unique-completion.com');
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
    [$op] = $prepare('unresolved-'.$fault.'.com'); $observation->fault = $fault;
    $result = $reconcile->run($op->id); same('pending', $result->state, 'Missing/ambiguous/wrong-term evidence stays pending');
    same(null, $result->cost['actual'] ?? null, 'No manufactured actual charge');
    same(null, $result->domain->provider_expires_at, 'No anchor from provider observation alone');
    same(true, $result->policy['reconciliation']['staff_alert'], 'Durable unresolved alert');
}
foreach (['late', 'absent', 'null'] as $mode) {
    [$op, $binding] = $prepare('recover-'.$mode.'.com', $mode);
    $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    if ($mode !== 'late') { HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1')); }
    $result = $reconcile->run($op->id); same('succeeded', $result->state, 'Late facts/read-only provider history recover original unique attempt');
    if ($mode === 'absent') { expect(!array_key_exists('execution', $result->attempt), 'Recovery never fabricates missing execution'); }
    if ($mode === 'null') { same(null, $result->attempt['execution'], 'Recorded-null distinction preserved'); }
}
[$op] = $prepare('timestamp-boundary.com');
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
