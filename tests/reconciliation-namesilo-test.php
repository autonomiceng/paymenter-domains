<?php
// Native no-ID accepted-history completion; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-no-id-sealed.php';
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances;
// TEST accounts have built-in registration history; a Live deployment has none until it is saved.
$environment = config('domains.namesilo_account.environment'); config(['domains.namesilo_account.environment' => 'live']);
for ($i = 0; $i < 6; $i++) { $result = $reconcile->run($op->id); }
same('pending', $result->state, 'Unaccepted no-ID history stays pending without consuming covered polls');
same(0, $result->policy['reconciliation']['polls'], 'Absent acceptance cannot exhaust recovery');
same(0, $wire->calls, 'Absent acceptance does no provider work');
same(['awaiting_answer', 'NameSilo'], [$result->policy['status_check']['outcome'] ?? null, $result->policy['status_check']['registrar'] ?? null], 'Absent acceptance leaves the NameSilo answer step');
config(['domains.namesilo_account.environment' => $environment]);
HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 600, 86400, 'namesilo_registration_v1'));
for ($i = 0; $i < 6; $i++) { $result = $reconcile->run($op->id); }
same(0, $result->policy['reconciliation']['polls'], 'History lag cannot exhaust recovery'); same(0, $wire->calls, 'Uncovered history does no provider work');
$clock->instant = $clock->now()->modify('+301 seconds');
$result = $reconcile->run($op->id); same('succeeded', $result->state, 'Accepted unique history completes no-ID native operation');
same('901', $result->provider_order_id, 'Read-confirmed order persisted'); same('active', $result->domain->status, 'Final ownership');
same(null, $result->attempt['registration']['order_reference'], 'Original missing order ID remains immutable');
same('registration_correction_required', $result->safe_code, 'Correction work retained after financial completion');
$before = $wire->calls; $reconcile->run($op->id); same($before, $wire->calls, 'No replay or repeated provider read after completion');
echo "Native NameSilo no-ID accepted-history unique completion passed\n"; $nativeSuccessful = true;
