<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';

use App\Models\{InvoiceTransaction, Role, User};
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, MoneyWorkSchema, OperationReconciliation,
    PricingRule, RegistrarAnswers, StaffSettingsReview as Review, StaffSettingsWrites};

app()->instance(Clock::class, $clock);
MoneyWorkSchema::install();
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Renewal takeover review', 'permissions' => [
    'admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer',
    'admin.domains.manage', 'admin.invoices.update',
]])->id]);
$settings = new StaffSettingsWrites($native, $sellers, $renewBindings);
$credit = static fn (): int => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));

// Case: expiry proof survives a deferred save and an expired claim's lagging replacement.
app()->instance(AccountBindings::class, $bindings);
[$op, $domain, $binding, $invoice] = $makeRenewal(placeholderComFixtureName('cli-renewal-proof-takeover-placeholder.com'));
[$engine, $writer, $completion] = $compose($binding);
same('pending', $engine->run($op->id, $writer)->state, 'The funded renewal was sent once');
app()->instance(AccountBindings::class, $renewBindings);
$before = $credit(); $writes = $state->writes; $priorState = $op->fresh()->state;
$receipts = InvoiceTransaction::where('invoice_id', $invoice->id)->orderBy('id')->get()->toArray();
$state->after = '2027-10-18'; $state->fail = ReadCommand::Order;
$clock->instant = $clock->now()->modify('+25 hours');
for ($i = 0; $i < OperationReconciliation::POLL_LIMIT - 1; $i++) {
    Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
}
same(OperationReconciliation::POLL_LIMIT - 1, $op->fresh()->policy['renewal_polls'], 'Four unproven checks leave one check available');

// Inject lock waits only at the native owner lock, after the registrar has supplied the later expiry.
// Evidence stored separately from the money locks remains free to commit.
$failures = 0; $conflicts = 0;
User::retrieved(static function (User $owner) use ($user, &$failures, &$conflicts): void {
    if ($owner->id !== $user->id || DB::transactionLevel() !== 1 || $failures === 0) { return; }
    $failures--; $conflicts++;
    $cause = new PDOException('Synthetic owner lock wait'); $cause->errorInfo = ['HY000', 1205, 'Synthetic'];
    throw new QueryException('sqlite', 'synthetic owner lock', [], $cause);
});
$state->expiry = '10/18/2028 5:00:00 AM'; $state->duringCommand = ReadCommand::Expiry;
$state->during = static function () use (&$failures): void { $failures = 2; };
Cache::flush(); $completion->run($op->id);
same([2, 0, OperationReconciliation::POLL_LIMIT - 1], [$conflicts, $failures, $op->fresh()->policy['renewal_polls']],
    'Both save attempts meet contention and leave the last poll available');

// The first worker has returned within its deadline. Later, its claim expires and a new check sees only stale expiry.
$clock->instant = $clock->now()->modify('+10 minutes');
$state->expiry = '10/18/2027 5:00:00 AM'; $state->duringCommand = null;
Cache::flush(); $completion->run($op->id);
same(OperationReconciliation::POLL_LIMIT, $op->fresh()->policy['renewal_polls'], 'The replacement saves the fifth unproven check');

// A fresh staff answer also gets lagging Info and Expiry replies, so only retained positive evidence protects the payment.
$state->fail = null; $fresh = $op->fresh(); $refused = false;
$review = new Review($staff->id, $fresh->server_id, null, 'enom_answer',
    Review::ticket($staff->id, $fresh->server_id, null, 'enom_answer', Review::stamp($fresh->server_id, null)), 'Record renewal answer');
try {
    $settings->run($review, ['operation_id' => $fresh->id, 'attempt_hash' => RegistrarAnswers::identity($fresh),
        'outcome' => 'not_processed', 'order_reference' => '', 'case_reference' => '',
        'evidence' => 'Registrar support checked the exact account and renewal attempt.', 'confirmed' => true], $clock->now());
} catch (DomainException) { $refused = true; }
$fresh = $op->fresh();
$actual = [$credit() - $before, $fresh->state, $fresh->funding['refund_authorization'] ?? null,
    $fresh->funding['refund'] ?? null, $fresh->policy['enom_answer'] ?? null, $refused,
    $state->writes - $writes, $invoice->fresh()->status,
    InvoiceTransaction::where('invoice_id', $invoice->id)->orderBy('id')->get()->toArray() === $receipts];
same([0, $priorState, null, null, null, true, 0, 'paid', true], $actual,
    'Positive expiry evidence survives contention and claim takeover: no credit, refund, rejection or repeat send: '.json_encode($actual));
echo "Renewal proof survives contention and claim takeover.\n";
$nativeSuccessful = true;
