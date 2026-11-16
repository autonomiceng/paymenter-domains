<?php
// A sent Enom renewal with an order number whose reads never prove the order; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Presentation\State;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationReconciliation, RegistrarAnswers, RegistrarCheck, StaffSettingsReview as Review, StaffSettingsWrites};
app()->instance(Clock::class, $clock); app()->instance(AccountBindings::class, $bindings);
$settings = new StaffSettingsWrites($native, $sellers, $bindings);
$staffWith = static function (array $permissions, string $name) {
    $staff = commerceUser(); $role = \App\Models\Role::create(['name' => $name, 'permissions' => $permissions]); $staff->update(['role_id' => $role->id]); return $staff;
};
$answers = ['admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer'];
$full = $staffWith([...$answers, 'admin.domains.manage', 'admin.invoices.update'], 'Renewal answers and refunds');
$answerOnly = $staffWith($answers, 'Renewal answers only');
$record = static function ($op, $staff, string $outcome, string $order = '', string $case = 'synthetic-case-1') use ($settings, $clock): ?DomainException {
    $op = $op->fresh(); $review = new Review($staff->id, $op->server_id, null, 'enom_answer', Review::ticket($staff->id, $op->server_id, null, 'enom_answer', Review::stamp($op->server_id, null)), 'Record Enom support answer');
    try { $settings->run($review, ['operation_id' => $op->id, 'attempt_hash' => RegistrarAnswers::identity($op), 'outcome' => $outcome, 'order_reference' => $order,
        'case_reference' => $case, 'evidence' => 'Enom support checked this account and renewal order.', 'confirmed' => true], $clock->now()); }
    catch (DomainException $e) { return $e; }
    return null;
};
$credit = static fn () => (string) ($user->credits()->where('currency_code', 'USD')->first()?->amount ?? '0.00');
// Enom accepted the renewal and returned its order number, but order detail never lists that order (as on Enom TEST).
$sent = static function (string $name, string $expiryAfter, ?Closure $pay = null) use ($makeRenewal, $compose, $state, $clock) {
    [$op, $d, $b, $invoice] = $makeRenewal(placeholderComFixtureName($name), $pay === null); [$engine, $writer, $completion] = $compose($b);
    if ($pay !== null) { $pay($invoice, $op); }
    same('pending', $engine->run($op->id, $writer)->state, 'Enom accepted the renewal');
    same('9002', $op->fresh()->provider_order_id, 'The renewal keeps the order number Enom returned');
    $state->order = '7777'; $state->after = $expiryAfter; $clock->instant = $clock->now()->modify('+25 hours'); Cache::flush();
    return [$op, $engine, $writer, $completion];
};
$readUntilStopped = static function ($op, $completion) use ($state, $clock): void {
    for ($i = 1; $i <= OperationReconciliation::POLL_LIMIT; $i++) {
        expect(!RegistrarAnswers::ready($op->fresh(), $clock->now()), 'No answer is offered while checks still run');
        $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
        same($i, $op->fresh()->policy['renewal_polls'] ?? 0, 'A completed read that finds no proof counts toward the read limit');
    }
    expect(!RegistrarCheck::scheduled($op->fresh()) && RegistrarCheck::stopped($op->fresh()), 'Checks stop at the read limit');
    same(State::NeedsReview, RequestFacts::state(RequestFacts::status($op->fresh())), 'Once checks stop the request no longer reads as checking');
    same('unclear', RequestFacts::answerState($op->fresh()), 'Once checks stop the Registrar reply and cost read as waiting for the support answer');
    $calls = count($state->calls); $completion->run($op->id);
    same($calls, count($state->calls), 'A check after the limit reads nothing more');
};

// 1. Not processed: the domain is unchanged, so the payment comes back once as account credit.
[$op, $engine, $writer, $completion] = $sent('renew-unproven-placeholder.com', '2027-10-18');
$readUntilStopped($op, $completion);
expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), 'Once checks stop, staff can record the answer for the renewal with its order number');
$before = $credit(); $writes = $state->writes;
expect($record($op, $answerOnly, 'not_processed') !== null, 'Not processed needs refund permission');
same([null, $before], [$op->fresh()->funding['refund_authorization'] ?? null, $credit()], 'A refused answer moves no money');
expect($record($op, $full, 'accepted', '9003') !== null, 'Processed must name the saved order number');
expect($record($op, $full, 'not_processed', '', '') !== null, 'Not processed needs the support case number');
same([null, $before], [$op->fresh()->funding['refund_authorization'] ?? null, $credit()], 'A refused answer moves no money');
same(null, $record($op, $full, 'not_processed'), 'Not processed is recorded');
$op = $op->fresh();
same(['rejected', 'refund_approved', 'enom_not_processed'], [$op->state, $op->safe_code, $op->funding['refund_authorization']['reason'] ?? null], 'Staff authorize the refund');
expect(isset($op->funding['refund']), 'The existing refund path runs');
same(number_format((float) $before + (float) $op->amount, 2, '.', ''), number_format((float) $credit(), 2, '.', ''), 'The payment comes back once as account credit');
same('9002', $op->policy['enom_answer']['unproven_order'] ?? null, 'The answer keeps the order number Enom returned');
$after = $credit();
expect($record($op, $full, 'not_processed') !== null, 'A second answer is refused');
$engine->run($op->id, $writer); $completion->run($op->id);
same([$after, $writes, 'rejected'], [$credit(), $state->writes, $op->fresh()->state], 'No second refund, no second renewal');

// 2. The domain's expiry moved, so Not processed is refused; Processed keeps the order and the renewal completes when Enom shows it.
$state->order = '9002';
[$op, $engine, $writer, $completion] = $sent('renew-unproven-moved-placeholder.com', '2028-10-18');
$readUntilStopped($op, $completion);
$before = $credit(); $writes = $state->writes;
expect($record($op, $full, 'not_processed') !== null, 'Not processed is refused when the last read saw a new expiry');
same(null, $record($op, $full, 'accepted', '9002'), 'Processed is recorded with the saved order number');
$op = $op->fresh();
same(['9002', 0, $before], [$op->provider_order_id, $op->policy['renewal_polls'] ?? null, $credit()], 'Processed keeps the order, restarts checks and moves no money');
expect(!isset($op->funding['refund_authorization']), 'Processed authorizes no refund');
expect($record($op, $full, 'not_processed') !== null && $record($op, $full, 'accepted', '9002') !== null, 'A second answer after Processed is refused');
$state->order = '9002'; $state->expiry = '2028-10-18'; $state->charge = '8.00';
same('succeeded', $completion->run($op->id)->state, 'The renewal completes through the existing read once Enom shows the order');
$engine->run($op->id, $writer);
same([$writes, $before], [$state->writes, $credit()], 'No second renewal and no refund');

// 3. Paid by a gateway that can refund the card: Not processed still returns the payment as account credit, once.
require_once __DIR__.'/Support/money-receipt.php'; MoneyReceiptGateway::$lost = false; MoneyReceiptGateway::$sends = 0;
\App\Models\Gateway::create(['extension' => 'MoneyReceipt', 'name' => 'Money receipt fixture', 'type' => 'gateway', 'enabled' => true]);
$byCard = static fn ($invoice, $op) => \App\Helpers\ExtensionHelper::addPayment($invoice, 'MoneyReceipt', $op->amount, null, 'receipt_unproven');
[$op, $engine, $writer, $completion] = $sent('renew-unproven-card-placeholder.com', '2027-10-18', $byCard);
$readUntilStopped($op, $completion);
$before = $credit(); $writes = $state->writes;
same(null, $record($op, $full, 'not_processed'), 'Not processed is recorded for the card payment');
$op = $op->fresh();
same(['rejected', 'account_credit', 0], [$op->state, $op->funding['refund']['to'] ?? null, MoneyReceiptGateway::$sends], 'The payment goes to account credit, not back to the card');
same(number_format((float) $before + (float) $op->amount, 2, '.', ''), number_format((float) $credit(), 2, '.', ''), 'The card payment comes back once as account credit');
$after = $credit(); app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class)->creditRejected($op->id); $engine->run($op->id, $writer);
same([$after, 0, $writes], [$credit(), MoneyReceiptGateway::$sends, $state->writes], 'No second credit, no card refund, no second renewal');
echo "A renewal with an order number stops its checks at the limit and each answer moves money once.\n"; $nativeSuccessful = true;
