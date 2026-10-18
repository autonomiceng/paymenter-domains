<?php
// Enom 444f31c uncertain disposition/automatic-refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use Illuminate\Support\Facades\DB;
foreach (['generation', 'cancelled', 'reversal', 'changed', 'paused'] as $race) {
    [$op, $binding] = $prepare('authority-'.$race.'.com');
    $observation->during = static function () use ($op, $race, $readBindings, $binding): void {
        if ($race === 'generation') { DB::table('domains_domains')->where('id', $op->domain_id)->update(['authority_generation' => 2]); }
        if ($race === 'cancelled') { DB::table('services')->where('id', $op->service_id)->update(['status' => 'cancelled']); }
        if ($race === 'reversal') { App\Models\InvoiceTransaction::create(['invoice_id' => $op->invoice_id, 'amount' => '-1000.00', 'fee' => '0.00', 'status' => 'succeeded']); }
        if ($race === 'changed') { $op->fresh()->update(['safe_code' => 'staff_review']); }
        if ($race === 'paused') { $readBindings->setProcessing($binding->serverId, false); }
    };
    $result = $reconcile->run($op->id);
    same($race === 'paused' ? 'succeeded' : 'pending', $result->state, 'Fresh authority blocks stale reads; spending pause allows observation');
    if ($race !== 'paused') { same(null, $result->domain->provider_expires_at, 'No resurrection or anchoring after authority change'); }
    if ($race === 'paused') { $readBindings->setProcessing($binding->serverId, true); }
}
[$op] = $prepare('bounded-poll.com'); $observation->fault = 'term';
for ($i = 0; $i < 7; $i++) { $reconcile->run($op->id); }
same(5, $op->fresh()->policy['reconciliation']['polls'], 'Unresolved cost poll capped at five');
same(35, count($observation->calls), 'Bounded covered polls; never a registrar write');
same('poll_limit_reached', $op->fresh()->policy['reconciliation']['reason'], 'Distinct durable limit alert');
$reconcile->resume($op->id, 'provider_facts_updated'); $observation->fault = '';
same('succeeded', $reconcile->run($op->id)->state, 'Audited resume permits later decisive observation without replay');
commerceReject(fn () => $op->fresh()->update(['policy' => [...$op->fresh()->policy, 'reconciliation_resumptions' => []]]));
foreach (['info_missing', 'expiry_missing'] as $missing) {
    [$op] = $prepare('retry-'.str_replace('_', '-', $missing).'.com'); $observation->fault = $missing;
    for ($i = 0; $i < 6; $i++) { $reconcile->run($op->id); }
    same(0, $op->fresh()->policy['reconciliation']['polls'], 'Failed required ownership/expiry reads never consume covered polls');
    $observation->fault = ''; same('succeeded', $reconcile->run($op->id)->state, 'Recovered required read completes without manual resume');
}
[$op] = $prepare('active-lease.com', 'absent');
$reconcile->run($op->id); same([], $observation->calls, 'Live sealed worker cannot race reconciliation');
[$op, $binding] = $prepare('before-dispatch.com', 'preseal');
$clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
\Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances::save(new \Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1'));
same('uncertain', $reconcile->run($op->id)->state, 'Charge before sealed dispatch cannot belong to original attempt');
[$op] = $prepare('atomic-completion.com'); $failOnce = true;
App\Models\Service::updated(static function ($service) use ($op, &$failOnce): void {
    if ($failOnce && $service->id === $op->service_id && $service->status === 'active') { $failOnce = false; throw new RuntimeException('Synthetic completion persistence failure'); }
});
try { $reconcile->run($op->id); expect(false, 'Persistence failure propagated'); } catch (RuntimeException) {}
same('pending', App\Models\Service::find($op->service_id)->status, 'Native activation rolled back with operation/domain decision');
same('pending', $op->fresh()->state, 'Operation remains unresolved after failed atomic completion');
same(null, $op->fresh()->domain->provider_expires_at, 'No partial anchor after persistence failure');
echo "Reconciliation authority, cancellation, payment reversal and bounded alerts passed\n"; $nativeSuccessful = true;
