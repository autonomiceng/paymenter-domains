<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationReconciliation, PricingRule, RegistrarAnswers, StaffOperationReview, StaffOperations, StaffSettingsReview as Review, StaffSettingsWrites};
app()->instance(Clock::class, $clock);
$staff = commerceUser();
$staff->update(['role_id' => App\Models\Role::create(['name' => 'Renewal resume race', 'permissions' => [
    'admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer', 'admin.domains.manage', 'admin.invoices.update',
    'admin.domains.operations.resume_reads',
]])->id]);
$settings = new StaffSettingsWrites($native, $sellers, $renewBindings);
$record = static function ($op) use ($staff, $settings, $clock): bool {
    $op = $op->fresh();
    $review = new Review($staff->id, $op->server_id, null, 'enom_answer',
        Review::ticket($staff->id, $op->server_id, null, 'enom_answer', Review::stamp($op->server_id, null)), 'Record renewal answer');
    try {
        $settings->run($review, ['operation_id' => $op->id, 'attempt_hash' => RegistrarAnswers::identity($op),
            'outcome' => 'not_processed', 'order_reference' => '', 'case_reference' => '',
            'evidence' => 'Registrar support checked the exact account and renewal attempt.', 'confirmed' => true], $clock->now());
    } catch (DomainException) { return true; }
    return false;
};
$resume = static function ($op) use ($staff, $clock): void {
    $op = $op->fresh(); $d = $op->domain()->firstOrFail();
    (new StaffOperations($clock))->run($staff, (int) $d->id, (int) $op->id, 'resume_reads',
        StaffOperationReview::ticket((int) $staff->id, (int) $d->id, (int) $op->id, 'resume_reads', StaffOperationReview::stamp($op, $d), $clock), 'Check the registrar again.');
};
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$stop = static function ($op, $completion) use ($clock): void {
    for ($i = 0; $i < OperationReconciliation::POLL_LIMIT; $i++) {
        Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
    }
    expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), 'The stopped renewal offers a staff answer');
};

// A check reads the advanced expiry while staff resume reads on the same renewal. The stale check completes nothing, yet the
// expiry it saw stays as proof, so later lagging reads cannot let staff record Not processed and return the payment.
app()->instance(AccountBindings::class, $bindings);
[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('resume-race-proof-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($op->id, $writer)->state, 'The renewal was sent once');
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order;
$clock->instant = $clock->now()->modify('+25 hours'); $stop($op, $completion);
$resume($op); $polls = $op->fresh()->policy['renewal_polls'] ?? null;
$state->after = '2028-10-18'; $state->duringCommand = ReadCommand::Info; $state->during = static fn () => $resume($op);
Cache::flush(); $raced = $completion->run($op->id);
$fresh = $op->fresh();
same([0, 2, true, false], [$polls, count($fresh->policy['renewal_resumptions'] ?? []), ($fresh->renewal_proof['identity'] ?? null) === RegistrarAnswers::identity($fresh),
    $raced->state === 'succeeded'], 'The raced check keeps the advanced expiry it saw and completes nothing');
$state->after = '2027-10-18'; $clock->instant = $clock->now()->modify('+10 minutes'); $stop($op, $completion);
app()->instance(AccountBindings::class, $renewBindings);
$before = $credit(); $writes = $state->writes; $priorState = $op->fresh()->state;
$refused = $record($op); $fresh = $op->fresh();
$actual = [$refused, $credit() - $before, $fresh->state, $fresh->funding['refund_authorization'] ?? null, $fresh->policy['enom_answer'] ?? null, $state->writes - $writes];
same([true, 0, $priorState, null, null, 0], $actual, 'Not processed stays refused after the resume race: '.json_encode($actual));
echo "A resume race keeps the renewal proof the stale check saw.\n"; $nativeSuccessful = true;
