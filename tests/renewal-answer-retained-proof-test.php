<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
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

// A refused answer that just saw the new expiry must protect the same attempt when subsequent reads lag.
app()->instance(AccountBindings::class, $bindings);
[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('answer-retained-proof-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($op->id, $writer)->state, 'The renewal was sent once');
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order;
$clock->instant = $clock->now()->modify('+25 hours'); $stop($op, $completion);
app()->instance(AccountBindings::class, $renewBindings);
$before = $credit(); $writes = $state->writes; $priorState = $op->fresh()->state;
$state->fail = null; $state->after = '2028-10-18';
expect($record($op), 'The fresh advanced expiry refuses the first answer');
$proof = $op->fresh()->policy['renewal_proof'] ?? null;
$state->after = '2027-10-18';
$refused = $record($op); $fresh = $op->fresh();
$actual = [$credit() - $before, $fresh->state, $fresh->funding['refund_authorization'] ?? null,
    $fresh->policy['enom_answer'] ?? null, $refused, is_array($proof) && ($proof['identity'] ?? null) === RegistrarAnswers::identity($fresh), $state->writes - $writes];
same([0, $priorState, null, null, true, true, 0], $actual,
    'Fresh positive proof survives refusal and later lagging reads without returning the renewal payment: '.json_encode($actual));
echo "A fresh advanced expiry protects later renewal answers.\n"; $nativeSuccessful = true;
