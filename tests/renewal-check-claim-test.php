<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, MoneyWorkSchema, OperationReconciliation, PricingRule, RegistrarAnswers, StaffOperationReview, StaffOperations, StaffReviewRefused, StaffSettingsReview as Review, StaffSettingsWrites};
app()->instance(Clock::class, $clock);
$staff = commerceUser();
$staff->update(['role_id' => App\Models\Role::create(['name' => 'Renewal check claim', 'permissions' => [
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
$staffCheck = static function ($op, $completion): bool { try { $completion->run($op->id, staff: true); } catch (StaffReviewRefused) { return true; } return false; };
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$polls = static fn ($op) => $op->fresh()->policy['renewal_polls'] ?? 0;
$check = static function ($op, $completion) use ($clock): void { Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes'); };
app()->instance(AccountBindings::class, $bindings);

// Four lagging checks; the fifth sees the renewal in its Expiry reply. While its last read is on the way, a newer cron check, a
// staff check and Not processed all arrive with lagging reads. Neither check reads, the proof saved with that reply refuses Not
// processed, and the fifth check saves its poll.
[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('check-claim-race-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($op->id, $writer)->state, 'The renewal was sent once');
$originalMoney = nativeFinancialSnapshot($op); $sentCount = $state->writes;
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order; $clock->instant = $clock->now()->modify('+25 hours');
for ($i = 1; $i < OperationReconciliation::POLL_LIMIT; $i++) { $check($op, $completion); }
same(OperationReconciliation::POLL_LIMIT - 1, $polls($op), 'Four lagging checks counted');
$before = $credit(); $priorState = $op->fresh()->state; $inner = [];
$state->after = '2028-10-18'; $state->duringCommand = ReadCommand::Order;
$state->during = static function () use ($op, $completion, $state, $record, $staffCheck, $polls, &$inner): void {
    $state->after = '2027-10-18'; $calls = count($state->calls);
    $completion->run($op->id); $inner['cron'] = [count($state->calls) - $calls, $polls($op)];
    $inner['staff'] = $staffCheck($op, $completion);
    $inner['not_processed_refused'] = $record($op);
    $state->after = '2028-10-18'; $state->duringCommand = null;
};
Cache::flush(); $completion->run($op->id); $fresh = $op->fresh();
$actual = [$inner, $polls($op), (renewalObservation($fresh)['identity'] ?? null) === RegistrarAnswers::identity($fresh), renewalRead($fresh) !== null,
    $credit() - $before, $fresh->state];
same([['cron' => [0, 4], 'staff' => true, 'not_processed_refused' => true], 5, true, false, 0, $priorState], $actual,
    'A running check keeps other checks and Not processed out, then saves its poll and proof: '.json_encode($actual));
$state->after = '2027-10-18';
same([true, 0], [$record($op), $credit() - $before], 'Later lagging reads cannot record Not processed for the observed renewal');

same([$sentCount, $originalMoney], [$state->writes, nativeFinancialSnapshot($op)], 'A refused answer preserves expiry, receipts and customer funds without another send');
// A check that died after its Expiry reply showed the renewal: other checks wait while its claim is live, the proof saved with
// that reply refuses Not processed once the claim expires, and the next check takes the claim over and counts its poll. The claim
// stays as an unresolved mark, so Not processed stays refused.
[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('check-claim-stale-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($op->id, $writer)->state, 'The second renewal was sent once');
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order; $clock->instant = $clock->now()->modify('+25 hours');
for ($i = 1; $i < OperationReconciliation::POLL_LIMIT; $i++) { $check($op, $completion); }
// Fixture for a lost worker: the claim a real check took in its first locked step, restored after that check's save,
// as if the worker had died during its last read.
$lost = null; $state->after = '2028-10-18'; $state->duringCommand = ReadCommand::Order;
$state->during = static function () use ($op, &$lost) { $lost = renewalRead($op->fresh()); };
$check($op, $completion); $state->after = '2027-10-18';
$fresh = $op->fresh(); setRenewalRead($fresh, $lost); $fresh->update(['policy' => [...$fresh->policy, 'renewal_polls' => OperationReconciliation::POLL_LIMIT - 1]]);
$clock->instant = $clock->now()->modify('-10 minutes'); $calls = count($state->calls);
Cache::flush(); $completion->run($op->id); $live = [is_array($lost), count($state->calls) - $calls, $polls($op), $staffCheck($op, $completion)];
same([true, 0, OperationReconciliation::POLL_LIMIT - 1, true], $live, 'A live claim keeps the next checks from reading: '.json_encode($live));
$clock->instant = $clock->now()->modify('+'.(OperationReconciliation::POLL_LIMIT * 600).' seconds'); $before = $credit();
same([true, 0, 'pending'], [$record($op), $credit() - $before, $op->fresh()->state === 'rejected' ? 'rejected' : 'pending'], 'A lost check\'s saved proof refuses Not processed after its claim expires');
$calls = count($state->calls); $check($op, $completion); $fresh = $op->fresh();
same([true, OperationReconciliation::POLL_LIMIT, true, false, true], [count($state->calls) > $calls, $polls($op), renewalRead($fresh)['unresolved'] ?? null,
    isset(renewalRead($fresh)['token']), $record($op)],
    'The next check takes over the expired claim and counts its poll; the lost check stays unresolved and Not processed refused');
// Only the locked projection fails. The clean read must release its claim even though Check status reports a refusal.
MoneyWorkSchema::install();
[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('check-claim-busy-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($op->id, $writer)->state, 'The renewal was sent once');
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order;
$clock->instant = $clock->now()->modify('+25 hours');
for ($i = 1; $i < OperationReconciliation::POLL_LIMIT; $i++) { $check($op, $completion); }
$remaining = 0; $conflicts = 0;
App\Models\User::retrieved(static function ($owner) use ($user, &$remaining, &$conflicts): void {
    if ($owner->id !== $user->id || DB::transactionLevel() !== 1 || $remaining === 0) { return; }
    $remaining--; $conflicts++;
    $error = new PDOException('Synthetic owner lock wait'); $error->errorInfo = ['HY000', 1205, 'Synthetic'];
    throw new QueryException('sqlite', 'synthetic owner lock', [], $error);
});
$state->duringCommand = ReadCommand::Expiry;
$state->during = static function () use (&$remaining): void { $remaining = 2; };
Cache::flush();
$busy = $staffCheck($op, $completion); $afterBusy = $op->fresh(); $claimAfterBusy = renewalRead($afterBusy);
$state->duringCommand = null; $clock->instant = $clock->now()->modify('+10 minutes');
$before = $credit(); $writes = $state->writes; $check($op, $completion);
$refused = $record($op); $replay = $record($op);
$actual = [$busy, $conflicts, $claimAfterBusy === null, $afterBusy->policy['renewal_polls'],
    $refused, $replay, $credit() - $before, $state->writes - $writes];
same([true, 2, true, OperationReconciliation::POLL_LIMIT - 1, false, true, PricingRule::minor($op->amount), 0], $actual,
    'A clean read followed by contention leaves the last poll available and permits a later credit once: '.json_encode($actual));
echo "One registrar check runs at a time per renewal attempt.\n"; $nativeSuccessful = true;
