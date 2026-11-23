<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, MoneyDecisions, OperationReconciliation, PricingRule, RegistrarAnswers, StaffSettingsReview as Review, StaffSettingsWrites};
app()->instance(Clock::class, $clock);
$staff = commerceUser();
$staff->update(['role_id' => App\Models\Role::create(['name' => 'Renewal proof unresolved', 'permissions' => [
    'admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer', 'admin.domains.manage', 'admin.invoices.update',
]])->id]);
$settings = new StaffSettingsWrites($native, $sellers, $renewBindings);
// Not processed through the staff answer, with its own fresh registrar read; true when it is refused.
$record = static function ($op) use ($staff, $settings, $clock, $renewBindings, $bindings): bool {
    $op = $op->fresh(); app()->instance(AccountBindings::class, $renewBindings);
    $review = new Review($staff->id, $op->server_id, null, 'enom_answer',
        Review::ticket($staff->id, $op->server_id, null, 'enom_answer', Review::stamp($op->server_id, null)), 'Record renewal answer');
    try {
        $settings->run($review, ['operation_id' => $op->id, 'attempt_hash' => RegistrarAnswers::identity($op),
            'outcome' => 'not_processed', 'order_reference' => '', 'case_reference' => '',
            'evidence' => 'Registrar support checked the exact account and renewal attempt.', 'confirmed' => true], $clock->now());
    } catch (DomainException) { return true; }
    finally { app()->instance(AccountBindings::class, $bindings); }
    return false;
};
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$polls = static fn ($op) => $op->fresh()->policy['renewal_polls'] ?? 0;
$check = static function ($op, $completion) use ($clock): void { Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes'); };
// A sent renewal whose reply was lost, 25 hours later, after one lagging check: Not processed is open to staff.
$lost = static function (string $name) use ($makeRenewal, $compose, $state, $clock, $check, $bindings): array {
    app()->instance(AccountBindings::class, $bindings);
    [$op, $d, $b] = $makeRenewal(placeholderComFixtureName($name));
    [$engine, $writer, $completion] = $compose($b); $state->lost = true;
    same('uncertain', $engine->run($op->id, $writer)->state, 'The sent renewal loses its reply');
    $state->lost = false; $state->fail = null; $state->after = '2027-10-18'; $state->expiry = '10/18/2027 5:00:00 AM';
    $clock->instant = $clock->now()->modify('+25 hours'); $check($op, $completion);
    return [$op, $completion];
};

// Clean: a check that finds nothing leaves Not processed to credit normally.
[$op, $completion] = $lost('proof-unresolved-clean-placeholder.com');
$before = $credit();
$clean = [$record($op), $credit() - $before > 0, $op->fresh()->state];
same([false, true, 'rejected'], $clean, 'After a clean check that found nothing, Not processed credits the payment: '.json_encode($clean));

// Window 1: Not processed arrives while a live check still waits on the reply that shows the renewal.
[$op, $completion] = $lost('proof-unresolved-live-placeholder.com');
$originalMoney = nativeFinancialSnapshot($op);
$before = $credit(); $priorState = $op->fresh()->state; $writes = $state->writes; $inner = null;
$state->duringCommand = ReadCommand::Info;
$state->during = static function () use ($op, $record, $state, &$inner): void {
    $inner = $record($op); $state->expiry = '10/18/2028 5:00:00 AM';
};
Cache::flush(); $completion->run($op->id); $state->expiry = '10/18/2027 5:00:00 AM';
$live = [$inner, $credit() - $before, $op->fresh()->state, RegistrarAnswers::renewalProof($op->fresh(), $op->fresh()->domain()->firstOrFail()), $record($op), $credit() - $before, $state->writes - $writes];
same($originalMoney, nativeFinancialSnapshot($op), 'The unresolved check leaves the original receipts and expiry intact');
$liveExpected = [true, 0, $priorState, true, true, 0, 0];

// Window 2: the check's Expiry reply shows the renewal, and every save of that proof meets a lock wait timeout. The check stops,
// its claim stays, and the next lagging check takes it over and counts the last poll. Not processed stays refused.
$failing = 0;
DB::listen(static function ($q) use (&$failing): void {
    if ($failing > 0 && DB::transactionLevel() === 1 && !MoneyDecisions::running() && str_contains($q->sql, 'domains_operations')) {
        $failing--; $e = new PDOException('Lock wait timeout exceeded'); $e->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded']; throw $e;
    }
});
app()->instance(AccountBindings::class, $bindings);
[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('proof-unresolved-failed-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($op->id, $writer)->state, 'The renewal was sent once');
$originalMoney = nativeFinancialSnapshot($op);
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order; $clock->instant = $clock->now()->modify('+25 hours');
for ($i = 1; $i < OperationReconciliation::POLL_LIMIT; $i++) { $check($op, $completion); }
$before = $credit(); $priorState = $op->fresh()->state; $writes = $state->writes;
$state->after = '2028-10-18'; $state->duringCommand = ReadCommand::Expiry;
$state->during = static function () use (&$failing): void { $failing = 6; };
$stopped = false; try { Cache::flush(); $completion->run($op->id); } catch (RuntimeException) { $stopped = true; }
$fresh = $op->fresh();
$failed = [$stopped, $failing, isset($fresh->renewal_proof), isset($fresh->renewal_check), $polls($op)];
$state->after = '2027-10-18'; $state->duringCommand = null; $clock->instant = $clock->now()->modify('+1 hour');
$check($op, $completion); $fresh = $op->fresh();
$taken = [$polls($op), $fresh->renewal_check['unresolved'] ?? null, isset($fresh->renewal_check['token']),
    $record($op), $credit() - $before, $op->fresh()->state, $state->writes - $writes];
same($originalMoney, nativeFinancialSnapshot($op), 'The unresolved check leaves the original receipts and expiry intact');
// Both windows are compared together, so a failure shows each one.
$windows = ['live' => $live, 'failed' => $failed, 'taken' => $taken];
same(['live' => $liveExpected, 'failed' => [true, 0, false, true, OperationReconciliation::POLL_LIMIT - 1],
    'taken' => [OperationReconciliation::POLL_LIMIT, true, false, true, 0, $priorState, 0]], $windows,
    'Not processed is refused while a check is live and after a failed proof save, even once the check is taken over: '.json_encode($windows));
echo "Not processed waits for a live check and stays refused after a failed proof save.\n"; $nativeSuccessful = true;
