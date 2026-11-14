<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, ManagementEvidence, RenewalCycles, RenewalFunding};

$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Renewal funding', 'permissions' => ['admin.domains.manage', 'admin.domains.fund']])->id]);
$cycles = new RenewalCycles($bindings, $clock); $failures = [];
foreach (['auth_code_email', 'nameservers'] as $action) {
    try {
        [$d, $driver] = $holding(placeholderComFixtureName('comp-unknown-'.str_replace('_', '-', $action).'-placeholder.com'));
        $state->fault = 'empty_ack'; $state->apply = false;
        $input = $action === 'nameservers' ? ['nameservers' => ['ns3.example.net', 'ns4.example.net']] : [];
        $peer = $submit($d, $driver, $action, $input);
        same('uncertain', $management->run($peer->id, $driver)->state, 'Management reply has no acknowledgement or matching readback');
        expect(!ManagementEvidence::resolved($peer->fresh(), $d), 'Management outcome remains unresolved');
        $before = $peer->fresh()->getRawOriginal(); $calls = $state->calls;
        commerceReject(fn () => $cycles->complimentary($d->id, $user, 1, new Money('8.95', 'USD'), 'staff_reviewed'));
        $op = $cycles->complimentary($d->id, $staff, 1, new Money('8.95', 'USD'), 'staff_reviewed');
        same('queued', $op->state, 'Authorized staff can issue complimentary renewal beside '.$action);
        same('complimentary', $op->funding_basis, 'Renewal retains audited complimentary funding');
        same(null, $op->invoice_id, 'Staff complimentary renewal creates no invoice');
        same((int) $staff->id, (int) $op->authorized_by, 'Funding records its authorizing staff member');
        DB::transaction(static fn () => RenewalFunding::requireCurrent($op, HoldingLocks::take($d)));
        same($op->id, $cycles->complimentary($d->id, $staff, 1, new Money('8.95', 'USD'), 'staff_reviewed')->id, 'Repeated staff funding returns the same obligation');
        same($before, $peer->fresh()->getRawOriginal(), 'Complimentary funding preserves unknown management evidence');
        same($calls, $state->calls, 'Complimentary funding performs no management exchanges');
        echo 'PASS complimentary renewal beside unconfirmed '.$action."\n";
    } catch (Throwable $error) {
        $failures[] = $action.': '.$error->getMessage();
        echo 'FAIL complimentary renewal beside unconfirmed '.$action.': '.$error->getMessage()."\n";
    }
}
if ($failures) { throw new RuntimeException(implode("\n", $failures)); }
$nativeSuccessful = true;
