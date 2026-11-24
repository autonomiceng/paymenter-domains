<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationReconciliation, PricingRule, RegistrarAnswers, StaffSettingsReview as Review, StaffSettingsWrites};
app()->instance(Clock::class, $clock);
app()->instance(AccountBindings::class, $renewBindings);
$staff = commerceUser();
$answerPermissions = ['admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer'];
$staff->update(['role_id' => App\Models\Role::create(['name' => 'Renewal money review',
    'permissions' => [...$answerPermissions, 'admin.domains.manage', 'admin.invoices.update']])->id]);
$answerOnly = commerceUser();
$answerOnly->update(['role_id' => App\Models\Role::create(['name' => 'Renewal answer only', 'permissions' => $answerPermissions])->id]);
$settings = new StaffSettingsWrites($native, $sellers, $renewBindings);
$reviewFor = static fn ($op, $actor) => new Review($actor->id, $op->server_id, null, 'enom_answer',
    Review::ticket($actor->id, $op->server_id, null, 'enom_answer', Review::stamp($op->server_id, null)), 'Record renewal answer');
$record = static function ($op, ?Review $review = null, ?string $identity = null, string $outcome = 'not_processed') use ($staff, $settings, $clock, $reviewFor): bool {
    $op = $op->fresh();
    $review ??= $reviewFor($op, $staff);
    try {
        $settings->run($review, ['operation_id' => $op->id, 'attempt_hash' => $identity ?? RegistrarAnswers::identity($op),
            'outcome' => $outcome, 'order_reference' => $outcome === 'accepted' ? $op->provider_order_id : '', 'case_reference' => '',
            'evidence' => 'Registrar support checked the exact account and renewal attempt.', 'confirmed' => true], $clock->now());
    } catch (DomainException) { return true; }
    return false;
};
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$stoppedRenewal = static function (string $name) use ($bindings, $makeRenewal, $compose, $state, $clock, $renewBindings) {
    app()->instance(AccountBindings::class, $bindings);
    [$op, , $binding] = $makeRenewal(placeholderComFixtureName($name));
    [$engine, $writer, $completion] = $compose($binding);
    same('pending', $engine->run($op->id, $writer)->state, 'The renewal was sent once');
    $state->after = '2027-10-18'; $state->fail = ReadCommand::Order;
    $clock->instant = $clock->now()->modify('+25 hours');
    for ($i = 0; $i < OperationReconciliation::POLL_LIMIT; $i++) {
        Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
    }
    expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), 'The stopped renewal offers a staff answer');
    app()->instance(AccountBindings::class, $renewBindings);
    $state->fail = null;
    return $op;
};

// A refused answer that just saw the new expiry must protect the same attempt when subsequent reads lag.
$op = $stoppedRenewal('answer-retained-proof-placeholder.com');
$before = $credit(); $writes = $state->writes; $priorState = $op->fresh()->state;
$calls = count($state->calls);
$unauthorized = $record($op, $reviewFor($op, $answerOnly));
$stale = $record($op, identity: str_repeat('0', 64));
$invalidSubmissions = [$unauthorized, $stale, count($state->calls) - $calls, $op->fresh()->renewal_check ?? null];

$review = $reviewFor($op, $staff);
$state->fail = ReadCommand::Info;
expect($record($op, $review), 'A read failure without any positive proof refuses the answer');
$afterHarmlessFailure = $op->fresh()->renewal_check ?? null;
$state->fail = null; $state->after = '2028-10-18';
Cache::flush();
expect($record($op, $review), 'Retrying the same review now sees the advanced expiry and refuses the answer');
$proof = $op->fresh()->renewal_proof ?? null;
$state->after = '2027-10-18';
$refused = $record($op); $fresh = $op->fresh(); $kept = $fresh->renewal_proof ?? null;
$afterSavedProof = $fresh->renewal_check ?? null;
$actual = [$credit() - $before, $fresh->state, $fresh->funding['refund_authorization'] ?? null,
    $fresh->policy['enom_answer'] ?? null, $refused, is_array($proof) && $kept === $proof && ($kept['identity'] ?? null) === RegistrarAnswers::identity($fresh), $state->writes - $writes];
same([0, $priorState, null, null, true, true, 0], $actual,
    'Fresh positive proof survives refusal and later lagging reads without returning the renewal payment: '.json_encode($actual));
echo "A fresh advanced expiry protects later renewal answers.\n";

// The staff read sees a later expiry, but every attempt to save that proof times out.
$op = $stoppedRenewal('answer-unsaved-proof-placeholder.com');
$before = $credit(); $writes = $state->writes; $priorState = $op->fresh()->state;
$remaining = 0; $conflicts = 0;
DB::connection()->beforeExecuting(static function (string $sql) use (&$remaining, &$conflicts): void {
    if ($remaining === 0 || DB::transactionLevel() !== 1 || !str_starts_with($sql, 'select')
        || !str_contains($sql, 'from "domains_operations"')) { return; }
    $remaining--; $conflicts++;
    $error = new PDOException('Synthetic operation lock wait');
    $error->errorInfo = ['HY000', 1205, 'Synthetic operation lock wait'];
    throw $error;
});
$state->after = '2028-10-18';
$state->duringCommand = ReadCommand::Expiry;
$state->during = static function () use (&$remaining): void { $remaining = 6; };
Cache::flush();
expect($record($op), 'The fresh advanced expiry refuses the answer even when its proof cannot be saved');
same([6, 0, null], [$conflicts, $remaining, $op->fresh()->renewal_proof ?? null],
    'All six proof writes met operation-lock timeouts and no proof was saved');

$state->after = '2027-10-18';
$clock->instant = $clock->now()->modify('+10 minutes');
Cache::flush();
$refused = $record($op); $fresh = $op->fresh();
$actual = [$credit() - $before, $fresh->state, $fresh->funding['refund_authorization'] ?? null,
    $fresh->policy['enom_answer']['outcome'] ?? null, $refused, $state->writes - $writes];
same([0, $priorState, null, null, true, 0], $actual,
    'Unsaved positive proof still prevents credit, refund authorization, a rejected renewal or another send: '.json_encode($actual));

same([true, true, 0, null], $invalidSubmissions, 'Missing refund permission or a stale attempt hash prevents the fresh read and claim');
same([null, null], [$afterHarmlessFailure, $afterSavedProof], 'The staff read releases its claim after a harmless failure or a saved proof');
expect(!$record($op, outcome: 'accepted'), 'Staff can still record Processed while unsaved proof blocks the refund');
same(['accepted', 0, 0], [$op->fresh()->policy['enom_answer']['outcome'] ?? null, $credit() - $before, $state->writes - $writes],
    'Processed moves no money and sends no renewal');
// A failed read with no positive evidence leaves the same staff review available for a fresh attempt.
$op = $stoppedRenewal('answer-retry-placeholder.com');
$review = $reviewFor($op, $staff); $before = $credit(); $writes = $state->writes;
$state->fail = ReadCommand::Expiry; Cache::flush();
expect($record($op, $review), 'The failed expiry read refuses credit');
same([null, null, 0], [$op->fresh()->renewal_check ?? null, $op->fresh()->policy['enom_answer'] ?? null, $credit() - $before],
    'The failed read releases its claim and moves no money');
$state->fail = null; Cache::flush();
expect(!$record($op, $review), 'A healthy read permits staff to retry with the same review');
expect($record($op, $review), 'The completed review cannot be replayed');
same(['rejected', 'not_processed', PricingRule::minor($op->amount), 0],
    [$op->fresh()->state, $op->fresh()->policy['enom_answer']['outcome'] ?? null, $credit() - $before, $state->writes - $writes],
    'The successful retry credits once without another send');
// A reply authorized under an old token cannot clear a successor or move money.
$op = $stoppedRenewal('answer-superseded-placeholder.com');
$before = $credit(); $writes = $state->writes; $successor = null;
$state->duringCommand = ReadCommand::Expiry;
$state->during = static function () use ($op, &$successor): void {
    $current = $op->fresh(); $claim = $current->renewal_check ?? null;
    if ($claim === null) { return; }
    $successor = [...$claim, 'token' => str_repeat('a', 32), 'unresolved' => true];
    $current->update(['renewal_check' => $successor]);
};
Cache::flush();
$refused = $record($op); $fresh = $op->fresh();
same([true, true, null, 0, 0], [$refused, $successor !== null && ($fresh->renewal_check ?? null) === $successor,
    $fresh->policy['enom_answer'] ?? null, $credit() - $before, $state->writes - $writes],
    'The stale worker cannot authorize credit or clear the replacement claim');
$nativeSuccessful = true;
