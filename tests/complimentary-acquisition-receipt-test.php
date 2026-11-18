<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Credit, Gateway, Invoice, InvoiceTransaction, Role, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{Environment, Money};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts, ComplimentaryCosts, ComplimentaryInvoices, EnomTestSendLimit, EnomTestSendLimitSchema, FundsWaits, HolderPricing, HoldingLocks, NativeDomainGuard, OwnerPorts, OwnerSetup, PricingRule, Refunds, StaffOperationReview, StaffOperations, TransferInputs};
(new NativeDomainGuard(new HolderPricing($prices), $clock))->boot();
app()->instance(Clock::class, $clock); app()->instance(Refunds::class, new Refunds($clock));
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Complimentary receipts', 'permissions' => ['admin.domains.fund', 'admin.domains.view', 'admin.domains.manage', 'admin.domains.costs', 'admin.domains.operations.release']])->id]);
ComplimentaryAccounts::set($user->id, $staff, true, 'Complimentary acquisition fixture', $clock);
Carbon\Carbon::setTestNow($clock->now()); Carbon\CarbonImmutable::setTestNow($clock->now());
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$setup = new OwnerSetup($clock, new OwnerPorts(static fn ($binding) => new Management($binding, static fn () => throw new LogicException('Setup performs no registrar I/O.'))));
$checkout = static function (string $name, string $action) use ($user, $native, $tlds) {
    $cart = commerceCart($user);
    commerceAdd($cart, $native->product($tlds[0], $action), $name);
    commerceCheckout($user, $cart)->assertHasNoErrors();
    return Domain::where('domain', $name)->sole()->operations()->sole();
};
$request = Request::create('/transfer-code', 'POST', ['transfer_code' => 'SyntheticOnly']);
$request->setUserResolver(static fn () => $user);
$results = []; $expected = []; $inputFailures = []; $orders = [];
foreach (['register', 'transfer'] as $action) {
    foreach (['after_commit', 'sweep'] as $path) {
        $name = placeholderComFixtureName('comp-receipt-'.$action.'-'.str_replace('_', '-', $path).'-placeholder.com');
        $op = $checkout($name, $action);
        if ($action === 'register') { $op->update(['state' => 'held', 'safe_code' => 'nameservers_required']); }
        else { same('awaiting_code', $op->state, 'The transfer has yet to receive its code'); }
        $invoice = Invoice::findOrFail($op->invoice_id); $account = Account::where('server_id', $op->server_id)->sole();
        same(['complimentary', 'paid', 0.0, 0], [$op->funding_basis, $invoice->status, (float) $invoice->total, $invoice->transactions()->count()], 'Acquisition starts with a paid zero invoice');
        $before = $credit(); $priorState = $op->state; $priorService = Service::findOrFail($op->service_id)->status;
        $reference = 'comp-acquisition-'.$action.'-'.$path;
        $pay = static fn () => ExtensionHelper::addPayment($invoice, null, '1.00', '0.00', $reference);
        if ($path === 'sweep') {
            // Retained succeeded receipt whose event handler did not run, as after a post-commit interruption.
            InvoiceTransaction::withoutEvents($pay);
            same($before, $credit(), 'The missed receipt handler has not credited money');
            app(Refunds::class)->creditRetired($account);
        } else {
            DB::transaction(static function () use ($pay, $credit, $before): void {
                $pay(); same($before, $credit(), 'Receipt credit waits for the outer payment commit');
            });
        }
        $first = $credit() - $before;
        ExtensionHelper::addPayment($invoice->fresh(), null, '1.00', '0.00', $reference);
        app(Refunds::class)->creditRetired($account); app(Refunds::class)->creditRetired($account);
        $fresh = $op->fresh(); $key = $action.':'.$path;
        $orders[$key] = $fresh;
        $results[$key] = [$first, $credit() - $before, $invoice->transactions()->count(), $invoice->fresh()->status,
            $fresh->state, Service::findOrFail($fresh->service_id)->status, $fresh->funding_basis, (float) $invoice->fresh()->total,
            count($fresh->funding['late_credits'] ?? [])];
        $expected[$key] = [100, 100, 1, 'paid', $priorState, $priorService, 'complimentary', 0.0, 1];

        try {
            if ($action === 'register') {
                $nameservers = ['ns1.example.com', 'ns2.example.com'];
                $setup->save($user, $fresh->domain_id, ['nameservers' => $nameservers, 'privacy' => false]);
                same($nameservers, $fresh->fresh()->payload['nameservers'], 'The owner can save registration setup after the credit');
            } else {
                $deadline = TransferInputs::deadline($fresh, $clock->now());
                expect($deadline !== null, 'The issued transfer has a verified input deadline');
                $transfers->submitCode($request, $fresh->id);
                $saved = $fresh->fresh();
                same(['queued', $deadline->getTimestamp(), true], [$saved->state,
                    TransferInputs::deadline($saved, $clock->now())?->getTimestamp(), ($saved->payload['epp'] ?? null) === 'SyntheticOnly'],
                    'The owner can queue the transfer with its original input deadline');
            }
            same($before + 100, $credit(), 'Owner input leaves the exact credit in place');
        } catch (DomainException $e) {
            $inputFailures[$key] = $e->getMessage();
        }
    }
}
same($expected, $results, 'Every complimentary acquisition receipt returns once as account credit through commit or sweep: '.json_encode($results));
// A foreign credit or payment, excess coverage, or unbalanced allocation cannot prove this receipt.
$otherReceiptId = $orders['register:sweep']->funding['late_credits'][0]['payments'][0]['id'];
$foreignCredit = Credit::create(['user_id' => $staff->id, 'currency_code' => 'USD', 'amount' => '1.00']);
$transfer = $orders['transfer:after_commit'];
DB::transaction(static function () use ($transfer, $otherReceiptId, $foreignCredit, &$inputFailures): void {
    $locked = HoldingLocks::take($transfer->domain);
    $credit = $transfer->funding['late_credits'][0];
    $localId = $credit['payments'][0]['id'];
    $cases = [
        'foreign payment' => ['payments' => [['id' => $otherReceiptId, 'amount_minor' => 100]]],
        'foreign credit' => ['credit_id' => $foreignCredit->id],
        'overpayment' => ['amount' => '1.01', 'payments' => [['id' => $localId, 'amount_minor' => 101]]],
        'unbalanced' => ['amount' => '0.99'],
    ];
    foreach ($cases as $case => $change) {
        $invalid = clone $transfer;
        $invalid->funding = ['late_credits' => [[...$credit, ...$change]]];
        try {
            ComplimentaryInvoices::require($invalid, $locked);
            $inputFailures[$case] = 'Invalid receipt authorized.';
        } catch (DomainException) {}
    }
});
// Net is zero once the ledger reserves the rest, but the owner can enter a code only after that remainder settles.
Gateway::create(['extension' => 'MoneyReceipt', 'name' => 'Synthetic receipt ledger', 'type' => 'gateway', 'enabled' => true]);
$split = $checkout(placeholderComFixtureName('comp-receipt-split-placeholder.com'), 'transfer');
$invoice = Invoice::findOrFail($split->invoice_id); $reference = 'comp-transfer-split';
$before = $credit();
InvoiceTransaction::withoutEvents(static fn () => ExtensionHelper::addPayment($invoice, 'MoneyReceipt', '10.00', '0.00', $reference));
MoneyReceiptGateway::automatic($invoice, $reference, 400, 'pending');
app(Refunds::class)->creditRenewalReceipts((int) $invoice->id);
same($before + 600, $credit(), 'Sweep credits only the portion available outside the pending return');
DB::transaction(static function () use ($split, &$inputFailures): void {
    $locked = HoldingLocks::take($split->domain);
    try {
        ComplimentaryInvoices::require($locked['operations']->firstWhere('id', $split->id), $locked);
        $inputFailures['pending return'] = 'Reserved funds authorized owner input.';
    } catch (DomainException) {}
});
commerceReject(fn () => $transfers->submitCode($request, $split->id));
same('awaiting_code', $split->fresh()->state, 'A pending return leaves transfer input unchanged');
MoneyReceiptGateway::complete($invoice, $reference.':excess');
app(Refunds::class)->creditRenewalReceipts((int) $invoice->id);
try {
    $transfers->submitCode($request, $split->id);
    same('queued', $split->fresh()->state, 'Settled gateway return and account credit together permit owner input');
} catch (DomainException $e) {
    $inputFailures['settled split'] = $e->getMessage();
}
same([$before + 600, 1], [$credit(), count($split->fresh()->funding['late_credits'] ?? [])], 'Gateway settlement and replay leave the original credit in place');
same([], $inputFailures, 'Complimentary receipt authority: '.json_encode($inputFailures));
// Exercise the real TEST counter, staff release and price approval against returned zero receipts.
EnomTestSendLimitSchema::install();
config(['domains.enom_test_sends_per_day' => 1000, 'domains.enom_test_sends_per_minute' => 100000]);
EnomTestSendLimit::admit(Environment::Test, 'account-enom', 'Check');
$spent = DB::table(EnomTestSendLimitSchema::SENDS)->count();
$retryFailures = [];
foreach (['release', 'funds deadline', 'price'] as $case) {
    $op = $new(placeholderComFixtureName('comp-receipt-retry-'.str_replace(' ', '-', $case).'-placeholder.com'), false);
    $price = $case === 'price';
    config(['domains.enom_test_sends_per_day' => $price ? 1000 : $spent]);
    $preflight->during = $price
        ? static fn ($op) => ComplimentaryCosts::require($op, new Money('8.01', 'USD'), '8.00')
        : static fn () => EnomTestSendLimit::admit(Environment::Test, 'account-enom', 'Check');
    $held = $engine->execute($op->id);
    same($price ? 'registrar_price_increased' : 'enom_test_call_cap', $held->safe_code, 'The original preflight holds before purchase');
    $before = $credit();
    ExtensionHelper::addPayment($held->invoice_id, null, '1.00');
    $audit = $held->fresh()->funding['late_credits'];
    same($before + 100, $credit(), 'A stray payment is fully returned before retry: '.$case);
    if ($case === 'funds deadline') {
        $held->refresh()->update(['safe_code' => 'registrar_balance_insufficient', 'policy' => [...$held->policy, 'funds_wait_at' => $now->format(DATE_ATOM)]]);
        FundsWaits::sweep(Account::where('server_id', $held->server_id)->sole(), $now->modify('+3 days'));
        if ($held->fresh()->state !== 'rejected') {
            $retryFailures[$case] = $held->fresh()->state;
        }
        same($audit, $held->fresh()->funding['late_credits'], 'Closure preserves the receipt return audit');
        continue;
    }
    try {
        if ($price) {
            ComplimentaryCosts::approve($held->id, $staff, '8.01', 'Synthetic price approval', $clock);
            if (!ComplimentaryCosts::readmitted($held->fresh())) {
                $retryFailures[$case] = 'approved unsent claim was not readmitted';
            }
        } else {
            config(['domains.enom_writes.registration' => true, 'domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true]);
            $held->refresh();
            $stamp = StaffOperationReview::stamp($held, $held->domain);
            $ticket = StaffOperationReview::ticket($staff->id, $held->domain_id, $held->id, 'release', $stamp, $clock);
            (new StaffOperations($clock))->run($staff, $held->domain_id, $held->id, 'release', $ticket, 'Synthetic counter reset');
        }
        config(['domains.enom_test_sends_per_day' => 1000]);
        $preflight->cost = $price ? '8.01' : '8.00';
        $sends = $writer->calls;
        $sent = $engine->execute($held->id);
        $engine->execute($held->id);
        if ($writer->calls !== $sends + 1) {
            $retryFailures[$case] = $sent->safe_code;
        }
        same($audit, $sent->funding['late_credits'], 'Retry preserves the receipt return audit');
        same($before + 100, $credit(), 'Retry does not credit the payment again');
    } catch (DomainException $e) {
        $retryFailures[$case] = $e->getMessage();
    }
}
same([], $retryFailures, 'Complimentary retry authority: '.json_encode($retryFailures));
echo "Complimentary acquisition receipts return once as credit.\n"; $nativeSuccessful = true;
