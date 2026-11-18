<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationReconciliation, PricingRule, RegistrarAnswers,
    RenewalBusy, StaffSettingsReview as Review, StaffSettingsWrites};

app()->instance(Clock::class, $clock);
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Concurrent renewal review', 'permissions' => [
    'admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer',
    'admin.domains.manage', 'admin.invoices.update',
]])->id]);
$settings = new StaffSettingsWrites($native, $sellers, $renewBindings);
$record = static function ($op) use ($staff, $settings, $clock): bool {
    $fresh = $op->fresh();
    $review = new Review($staff->id, $fresh->server_id, null, 'enom_answer',
        Review::ticket($staff->id, $fresh->server_id, null, 'enom_answer', Review::stamp($fresh->server_id, null)), 'Record renewal answer');
    try {
        $settings->run($review, ['operation_id' => $fresh->id, 'attempt_hash' => RegistrarAnswers::identity($fresh),
            'outcome' => 'not_processed', 'order_reference' => '', 'case_reference' => '',
            'evidence' => 'Registrar support checked the exact account and renewal attempt.', 'confirmed' => true], $clock->now());
    } catch (DomainException) { return true; }
    return false;
};
$cash = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));

// Case: a lagging concurrent check cannot authorize credit before an older check saves renewal proof.
app()->instance(AccountBindings::class, $bindings);
[$op, $domain, $binding, $invoice] = $makeRenewal(placeholderComFixtureName('cli-renewal-concurrent-proof-placeholder.com'));
[$engine, $writer, $completion] = $compose($binding);
same('pending', $engine->run($op->id, $writer)->state, 'The funded renewal was sent once');
app()->instance(AccountBindings::class, $renewBindings);
$before = $cash(); $writes = $state->writes; $identity = RegistrarAnswers::identity($op->fresh());
$priorState = $op->fresh()->state; $priorExpiry = $domain->fresh()->provider_expires_at;
$clock->instant = $clock->now()->modify('+25 hours');
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order;
for ($i = 0; $i < OperationReconciliation::POLL_LIMIT - 1; $i++) {
    Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
}
same(OperationReconciliation::POLL_LIMIT - 1, $op->fresh()->policy['renewal_polls'], 'Four unproven checks leave one check available');

// The older check has observed the later Info expiry but has not reached its locked save.
$state->expiry = '10/18/2028 5:00:00 AM'; $state->duringCommand = ReadCommand::Expiry;
$interleaved = null;
$state->during = static function () use ($state, $completion, $op, $record, $cash, $before, &$interleaved): void {
    $state->expiry = '10/18/2027 5:00:00 AM';
    Cache::flush();
    // Serializing checks may refuse this call or leave it pending. Neither outcome requires a fifth saved poll here.
    try { $completion->run($op->id); } catch (DomainException|RenewalBusy) {}
    $refused = $record($op);
    $fresh = $op->fresh();
    $interleaved = [$cash() - $before, $fresh->state, $fresh->funding['refund_authorization'] ?? null,
        $fresh->funding['refund'] ?? null, $fresh->policy['enom_answer'] ?? null, $refused];
};
Cache::flush(); $completion->run($op->id);

// All later replies lag as well, so only the older check's retained observation can protect this money.
$state->duringCommand = null;
for ($i = 0; $i < OperationReconciliation::POLL_LIMIT; $i++) {
    Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
}
$state->fail = null;
$refused = $record($op); $fresh = $op->fresh();
same([0, $priorState, null, null, null, true], $interleaved,
    'An overlapping lagging check and staff answer cannot return a funded renewal payment: '.json_encode($interleaved));
$actual = [$cash() - $before, $fresh->state, $fresh->funding['refund_authorization'] ?? null,
    $fresh->funding['refund'] ?? null, $fresh->policy['enom_answer'] ?? null, $refused,
    RegistrarAnswers::renewalProof($fresh, $domain->fresh()), $fresh->policy['renewal_proof']['identity'] ?? null,
    $state->writes - $writes, $invoice->fresh()->status, $domain->fresh()->provider_expires_at == $priorExpiry];
same([0, $priorState, null, null, null, true, true, $identity, 0, 'paid', true], $actual,
    'The older check retains same-attempt proof and refuses later Not processed answers without credit, refund or another send: '.json_encode($actual));
echo "Concurrent renewal checks retain proof and protect the payment.\n";
$nativeSuccessful = true;
