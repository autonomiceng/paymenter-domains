<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationReconciliation, PricingRule, RegistrarAnswers, RenewalCompletion,
    StaffOperationReview, StaffOperations, StaffSettingsReview as Review, StaffSettingsWrites};

app()->instance(Clock::class, $clock);
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Renewal resume review', 'permissions' => [
    'admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer', 'admin.domains.manage',
    'admin.invoices.update', 'admin.domains.operations.resume_reads',
]])->id]);
$cash = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));

// Case: a staff resume during a read must retain its advanced-expiry proof against a later lagging answer.
app()->instance(AccountBindings::class, $bindings);
[$op, $domain, $binding, $invoice] = $makeRenewal(placeholderComFixtureName('cli-renewal-resume-proof-placeholder.com'));
[$engine, $writer, $completion] = $compose($binding);
same('pending', $engine->run($op->id, $writer)->state, 'The funded renewal was sent once');
app()->instance(AccountBindings::class, $renewBindings); app()->instance(RenewalCompletion::class, $completion);
$before = $cash(); $writes = $state->writes; $identity = RegistrarAnswers::identity($op->fresh());
$priorExpiry = $domain->fresh()->provider_expires_at->format('Y-m-d');
$clock->instant = $clock->now()->modify('+25 hours');
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order;
// Info has already returned the new expiry when the dedicated Expiry read allows a staff action to interleave.
$state->expiry = '10/18/2028 5:00:00 AM'; $state->duringCommand = ReadCommand::Expiry;
$state->during = static function () use ($op, $staff, $clock): void {
    $fresh = $op->fresh();
    $ticket = StaffOperationReview::ticket($staff->id, $fresh->domain_id, $fresh->id, 'resume_reads',
        StaffOperationReview::stamp($fresh, $fresh->domain()->firstOrFail()), $clock);
    (new StaffOperations($clock))->run($staff, $fresh->domain_id, $fresh->id, 'resume_reads', $ticket, 'Read this unresolved renewal again');
};
Cache::flush(); $completion->run($op->id);
$fresh = $op->fresh();
same([1, $identity], [count($fresh->policy['renewal_resumptions'] ?? []), RegistrarAnswers::identity($fresh)],
    'The interleaved public staff action resumes reads without replacing the sealed attempt');
$proof = RegistrarAnswers::renewalProof($fresh, $domain->fresh());
$priorState = $fresh->state;
// Subsequent replies all lag: they cannot erase the positive observation or authorize returning this payment.
$state->expiry = '10/18/2027 5:00:00 AM'; $state->duringCommand = null;
for ($i = 0; $i < OperationReconciliation::POLL_LIMIT; $i++) {
    Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
}
expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), 'The resumed checks stop and offer a registrar answer');
$state->fail = null;
$fresh = $op->fresh();
$review = new Review($staff->id, $fresh->server_id, null, 'enom_answer',
    Review::ticket($staff->id, $fresh->server_id, null, 'enom_answer', Review::stamp($fresh->server_id, null)), 'Record the registrar answer');
$refused = false;
try {
    (new StaffSettingsWrites($native, $sellers, $renewBindings))->run($review, ['operation_id' => $fresh->id,
        'attempt_hash' => RegistrarAnswers::identity($fresh), 'outcome' => 'not_processed', 'order_reference' => '', 'case_reference' => '',
        'evidence' => 'Registrar support checked this account and renewal attempt.', 'confirmed' => true], $clock->now());
} catch (DomainException) { $refused = true; }
$fresh = $op->fresh();
$actual = [$proof, RegistrarAnswers::renewalProof($fresh, $domain->fresh()), $refused, $cash() - $before, $fresh->state,
    $fresh->funding['refund_authorization'] ?? null, $fresh->funding['refund'] ?? null, $fresh->policy['enom_answer'] ?? null,
    $state->writes - $writes, $invoice->fresh()->status, $domain->fresh()->provider_expires_at->format('Y-m-d')];
same([true, true, true, 0, $priorState, null, null, null, 0, 'paid', $priorExpiry], $actual,
    'A read overlapping staff resume keeps renewal proof and refuses a lagging Not processed answer without credit or another send: '.json_encode($actual));
echo "Renewal proof survives a staff resume during the read.\n";
$nativeSuccessful = true;
