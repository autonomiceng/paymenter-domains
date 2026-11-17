<?php
// A sent Enom renewal with an order number that no read proves; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Presentation\State;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationReconciliation, RegistrarAnswers, RegistrarCheck, StaffSettingsReview as Review, StaffSettingsWrites};
app()->instance(Clock::class, $clock); app()->instance(AccountBindings::class, $bindings);
$settings = new StaffSettingsWrites($native, $sellers, $bindings);
$staffWith = static function (array $permissions, string $name) {
    $staff = commerceUser(); $role = \App\Models\Role::create(['name' => $name, 'permissions' => $permissions]); $staff->update(['role_id' => $role->id]); return $staff;
};
$answers = ['admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer'];
$full = $staffWith([...$answers, 'admin.domains.manage', 'admin.invoices.update'], 'Renewal answers and refunds');
$answerOnly = $staffWith($answers, 'Renewal answers only');
$recordWith = static function ($op, $staff, string $outcome, string $order = '', string $case = '') use ($settings, $clock): ?DomainException {
    $op = $op->fresh(); $review = new Review($staff->id, $op->server_id, null, 'enom_answer', Review::ticket($staff->id, $op->server_id, null, 'enom_answer', Review::stamp($op->server_id, null)), 'Record Enom support answer');
    try { $settings->run($review, ['operation_id' => $op->id, 'attempt_hash' => RegistrarAnswers::identity($op), 'outcome' => $outcome, 'order_reference' => $order,
        'case_reference' => $case, 'evidence' => 'Enom support checked this account and renewal order.', 'confirmed' => true], $clock->now()); }
    catch (DomainException $e) { return $e; }
    return null;
};
$record = static function ($op, $staff, string $outcome, string $order = '', string $case = '') use ($recordWith, $bindings, $renewBindings): ?DomainException {
    // The answer's fresh registrar read uses the renewal registry, as in production.
    app()->instance(AccountBindings::class, $renewBindings);
    try { return $recordWith($op, $staff, $outcome, $order, $case); } finally { app()->instance(AccountBindings::class, $bindings); }
};
$credit = static fn () => (string) ($user->credits()->where('currency_code', 'USD')->first()?->amount ?? '0.00');
// Enom accepted the renewal and returned order 9002. Each read then answers in the shape $shape sets up.
$shapes = [
    'another order' => static function ($s) { $s->order = '7777'; },
    'failed order read' => static function ($s) { $s->fail = ReadCommand::Order; },
    'failed domain read' => static function ($s) { $s->fail = ReadCommand::Info; },
    'missing detail' => static function ($s) { $s->order = '9002'; $s->detail = ''; },
    'unreadable detail' => static function ($s) { $s->order = '9002'; $s->detail = '<OrderDetail>unreadable</OrderDetail>'; },
    'charge in another currency' => static function ($s) { $s->order = '9002'; $s->currency = 'EUR'; },
    'matching charge' => static function ($s) { $s->order = '9002'; },
    // The domain info read shows the renewed expiry; the expiry read then fails or still shows the old date.
    'later info, failed expiry read' => static function ($s) { $s->order = '7777'; $s->expiry = '10/18/2028 5:00:00 AM'; $s->fail = ReadCommand::Expiry; },
    'later info, old expiry read' => static function ($s) { $s->order = '7777'; $s->expiry = '10/18/2028 5:00:00 AM'; },
];
$reset = static function () use ($state): void { $state->order = '9002'; $state->fail = null; unset($state->detail, $state->currency); };
$sent = static function (string $name, string $expiryAfter, string $shape, ?Closure $pay = null) use ($makeRenewal, $compose, $state, $clock, $shapes, $reset) {
    $reset(); [$op, $d, $b, $invoice] = $makeRenewal(placeholderComFixtureName($name), $pay === null); [$engine, $writer, $completion] = $compose($b);
    if ($pay !== null) { $pay($invoice, $op); }
    same('pending', $engine->run($op->id, $writer)->state, 'Enom accepted the renewal');
    same('9002', $op->fresh()->provider_order_id, 'The renewal keeps the order number Enom returned');
    $state->after = $expiryAfter; ($shapes[$shape])($state); $clock->instant = $clock->now()->modify('+25 hours'); Cache::flush();
    return [$op, $engine, $writer, $completion];
};
$readUntilStopped = static function ($op, $completion, string $shape) use ($state, $clock): void {
    for ($i = 1; $i <= OperationReconciliation::POLL_LIMIT; $i++) {
        expect(!RegistrarAnswers::ready($op->fresh(), $clock->now()), "{$shape}: no answer is offered while checks still run");
        $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
        same($i, $op->fresh()->policy['renewal_polls'] ?? 0, "{$shape}: every completed check counts toward the limit");
    }
    expect(!RegistrarCheck::scheduled($op->fresh()) && RegistrarCheck::stopped($op->fresh()), "{$shape}: checks stop at the limit");
    same(State::NeedsReview, RequestFacts::state(RequestFacts::status($op->fresh())), "{$shape}: once checks stop the request no longer reads as checking");
    expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), "{$shape}: once checks stop, Record answer appears");
    $calls = count($state->calls); $completion->run($op->id);
    same($calls, count($state->calls), "{$shape}: a check after the limit reads nothing more");
};

// 1. Checks stop at the limit whatever the reply looks like, and Record answer appears.
foreach (array_keys($shapes) as $n => $shape) {
    [$op, , , $completion] = $sent("renew-shape-{$n}-placeholder.com", '2027-10-18', $shape);
    $readUntilStopped($op, $completion, $shape);
}

// 2. Not processed returns the payment once as account credit. No case number is needed.
[$op, $engine, $writer, $completion] = $sent('renew-unproven-placeholder.com', '2027-10-18', 'failed order read');
$readUntilStopped($op, $completion, 'credit once');
$before = $credit(); $writes = $state->writes;
expect($record($op, $answerOnly, 'not_processed') !== null, 'Not processed needs refund permission');
same([null, $before], [$op->fresh()->funding['refund_authorization'] ?? null, $credit()], 'A refused answer moves no money');
expect($record($op, $full, 'accepted', '9003') !== null, 'Processed must name the saved order number');
same(null, $record($op, $full, 'not_processed')?->getMessage(), 'Not processed is recorded');
$op = $op->fresh();
same(['rejected', 'refund_approved', 'enom_not_processed'], [$op->state, $op->safe_code, $op->funding['refund_authorization']['reason'] ?? null], 'Staff authorize the refund');
same(number_format((float) $before + (float) $op->amount, 2, '.', ''), number_format((float) $credit(), 2, '.', ''), 'The payment comes back once as account credit');
same('9002', $op->policy['enom_answer']['unproven_order'] ?? null, 'The answer keeps the order number Enom returned');
$after = $credit();
expect($record($op, $full, 'not_processed') !== null, 'A second answer is refused');
app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class)->creditRejected($op->id); $engine->run($op->id, $writer); $completion->run($op->id);
same([$after, $writes, 'rejected'], [$credit(), $state->writes, $op->fresh()->state], 'No second credit, no second renewal');

// 3. Not processed is refused once a check saw a matching charge or an advanced expiry.
// Every case is checked before one assert, so a failure names each case that was not refused.
$refused = static function ($op) use ($record, $full, $credit): bool {
    $before = $credit(); $error = $record($op, $full, 'not_processed'); $op = $op->fresh();
    return $error !== null && [null, null, $before] === [$op->funding['refund_authorization'] ?? null, $op->policy['enom_answer'] ?? null, $credit()];
};
$seen = [];
// A check that returns after its deadline still keeps the advanced expiry it saw; later checks see the old date.
[$late, , , $completion] = $sent('renew-proof-late-placeholder.com', '2028-10-18', 'another order');
$state->duringCommand = ReadCommand::Order; $state->during = static function () use ($clock) { $clock->instant = $clock->now()->modify('+300 seconds'); };
$completion->run($late->id); $state->duringCommand = null; $state->after = '2027-10-18';
same(1, $late->fresh()->policy['renewal_polls'] ?? 0, 'The late check counts');
for ($i = 2; $i <= OperationReconciliation::POLL_LIMIT; $i++) { $clock->instant = $clock->now()->modify('+10 minutes'); $completion->run($late->id); }
expect(RegistrarAnswers::ready($late->fresh(), $clock->now()), 'Checks stopped after the late check');
$seen['an advanced expiry in a late check'] = $refused($late);
// The info read shows the renewed expiry, then the next read throws an unexpected error; that check counts and keeps the expiry.
[$thrown, , , $completion] = $sent('renew-proof-thrown-placeholder.com', '2027-10-18', 'later info, old expiry read');
$limiter = new class implements Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter {
    public bool $armed = false;
    public function acquire($binding, $origin): string { if ($this->armed) { $this->armed = false; throw new LogicException('Unexpected read failure.'); } return 'read'; }
    public function release($binding, $origin, string $token): void {}
};
app()->bind(Paymenter\Extensions\Servers\Domains\Services\Runtime::class, static fn () => throw new LogicException('Runtime is not resolved here.'));
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class, $limiter);
$state->duringCommand = ReadCommand::Renewal; $state->during = static function () use ($limiter) { $limiter->armed = true; };
$completion->run($thrown->id); $state->duringCommand = null;
app()->offsetUnset(Paymenter\Extensions\Servers\Domains\Services\Runtime::class); app()->offsetUnset(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class);
same([1, false], [$thrown->fresh()->policy['renewal_polls'] ?? 0, $limiter->armed], 'The check that threw after the info read counts');
$state->expiry = '10/18/2027 5:00:00 AM';
for ($i = 2; $i <= OperationReconciliation::POLL_LIMIT; $i++) { $clock->instant = $clock->now()->modify('+10 minutes'); $completion->run($thrown->id); }
expect(RegistrarAnswers::ready($thrown->fresh(), $clock->now()), 'Checks stopped after the check that threw');
$seen['an advanced expiry before a read that throws'] = $refused($thrown);
// The second domain info read of one check shows the renewed expiry; the first info read and the expiry read show the old date.
[$second, , , $completion] = $sent('renew-proof-second-info-placeholder.com', '2027-10-18', 'another order');
$state->duringCommand = ReadCommand::Renewal; $state->during = static function () use ($state) { $state->expiry = '10/18/2028 5:00:00 AM'; };
$completion->run($second->id); $state->duringCommand = null; $state->during = null; $state->expiry = '10/18/2027 5:00:00 AM';
for ($i = 2; $i <= OperationReconciliation::POLL_LIMIT; $i++) { $clock->instant = $clock->now()->modify('+10 minutes'); $completion->run($second->id); }
expect(RegistrarAnswers::ready($second->fresh(), $clock->now()), 'Checks stopped after the second info read');
$seen['an advanced expiry in a second info read'] = $refused($second);
// The info read shows the renewed expiry, then our read allowance defers the rest of the check; it does not count.
[$deferred, , , $completion] = $sent('renew-proof-deferred-placeholder.com', '2027-10-18', 'later info, old expiry read');
$deferring = new class implements Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter {
    public bool $armed = false;
    public function acquire($binding, $origin): string { if ($this->armed) { $this->armed = false; throw new Paymenter\Extensions\Servers\Domains\Services\ReadDeferred('Synthetic read allowance.'); } return 'read'; }
    public function release($binding, $origin, string $token): void {}
};
app()->bind(Paymenter\Extensions\Servers\Domains\Services\Runtime::class, static fn () => throw new LogicException('Runtime is not resolved here.'));
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class, $deferring);
$state->duringCommand = ReadCommand::Renewal; $state->during = static function () use ($deferring) { $deferring->armed = true; };
$completion->run($deferred->id); $state->duringCommand = null;
app()->offsetUnset(Paymenter\Extensions\Servers\Domains\Services\Runtime::class); app()->offsetUnset(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class);
same([0, false], [$deferred->fresh()->policy['renewal_polls'] ?? 0, $deferring->armed], 'The deferred check does not count');
$state->expiry = '10/18/2027 5:00:00 AM';
for ($i = 1; $i <= OperationReconciliation::POLL_LIMIT; $i++) { $clock->instant = $clock->now()->modify('+10 minutes'); $completion->run($deferred->id); }
expect(RegistrarAnswers::ready($deferred->fresh(), $clock->now()), 'Checks stopped after the deferred check');
$seen['an advanced expiry before a deferred read'] = $refused($deferred);
// Every check saw the old date; the fresh read at answer time shows the renewed expiry, or fails.
foreach (['a fresh expiry read showing the renewal' => static function ($s) { $s->after = '2028-10-18'; },
    'a fresh info read showing the renewal' => static function ($s) { $s->expiry = '10/18/2028 5:00:00 AM'; },
    'a failed fresh read' => static function ($s) { $s->fail = ReadCommand::Info; }] as $why => $answerTime) {
    [$op, , , $completion] = $sent('renew-proof-'.substr(md5($why), 0, 8).'-placeholder.com', '2027-10-18', 'another order');
    $readUntilStopped($op, $completion, $why); $answerTime($state);
    $seen[$why] = $refused($op); $state->after = '2027-10-18'; $state->expiry = '10/18/2027 5:00:00 AM'; $state->fail = null;
}
foreach (['a matching charge' => ['matching charge', '2027-10-18'], 'an advanced expiry' => ['failed order read', '2028-10-18'],
    'an advanced expiry before a failed expiry read' => ['later info, failed expiry read', '2027-10-18'],
    'an advanced expiry before an old expiry read' => ['later info, old expiry read', '2027-10-18']] as $why => [$shape, $expiry]) {
    [$op, , , $completion] = $sent('renew-proof-'.substr(md5($why), 0, 8).'-placeholder.com', $expiry, $shape);
    $readUntilStopped($op, $completion, $why); $seen[$why] = $refused($op);
}
same(array_fill_keys(array_keys($seen), true), $seen, 'Not processed is refused and moves no money after a check saw proof: '.json_encode($seen));
// 4. Processed still needs real proof: it restarts checks, and only a read with the charge and the new expiry completes it.
$writes = $state->writes; $before = $credit();
same(null, $record($op, $full, 'accepted', '9002'), 'Processed is recorded with the saved order number');
$op = $op->fresh();
same(['9002', 0, $before], [$op->provider_order_id, $op->policy['renewal_polls'] ?? null, $credit()], 'Processed keeps the order, restarts checks and moves no money');
expect(!isset($op->funding['refund_authorization']), 'Processed authorizes no refund');
expect($record($op, $full, 'not_processed') !== null && $record($op, $full, 'accepted', '9002') !== null, 'A second answer after Processed is refused');
expect($completion->run($op->id)->state !== 'succeeded', 'A read without the charge does not complete it');
$state->fail = null; $state->order = '9002'; $state->expiry = '2028-10-18'; $state->after = '2028-10-18'; $state->charge = '8.00';
same('succeeded', $completion->run($op->id)->state, 'The renewal completes through the existing read once Enom shows the order');
same([$writes, $before], [$state->writes, $credit()], 'No second renewal and no refund');

// 5. Paid by a gateway that can refund the card: Not processed still returns the payment as account credit, once.
require_once __DIR__.'/Support/money-receipt.php'; MoneyReceiptGateway::$lost = false; MoneyReceiptGateway::$sends = 0;
\App\Models\Gateway::create(['extension' => 'MoneyReceipt', 'name' => 'Money receipt fixture', 'type' => 'gateway', 'enabled' => true]);
$byCard = static fn ($invoice, $op) => \App\Helpers\ExtensionHelper::addPayment($invoice, 'MoneyReceipt', $op->amount, null, 'receipt_unproven');
[$op, $engine, $writer, $completion] = $sent('renew-unproven-card-placeholder.com', '2027-10-18', 'another order', $byCard);
$readUntilStopped($op, $completion, 'card');
$before = $credit(); $writes = $state->writes;
same(null, $record($op, $full, 'not_processed'), 'Not processed is recorded for the card payment');
$op = $op->fresh();
same(['rejected', 'account_credit', 0], [$op->state, $op->funding['refund']['to'] ?? null, MoneyReceiptGateway::$sends], 'The payment goes to account credit, not back to the card');
same(number_format((float) $before + (float) $op->amount, 2, '.', ''), number_format((float) $credit(), 2, '.', ''), 'The card payment comes back once as account credit');
$after = $credit(); app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class)->creditRejected($op->id); $engine->run($op->id, $writer);
same([$after, 0, $writes], [$credit(), MoneyReceiptGateway::$sends, $state->writes], 'No second credit, no card refund, no second renewal');
echo "A renewal with an order number stops its checks at the limit and each answer moves money once.\n"; $nativeSuccessful = true;
