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
$staff->update(['role_id' => App\Models\Role::create(['name' => 'Renewal money review', 'permissions' => [
    'admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer', 'admin.domains.manage', 'admin.invoices.update',
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
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$stop = static function ($op, $completion) use ($clock): void {
    for ($i = 0; $i < OperationReconciliation::POLL_LIMIT; $i++) {
        Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
    }
    expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), 'The stopped renewal offers a staff answer');
};

// The fresh expiry reply must remain within its deadline through the final money decision.
$delayFinalLock = false; $delayed = 0;
DB::connection()->beforeExecuting(static function (string $sql) use ($clock, &$delayFinalLock, &$delayed): void {
    if (!$delayFinalLock || DB::transactionLevel() !== 1 || !str_starts_with($sql, 'select')
        || !str_contains($sql, 'from "domains_operations"')) { return; }
    $delayFinalLock = false; $delayed++;
    $clock->instant = $clock->now()->modify('+31 seconds');
});
$results = []; $expected = [];
foreach (['failed', 'malformed', 'late', 'lock-wait'] as $fault) {
    app()->instance(AccountBindings::class, $bindings);
    [$op, $d, $b] = $makeRenewal(placeholderComFixtureName('fresh-expiry-'.$fault.'-placeholder.com'));
    [$engine, $writer, $completion] = $compose($b);
    same('pending', $engine->run($op->id, $writer)->state, 'The renewal was sent once');
    $state->after = '2027-10-18'; $state->fail = ReadCommand::Order;
    $clock->instant = $clock->now()->modify('+25 hours'); $stop($op, $completion);
    app()->instance(AccountBindings::class, $renewBindings);
    $state->fail = $fault === 'failed' ? ReadCommand::Expiry : null;
    if ($fault === 'malformed') { $state->after = 'unreadable'; }
    if ($fault === 'late') {
        $state->duringCommand = ReadCommand::Expiry;
        $state->during = static function () use ($clock): void { $clock->instant = $clock->now()->modify('+31 seconds'); };
    }
    if ($fault === 'lock-wait') {
        $state->duringCommand = ReadCommand::Expiry;
        $state->during = static function () use (&$delayFinalLock): void { $delayFinalLock = true; };
    }
    $before = $credit(); $priorState = $op->fresh()->state; $refused = $record($op); $fresh = $op->fresh();
    $results[$fault] = [$credit() - $before, $fresh->state, $fresh->funding['refund_authorization'] ?? null, $fresh->policy['enom_answer'] ?? null, $refused];
    $expected[$fault] = [0, $priorState, null, null, true];
    $state->fail = null; $state->after = '2027-10-18'; $state->duringCommand = null; $state->during = null;
}
same(1, $delayed, 'The simulated lock wait begins after the successful expiry reply');
same($expected, $results, 'Unconfirmed fresh expiry must refuse credit and preserve the unresolved renewal: '.json_encode($results));
echo "Unconfirmed fresh expiry refuses renewal credit.\n"; $nativeSuccessful = true;
