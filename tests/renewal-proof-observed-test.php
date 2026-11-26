<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, MoneyDecisions, MoneyWorkSchema, OperationReconciliation, PricingRule, RegistrarAnswers, StaffOperationReview, StaffOperations, StaffSettingsReview as Review, StaffSettingsWrites};
app()->instance(Clock::class, $clock);
$staff = commerceUser();
$staff->update(['role_id' => App\Models\Role::create(['name' => 'Renewal proof observed', 'permissions' => [
    'admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer', 'admin.domains.manage', 'admin.invoices.update',
    'admin.domains.operations.resume_reads',
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
$resume = static function ($op) use ($staff, $clock): void {
    $op = $op->fresh(); $d = $op->domain()->firstOrFail();
    (new StaffOperations($clock))->run($staff, (int) $d->id, (int) $op->id, 'resume_reads',
        StaffOperationReview::ticket((int) $staff->id, (int) $d->id, (int) $op->id, 'resume_reads', StaffOperationReview::stamp($op, $d), $clock), 'Check the registrar again.');
};
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$polls = static fn ($op) => $op->fresh()->policy['renewal_polls'] ?? 0;
$check = static function ($op, $completion) use ($clock): void { Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes'); };
// Fixture: while armed, each money decision meets a lock wait timeout (MariaDB 1205), so a check's locked save retries once and defers.
$contend = 0;
DB::listen(static function () use (&$contend): void {
    if ($contend > 0 && MoneyDecisions::running() && DB::transactionLevel() === 1) {
        $contend--; $e = new PDOException('Lock wait timeout exceeded'); $e->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded']; throw $e;
    }
});
app()->instance(AccountBindings::class, $bindings);

[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('proof-observed-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($op->id, $writer)->state, 'The renewal was sent once');
$originalMoney = nativeFinancialSnapshot($op); $sentCount = $state->writes;
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order; $clock->instant = $clock->now()->modify('+25 hours');
for ($i = 1; $i < OperationReconciliation::POLL_LIMIT; $i++) { $check($op, $completion); }
$before = $credit(); $priorState = $op->fresh()->state; $writes = $state->writes; $identity = RegistrarAnswers::identity($op->fresh());

// Deferral: the fifth check's Expiry reply shows the renewal. Its locked save then meets contention twice and defers to the queue.
$state->after = '2028-10-18'; $state->duringCommand = ReadCommand::Order; $atReply = null;
$state->during = static function () use ($op, &$contend, &$atReply): void { $atReply = renewalObservation($op->fresh())['identity'] ?? null; $contend = 2; };
Cache::flush(); $completion->run($op->id); $fresh = $op->fresh();
$deferred = [$atReply, $contend, DB::table(MoneyWorkSchema::TABLE)->where('subject_id', $op->id)->count(), renewalRead($fresh) !== null,
    $polls($op), renewalObservation($fresh)['identity'] ?? null];

// The deferred check ended its claim once its read and proof were saved. The next check (a lagging read) counts the last poll.
$state->after = '2027-10-18'; $state->duringCommand = null; $clock->instant = $clock->now()->modify('+1 hour');
$calls = count($state->calls); $check($op, $completion); $fresh = $op->fresh();
$takeover = [count($state->calls) > $calls, $polls($op), renewalRead($fresh) !== null];
$taken = [$record($op), $credit() - $before, $op->fresh()->state, $state->writes - $writes];
same([true, 0, $priorState, 0], $taken, 'After deferral and a later check, lagging reads cannot record Not processed: '.json_encode($taken));
same([$identity, 0, 1, false, OperationReconciliation::POLL_LIMIT - 1, $identity], $deferred,
    'Proof is saved as the reply returns, and stays when the locked save defers: '.json_encode($deferred));
same([true, OperationReconciliation::POLL_LIMIT, false], $takeover, 'The next check runs and counts the last poll');

// Resume: staff restart the reads; five more lagging checks reach the limit again, and the saved proof still refuses Not processed.
$resume($op);
same(0, $polls($op), 'Staff restarted the reads');
for ($i = 0; $i < OperationReconciliation::POLL_LIMIT; $i++) { $check($op, $completion); }
$fresh = $op->fresh();
$resumed = [$polls($op), renewalObservation($fresh)['identity'] ?? null, $record($op), $credit() - $before, $op->fresh()->state, $state->writes - $writes];
same([OperationReconciliation::POLL_LIMIT, $identity, true, 0, $priorState, 0], $resumed,
    'Proof survives a staff resume and later lagging checks: '.json_encode($resumed));
same([$sentCount, $originalMoney], [$state->writes, nativeFinancialSnapshot($op)], 'A refused answer preserves expiry, receipts and customer funds without another send');
echo "Renewal proof is saved as each read returns it and survives deferral, a later check and resume.\n"; $nativeSuccessful = true;
