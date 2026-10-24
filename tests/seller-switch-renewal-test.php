<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/lifecycle-scenarios.php';
use App\Models\Role;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\{Price, SalesPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingAdoptionSchema, HoldingProvenanceSchema};
HoldingProvenanceSchema::install(); HoldingAdoptionSchema::install();
$refreshPrices = static function () use ($clock): void {
    Price::query()->update(['observed_at' => $clock->now(), 'fresh_until' => $clock->now()->modify('+1 day')]);
};
$staff = commerceUser(); $role = Role::create(['name' => 'Cycle funding', 'permissions' => ['admin.domains.manage', 'admin.domains.fund']]); $staff->update(['role_id' => $role->id]);
foreach (['paid', 'cap-over', 'cap-exact'] as $case) {
    $clock->instant = $now; $refreshPrices(); scenarioSwitch($sellers, $clock, $tlds[0]->server_id);
    [$op, $d, $binding, $bill] = $makeRenewal(placeholderComFixtureName('non-selling-'.$case.'-placeholder.com'), $case === 'paid', 1, false,
        static function ($domain) use ($refreshPrices, $sellers, $clock, $tlds): void {
            $refreshPrices(); scenarioRetained($domain, static fn () => scenarioSwitch($sellers, $clock, $tlds[1]->server_id));
        });
    same($tlds[1]->server_id, SalesPolicy::where('tld', 'com')->sole()->server_id, 'Renewal is issued while another registrar sells');
    same($tlds[0]->server_id, $op->server_id, 'Renewal keeps original holder');
    if ($case !== 'paid') {
        $cycles->retireUnpaid($op->id);
        $op = $cycles->complimentary($d->id, $staff, 1, new Money('8.00', 'USD'), 'staff_reviewed');
        same(null, $op->invoice_id, 'Complimentary authority adds no invoice');
        $state->price = $case === 'cap-over' ? '8.01' : '8.00';
    }
    $refreshPrices(); $money = scenarioMoney(); $writes = $state->writes;
    foreach ([0, null, 1] as $seller) {
        scenarioRetained($d, static fn () => scenarioSwitch($sellers, $clock, $seller === null ? null : $tlds[$seller]->server_id));
    }
    [$engine, $writer, $completion] = $compose($binding);
    $result = $engine->run($op->id, $writer); $held = $case === 'cap-over';
    same($held ? 'held' : 'pending', $result->state, 'Non-selling holder respects '.$case.' authority');
    same($writes + ($held ? 0 : 1), $state->writes, 'Exact cap boundary controls one renewal send');
    same($money, scenarioMoney(), 'Switches and renewal dispatch add no supplemental debt or credit');
    $calls = $state->calls;
    foreach ([0, 1, null, 1] as $seller) {
        scenarioRetained($d, static function () use ($sellers, $clock, $tlds, $seller, $engine, $writer, $op): void {
            scenarioSwitch($sellers, $clock, $seller === null ? null : $tlds[$seller]->server_id); $engine->run($op->id, $writer);
        });
    }
    same($calls, $state->calls, 'Pending renewal cannot re-read or replay after repeated switches');
    same($writes + ($held ? 0 : 1), $state->writes, 'Held cycle has no write; pending cycle has exactly one');
    same($money, scenarioMoney(), 'Replay preserves all invoice, item, transaction and credit bytes');
    if ($held) { continue; }
    $state->expiry = '2028-10-18'; $state->charge = '8.00';
    same('succeeded', $completion->run($op->id)->state, 'Original holder completes while another registrar sells');
    same('2028-10-18', $d->fresh()->provider_expires_at->format('Y-m-d'), 'One proved annual expiry advance');
    same(null, $d->service->fresh()->expires_at, 'Native billing expiry remains null');
    same($money, scenarioMoney(), 'Completion preserves issued financial history');
}
echo "Non-default holding renewals, repeated switches and complimentary cap boundaries passed\n";
$nativeSuccessful = true;
