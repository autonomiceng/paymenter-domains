<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adopted-renewal.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingAnchor, HoldingLocks};
extract(adoptedRenewalFixture());
$original = $domain->getRawOriginal('snapshot'); $adoptionBytes = $adoption->getRawOriginal('evidence');
same(0, $domain->operations()->count(), 'Adoption has no fabricated acquisition');
same(null, $domain->snapshot['checkout'] ?? null, 'Adoption has no checkout');
$domain->update(['observation' => ['ordinary' => true], 'last_synced_at' => $clock->now()]);
same(1, $domain->fresh()->confirmed_revision, 'Observation metadata does not advance confirmed authority');
same('adoption', DB::transaction(fn () => HoldingAnchor::resolve(HoldingLocks::take($domain)))->kind, 'Initial union branch');
[$engine, $writer, $completion] = $compose($binding);
foreach ([2027, 2028] as $year) {
    $clock->time = new DateTimeImmutable($year.'-09-29T12:00:00Z');
    $invoice = $billing->invoice($domain->id, $owner); $op = Operation::where('invoice_id', $invoice->id)->sole();
    same('renew', $op->action, 'Only renewal obligation issued');
    same(null, $invoice->items()->sole()->reference_id, 'Renewal native line has no acquisition reference');
    same(1 + $year - 2027, $op->policy['renewal']['confirmed_revision'], 'Debt freezes verified chain revision');
    \App\Helpers\ExtensionHelper::addPayment($invoice, null, $op->amount);
    $state->expiry = '10/20/'.$year.' 5:00:00 AM'; $state->day = '10/20/'.$year; $state->after = ($year + 1).'-10-20';
    $state->orderYear = (string) $year; $state->order = (string) (9002 + $year - 2027); $state->charge = '8.00';
    $clock->time = new DateTimeImmutable($year.'-10-20T11:59:00Z'); Cache::flush();
    same('pending', $engine->run($op->id, $writer)->state, 'Funded adopted renewal executes once');
    $state->expiry = $state->after;
    same('succeeded', $completion->run($op->id)->state, 'Original charge and full added year complete renewal');
    $domain->refresh();
    same($state->after, $domain->provider_expires_at->format('Y-m-d'), 'Confirmed expiry advances');
    same(2 + $year - 2027, $domain->confirmed_revision, 'Verified renewal advances one revision');
    same('completed_operation', DB::transaction(fn () => HoldingAnchor::resolve(HoldingLocks::take($domain)))->kind, 'Completed union branch');
    same($original, $domain->getRawOriginal('snapshot'), 'Original adoption snapshot bytes retained');
    same($adoptionBytes, $adoption->fresh()->getRawOriginal('evidence'), 'Original adoption evidence bytes retained');
    same($adoption->id, $domain->adoption_id, 'Original adoption pointer retained');
    same(null, $domain->service->fresh()->expires_at, 'Native expiry stays null');
    $sealed = $op->fresh()->getRawOriginal('policy'); $changed = $op->fresh()->policy; $changed['renewal']['confirmed_revision'] = 99;
    DB::table('domains_operations')->where('id', $op->id)->update(['policy' => encrypt(json_encode($changed), false)]);
    try { holdingRefused(fn () => DB::transaction(fn () => HoldingAnchor::resolve(HoldingLocks::take($domain))), 'Completed state cannot bypass prior revision chain'); }
    finally { DB::table('domains_operations')->where('id', $op->id)->update(['policy' => $sealed]); }
}
same(2, $state->writes, 'Exactly two synthetic renewal writes');
same(0, $domain->operations()->whereIn('action', ['register', 'transfer'])->count(), 'No acquisition operation synthesized');
echo "Live-bound adopted renewal invoices, two completed cycles and original provenance retention passed.\n";
$nativeSuccessful = true;
