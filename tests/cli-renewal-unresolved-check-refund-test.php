<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';

use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, PricingRule, RegistrarAnswers,
    StaffSettingsReview as Review, StaffSettingsWrites};

app()->instance(Clock::class, $clock);
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Unresolved renewal review', 'permissions' => [
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
$credit = static fn (): int => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$snapshot = static function ($op, $domain, $invoice) use ($credit, $state): array {
    $fresh = $op->fresh();
    return ['credit' => $credit(), 'state' => $fresh->state,
        'refund_authorization' => $fresh->funding['refund_authorization'] ?? null,
        'refund' => $fresh->funding['refund'] ?? null, 'answer' => $fresh->policy['enom_answer']['outcome'] ?? null,
        'invoice' => $invoice->fresh()->status, 'expiry' => $domain->fresh()->provider_expires_at->format('Y-m-d'),
        'receipts' => InvoiceTransaction::where('invoice_id', $invoice->id)->orderBy('id')->get()->toArray(),
        'writes' => $state->writes];
};
$failures = [];
$assertProtected = static function (string $case, array $before, array $after, bool $refused) use (&$failures): void {
    try {
        same([$before, true], [$after, $refused], $case.': Not processed must refuse without credit, refund, rejection, receipt changes or another send'
            .' (credit change='.($after['credit'] - $before['credit']).', state='.$after['state'].', refused='.json_encode($refused).')');
        echo "PASS ".$case." money and state\n";
    } catch (RuntimeException $error) {
        $failures[] = $error->getMessage();
        echo "FAIL ".$error->getMessage()."\n";
    }
};
$prepareLostReply = static function (string $name) use ($makeRenewal, $compose, $bindings, $renewBindings, $state, $clock): array {
    app()->instance(AccountBindings::class, $bindings);
    $state->fail = null; $state->duringCommand = null;
    [$op, $domain, $binding, $invoice] = $makeRenewal(placeholderComFixtureName($name));
    [$engine, $writer, $completion] = $compose($binding);
    $state->lost = true;
    same('uncertain', $engine->run($op->id, $writer)->state, 'A funded renewal loses its reply after one send');
    app()->instance(AccountBindings::class, $renewBindings);
    $clock->instant = $clock->now()->modify('+25 hours');
    $state->after = '2027-10-18';
    Cache::flush(); $completion->run($op->id);
    expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), 'The prior unproven read makes the old lost reply eligible for a staff answer');
    $clock->instant = $clock->now()->modify('+10 minutes');
    return [$op, $domain, $invoice, $completion];
};

// Case: live-check. A has not received its positive Info reply when B records a lagging answer.
[$op, $domain, $invoice, $completion] = $prepareLostReply('cli-live-check-refund-placeholder.com');
$before = $snapshot($op, $domain, $invoice); $during = null;
$state->expiry = '10/18/2028 5:00:00 AM'; $state->after = '2028-10-18';
$state->duringCommand = ReadCommand::Info;
$state->during = static function () use ($op, $domain, $invoice, $state, $record, $snapshot, &$during): void {
    $state->expiry = '10/18/2027 5:00:00 AM'; $state->after = '2027-10-18';
    $refused = $record($op);
    $during = [$snapshot($op, $domain, $invoice), $refused];
    $state->expiry = '10/18/2028 5:00:00 AM'; $state->after = '2028-10-18';
};
Cache::flush(); $completion->run($op->id);
expect($during !== null, 'The staff answer ran while the positive reply was still in flight');
$assertProtected('live-check', $before, $during[0], $during[1]);

// Case: failed-proof-save. The Expiry reply is positive, but six operation-row lock waits prevent its durable save.
[$op, $domain, $invoice, $completion] = $prepareLostReply('cli-failed-proof-refund-placeholder.com');
$before = $snapshot($op, $domain, $invoice); $remaining = 0; $conflicts = 0;
DB::connection()->beforeExecuting(static function (string $sql) use (&$remaining, &$conflicts): void {
    if ($remaining === 0 || DB::transactionLevel() !== 1 || !str_starts_with($sql, 'select')
        || !str_contains($sql, 'from "domains_operations"')) { return; }
    $remaining--; $conflicts++;
    $error = new PDOException('Synthetic operation lock wait');
    $error->errorInfo = ['HY000', 1205, 'Synthetic operation lock wait'];
    throw $error;
});
$state->after = '2028-10-18'; $state->duringCommand = ReadCommand::Expiry;
$state->during = static function () use (&$remaining): void { $remaining = 6; };
Cache::flush();
try { $completion->run($op->id); } catch (RuntimeException) { /* The unsaved check may refuse or defer. */ }
same([6, 0], [$conflicts, $remaining], 'The positive reply met all six operation-lock timeouts');
$state->duringCommand = null; $state->expiry = '10/18/2027 5:00:00 AM'; $state->after = '2027-10-18';
$clock->instant = $clock->now()->modify('+10 minutes');
Cache::flush(); $refused = $record($op);
$assertProtected('failed-proof-save', $before, $snapshot($op, $domain, $invoice), $refused);

expect($failures === [], implode("\n", $failures));
$nativeSuccessful = true;
