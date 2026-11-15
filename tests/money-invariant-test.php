<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/money-receipt.php';

use App\Models\{Credit, Gateway, Invoice, InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, InvoiceMoney, PricingRule, Refunds};

// Both gateway ports use only process-local observations. The native runner disables network I/O.
class InvariantReceiptGateway extends MoneyReceiptGateway
{
    public static array $observations = [];
    public static array $requests = []; public static array $ledger = []; public static bool $unreadable = false;
    public function allocateReceiptReturn(Invoice $invoice, string $receipt, string $key, int $minor): array
    {
        self::$requests[$key] = ['receipt'=>$receipt, 'amount'=>$minor];
        return parent::allocateReceiptReturn($invoice, $receipt, $key, $minor);
    }
    public function receiptLedger(Invoice $invoice): array
    {
        expect(DB::transactionLevel() > 0, 'Ledger reads require the caller transaction');
        if (self::$unreadable) { throw new RuntimeException('Synthetic unreadable ledger'); }
        return self::$ledger;
    }
    public function receiptReversals(Invoice $invoice, string $receipt): array
    {
        return self::$observations[$receipt] ?? [];
    }
}
class InvariantCreditGateway extends \App\Classes\Extension\Gateway
{
    public function pay(Invoice $invoice, $total) { return ''; }
    public function receiptLedger(Invoice $invoice): array { return InvariantReceiptGateway::$ledger; }
    public function receiptReversals(Invoice $invoice, string $receipt): array
    {
        return InvariantReceiptGateway::$observations[$receipt] ?? [];
    }
}
class_alias(InvariantReceiptGateway::class, 'Paymenter\\Extensions\\Gateways\\InvariantReceipt\\InvariantReceipt');
class_alias(InvariantCreditGateway::class, 'Paymenter\\Extensions\\Gateways\\InvariantCredit\\InvariantCredit');
$receiptGateway = Gateway::create(['extension'=>'InvariantReceipt', 'name'=>'Offline receipt', 'type'=>'gateway', 'enabled'=>true]);
$creditGateway = Gateway::create(['extension'=>'InvariantCredit', 'name'=>'Offline credit', 'type'=>'gateway', 'enabled'=>true]);
$staff = commerceUser();
$role = Role::create(['name'=>'Invariant refunds', 'permissions'=>['admin.domains.manage', 'admin.invoices.update']]);
$staff->update(['role_id'=>$role->id]);
$refunds = new Refunds($clock);
$balance = static fn(): int => PricingRule::minor((string) (Credit::where('user_id', $user->id)->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$projectionChecks = 0;
$failures = []; $checks = 0; $steps = 0; $cases = 0; $coverage = [];

// Each scenario has its own invoice. The oracle reads actual native payouts and the credit balance,
// never Domains' available(), retained plan amounts, or a service's returned observation.
$run = static function (int $seed, string $mode, array $actions) use (
    $new, $makeRenewal, $cycles, $clock, $now, $ports, $refunds, $staff, $balance, $receiptGateway, $creditGateway,
    &$failures, &$checks, &$steps, &$cases, &$coverage, &$projectionChecks
): void {
    $cases++;
    MoneyReceiptGateway::$returns = []; MoneyReceiptGateway::$lost = false;
    MoneyReceiptGateway::$outcome = 'refunded'; MoneyReceiptGateway::$onStatus = null;
    InvariantReceiptGateway::$observations = []; InvariantReceiptGateway::$requests = [];
    $name = 'money-invariant-'.$cases.'-placeholder.com';
    $clock->instant = $now;
    activeCommerceFacts($now, $now->modify('+30 days'), $ports);
    if ($mode === 'late') {
        [$op, , , $invoice] = $makeRenewal($name, false);
        $cycles->retireUnpaid($op->id);
    } else {
        $op = $new($name, false); $op->update(['state'=>'held', 'safe_code'=>'staff_review']);
        $invoice = Invoice::findOrFail($op->invoice_id);
        DB::table('invoices')->where('id', $invoice->id)->update(['status'=>'paid']);
    }
    $gross = PricingRule::minor($op->amount);
    $beforeCredit = $balance(); $receipts = []; $trace = []; $seen = []; $closing = false; $previousOut = 0; $promised = 0;
    $record = static function (string $rule, array $facts) use (&$failures, &$seen, &$trace, $seed, $mode): void {
        if (isset($seen[$rule])) { return; }
        $seen[$rule] = true;
        $failure = ['seed'=>$seed, 'mode'=>$mode, 'rule'=>$rule, 'sequence'=>$trace, 'facts'=>$facts];
        $failures[] = $failure;
        echo 'INVARIANT_FAIL '.json_encode($failure, JSON_THROW_ON_ERROR)."\n";
    };
    foreach ($actions as $action) {
        $steps++; $coverage[$action[0]] = ($coverage[$action[0]] ?? 0) + 1;
        // Fractions are relative to the actual issued line, not a duplicated fixture price.
        $kind = $action[0]; $trace[] = $action; $error = null;
        try {
            if ($kind === 'payment' || $kind === 'late_payment') {
                [, $label, $minor, $destination] = $action;
                $minor = $minor === 'full' ? $gross : ($minor === 'half' ? intdiv($gross, 2) : ($minor === 'rest' ? $gross-intdiv($gross, 2) : $minor));
                $gateway = $destination === 'refund' ? $receiptGateway : $creditGateway;
                $receipt = 'synthetic_receipt_'.$cases.'_'.$label;
                InvoiceTransaction::create(['invoice_id'=>$invoice->id, 'amount'=>PricingRule::decimal($minor),
                    'fee'=>'0.00', 'status'=>'succeeded', 'gateway_id'=>$gateway->id, 'transaction_id'=>$receipt]);
                $receipts[$label] = ['receipt'=>$receipt, 'amount'=>$minor, 'dispute'=>null, 'lost'=>0];
            } elseif (in_array($kind, ['open', 'lost', 'won'], true)) {
                [, $label, $minor] = $action; $r = &$receipts[$label];
                $minor = $minor === 'full' ? $r['amount'] : $minor;
                $r['dispute'] = $kind; $r['lost'] = $kind === 'lost' ? $minor : 0;
                InvariantReceiptGateway::$observations[$r['receipt']] = [[
                    'kind'=>'dispute', 'id'=>'synthetic_dispute_'.$label, 'status'=>$kind, 'needs_review'=>$kind !== 'won',
                    'invoice_id'=>(int) $invoice->id, 'user_id'=>(int) $invoice->user_id,
                    'payment_intent_id'=>$r['receipt'], 'currency'=>'usd', 'amount_minor'=>$minor, 'credited_minor'=>0,
                ]];
                unset($r);
            } elseif ($kind === 'pending') {
                MoneyReceiptGateway::$outcome = 'pending';
            } elseif ($kind === 'lose_reply') {
                MoneyReceiptGateway::$lost = true;
            } elseif ($kind === 'settle') {
                foreach (MoneyReceiptGateway::$returns as $key=>$result) {
                    if ($result['state'] === 'pending') { MoneyReceiptGateway::complete($invoice, $key); }
                }
            } elseif ($kind === 'refund') {
                $closing = true; $refunds->refundHeld($staff, $op->id, 'customer_cancelled');
            } elseif ($kind === 'credit') {
                $closing = true; $refunds->creditRejected($op->id);
            } elseif ($kind === 'retry') {
                $refunds->retryPending($op->id-1, 1);
            } else {
                throw new LogicException('Unknown invariant action');
            }
        } catch (DomainException $e) {
            if (!in_array($kind, ['refund', 'credit', 'retry'], true)) { throw $e; }
            // A deficit/refusal is allowed; the independent money and progress assertions still run.
            $error = $e::class;
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'Synthetic reply lost after acceptance') { throw $e; }
            $error = 'lost_reply';
        }
        $paid = array_sum(array_column($receipts, 'amount'));
        $lost = array_sum(array_column($receipts, 'lost'));
        $cash = 0;
        foreach (InvoiceTransaction::where('invoice_id', $invoice->id)->where('status', 'succeeded')->get() as $row) {
            if (!$row->is_credit_transaction) { $cash += max(0, -PricingRule::minor($row->amount)); }
        }
        $credit = $balance()-$beforeCredit; $out = $cash+$credit; $held = $paid-$lost-$out;
        $facts = compact('paid', 'lost', 'cash', 'credit', 'out', 'held', 'error');
        $checks += 5;
        if ($cash < 0 || $credit < 0 || $out < 0) { $record('negative_return', $facts); }
        if ($out > $paid-$lost) {
            // External loss after a completed return is an unavoidable deficit, distinct from a new payout.
            $record($kind === 'lost' ? 'external_deficit' : 'returned_exceeds_paid_less_lost', $facts);
        }
        if ($out > $previousOut && $held < 0) { $record('new_return_exceeds_available', $facts); }
        $open = (bool) array_filter($receipts, static fn($r)=>$r['dispute'] === 'open');
        $pending = (bool) array_filter(MoneyReceiptGateway::$returns, static fn($r)=>$r['state'] === 'pending');
        if ($open && $out > $previousOut) { $record('return_during_open_dispute', $facts); }
        // The projection consumes retained evidence; expected money still comes only from native payouts,
        // actual Credit changes and the fake gateway's independently captured accepted reservations.
        $native = InvoiceTransaction::where('invoice_id', $invoice->id)->orderBy('id')->get();
        $input = $native->map(fn($row)=>['id'=>(int)$row->id, 'amount'=>(string)$row->amount, 'status'=>$row->status->value,
            'gateway_id'=>(int)$row->gateway_id, 'transaction_id'=>$row->transaction_id, 'cardOk'=>$row->gateway_id === $receiptGateway->id])->all();
        $ledger = []; $reserved = 0;
        foreach ($native as $row) {
            foreach (InvariantReceiptGateway::$observations[$row->transaction_id] ?? [] as $observation) {
                $ledger[] = [...$observation, 'gateway_id'=>(int)$row->gateway_id,
                    'allocation_key'=>'stripe:dispute:'.$observation['id'], 'state'=>$observation['status']];
            }
        }
        foreach (InvariantReceiptGateway::$requests as $key=>$request) {
            $result = MoneyReceiptGateway::$returns[$key] ?? null;
            if (!$result) { continue; }
            if (!in_array($result['state'], ['refunded','credited'], true)) { $reserved += $request['amount']; }
            $ledger[] = [...$result, 'gateway_id'=>(int)$receiptGateway->id, 'payment_intent_id'=>$request['receipt'],
                'allocation_key'=>$key, 'return_key'=>$result['key']];
        }
        // A successful ordinary cancellation commits the requested full-line return before the first send.
        // This uses the scenario's issued amount, including unsent work after a lost gateway reply.
        if ($kind === 'refund' && $error !== DomainException::class) { $promised = $gross; }
        $reserved = max($reserved, max(0, $promised-$out));
        $peers = \Paymenter\Extensions\Servers\Domains\Models\Operation::where('invoice_id', $invoice->id)->orderBy('id')->get()
            ->map(fn($peer)=>['funding'=>$peer->funding ?? []])->all();
        $money = InvoiceMoney::record($input, $peers, $ledger);
        same($held-$reserved, $money['pool'], 'Projection pool mismatch '.json_encode(compact('seed','mode','trace','held','reserved','money','peers'), JSON_THROW_ON_ERROR));
        same($open ? 0 : max(0, $held-$reserved), InvoiceMoney::held($money), 'Projection held matches independent oracle');
        same($held-$reserved < 0, $money['overdrawn'], 'Projection preserves the signed deficit');
        same($held-$reserved === 0 && !$reserved && !$pending && !$open, InvoiceMoney::settled($money), 'Settled uses pool and unresolved work');
        $parts = array_map(fn($id)=>array_sum(InvoiceMoney::heldFor($money, $id)), array_keys($money['receipts']));
        same(InvoiceMoney::held($money), array_sum($parts), 'Receipt shares cover exactly the held pool');
        same($money, InvoiceMoney::record($input, $peers, $ledger), 'Pure record replay is idempotent');
        $projectionChecks += 6;
        $previousOut = $out;
        // One retry must finish synchronous fake returns once funding and the dispute are resolved.
        // Ordinary refunds owe their line; retired invoices return every incremental receipt.
        if ($kind === 'retry' && $closing && !$open && !$pending) {
            $due = $mode === 'late' ? max(0, $paid-$lost) : min($gross, max(0, $paid-$lost));
            if ($out < $due) { $record('refund_stalled', [...$facts, 'due'=>$due]); }
        }
    }
    echo 'INVARIANT_CASE '.json_encode(['seed'=>$seed, 'mode'=>$mode, 'steps'=>count($trace), 'rules'=>array_keys($seen)], JSON_THROW_ON_ERROR)."\n";
};

// Short witnesses also make the review findings reproducible without random retry noise.
$run(1, 'ordinary', [['payment','a','full','refund'], ['lost','a','full'], ['refund'], ['retry']]);
$run(2, 'ordinary', [['payment','a','half','refund'], ['payment','b','rest','refund'], ['refund'], ['retry']]);
$run(3, 'late', [['late_payment','a',200,'credit'], ['credit'], ['lost','a','full'],
    ['late_payment','b',200,'credit'], ['credit'], ['retry']]);
$run(4, 'ordinary', [['payment','a','full','credit'], ['open','a','full'], ['refund'], ['won','a','full'], ['retry']]);
$run(5, 'late', [['late_payment','a',100,'credit'], ['credit'], ['lost','a','full'],
    ['late_payment','b',300,'credit'], ['retry']]);
$run(6, 'late', [['late_payment','a',200,'credit'], ['lost','a','full'],
    ['late_payment','b',200,'credit'], ['credit'], ['retry']]);

// A local PRNG keeps sequences identical across revisions and independent of framework randomness.
// Seeded choices vary receipt splits, destinations, dispute amounts/outcomes, reply loss and retry order.
foreach ([101, 211, 307, 401, 503, 601, 701, 809, 907, 1009, 1103, 1201, 1301, 1409, 1511, 1601] as $index=>$seed) {
    $rng = $seed;
    $pick = static function (int $max) use (&$rng): int { $rng = ($rng*48271)%2147483647; return $rng%$max; };
    $destination = $pick(2) ? 'refund' : 'credit';
    $actions = [['payment','a','half',$destination], ['payment','b','rest',$pick(2) ? 'refund' : 'credit']];
    $shape = $index%4;
    if ($shape === 0) { $actions[] = ['open','a','full']; }
    if ($shape === 1) { $actions[] = ['lost','a',1+$pick(100)]; }
    if ($shape === 2) { $actions[] = ['pending']; }
    if ($pick(2)) { $actions[] = ['lose_reply']; }
    $actions[] = ['refund'];
    for ($i=0, $n=1+$pick(3); $i<$n; $i++) { $actions[] = ['retry']; }
    if ($shape === 0) { $actions[] = [$pick(2) ? 'won' : 'lost','a','full']; }
    $actions[] = ['settle']; $actions[] = ['retry']; $actions[] = ['retry'];
    $run($seed, 'ordinary', $actions);

    $amount = 100+$pick(500); $other = 100+$pick(500);
    $late = [['late_payment','a',$amount,'credit']];
    if ($index%3 === 0) { $late[] = ['credit']; }
    $late[] = ['open','a','full'];
    if ($pick(2)) { $late[] = ['credit']; }
    $late[] = [$pick(2) ? 'won' : 'lost','a','full'];
    $late[] = ['late_payment','b',$other,'credit'];
    $late[] = ['credit'];
    for ($i=0, $n=1+$pick(3); $i<$n; $i++) { $late[] = ['retry']; }
    $run($seed, 'late', $late);
}
// Focused records cover the new states without switching any production writer.
$nativeRow = static fn(int $id, int $minor, string $key, bool $card = true, string $status = 'succeeded'): array =>
    ['id'=>$id, 'amount'=>PricingRule::decimal($minor), 'transaction_id'=>$key, 'gateway_id'=>1, 'cardOk'=>$card, 'status'=>$status];
$allocation = ['transaction_id'=>10, 'gateway_id'=>1, 'receipt'=>'synthetic_a', 'key'=>'return_a', 'amount_minor'=>100];
$plan = static fn(array $allocations, int $bridge = 0): array => ['to'=>'original_payment', 'amount'=>PricingRule::decimal(100+$bridge),
    'payments'=>[['id'=>10, 'amount_minor'=>100+$bridge]], 'allocations'=>$allocations, 'bridge_minor'=>$bridge];
$observation = static fn(string $key, string $state, int $amount): array =>
    ['gateway_id'=>1, 'payment_intent_id'=>'synthetic_a', 'allocation_key'=>$key, 'state'=>$state, 'amount_minor'=>$amount];
$base = [$nativeRow(20, 200, 'synthetic_b', false), $nativeRow(10, 300, 'synthetic_a')];
$assertMoney = static function(array $rows, array $peers, array $ledger, bool $owner, int $pool, int $held, bool $settled) use (&$projectionChecks): array {
    $r = InvoiceMoney::record($rows, $peers, $ledger, $owner);
    same($pool, $r['pool'], 'Exact signed invoice pool'); same($held, InvoiceMoney::held($r), 'Exact available invoice funds');
    same($settled, InvoiceMoney::settled($r), 'Settlement uses pre-owner-hold pool and outstanding work');
    $projectionChecks += 3; return $r;
};
foreach ([false, true] as $owner) {
    $assertMoney([], [], [], $owner, 0, 0, true);
    $assertMoney($base, [], [], $owner, 500, $owner ? 0 : 500, false);
    $credit = [['funding'=>['refund'=>['to'=>'account_credit', 'amount'=>'5.00', 'payments'=>[['id'=>10,'amount_minor'=>300],['id'=>20,'amount_minor'=>200]]]]]];
    $assertMoney($base, $credit, [], $owner, 0, 0, true);
    $over = [['funding'=>['refund'=>['to'=>'account_credit', 'amount'=>'6.00', 'payments'=>[['id'=>10,'amount_minor'=>600]]]]]];
    $r = $assertMoney($base, $over, [], $owner, -100, 0, false);
    same(-300, $r['receipts'][10]['net'], 'Receipt deficits remain signed');
}
$deficitCredit = [['funding'=>['refund'=>['to'=>'account_credit','amount'=>'4.00','payments'=>[['id'=>10,'amount_minor'=>400]]]]]];
$r = $assertMoney($base, $deficitCredit, [], false, 100, 100, false);
same(['card'=>0,'credit'=>0], InvoiceMoney::heldFor($r, 10), 'Over-returned receipt gets no further share');
same(['card'=>0,'credit'=>100], InvoiceMoney::heldFor($r, 20), 'Later receipt first covers the earlier deficit');
$r = $assertMoney($base, [], [], false, 500, 500, false);
same(['card'=>300, 'credit'=>0], InvoiceMoney::heldFor($r, 10), 'Card share follows receipt identity');
same(['card'=>0, 'credit'=>200], InvoiceMoney::heldFor($r, 20), 'Unsupported share goes to credit');
foreach (['reserved', 'sending', 'pending', 'review', 'released', 'refunded', 'credited'] as $state) {
    $result = ['state'=>$state, 'amount_minor'=>100, 'key'=>'native_return', 'refund_id'=>'synthetic_refund', 'credit_minor'=>60];
    $peers = [['funding'=>['refund'=>$plan([$allocation], 50), 'receipt_return_results'=>['return_a'=>$result]]]];
    $rows = $base;
    if (in_array($state, ['refunded','credited'], true)) { $rows[] = $nativeRow(30, -100, 'native_return'); }
    $r = $assertMoney($rows, $peers, [], false, $state === 'released' ? 390 : 350, $state === 'review' ? 0 : ($state === 'released' ? 390 : 350), false);
    same($state === 'released' ? 110 : 50, $r['receipts'][10]['credit'], 'Bridge and released credit counted once');
    if (in_array($state, ['refunded','credited'], true)) {
        same($r['pool'], InvoiceMoney::record($rows, $peers, [$observation('stripe:refund:synthetic_refund', 'refunded', 100)])['pool'], 'Own refund observation is not external');
    }
}
// A gateway result is authoritative after acceptance even if Domains has no local result yet.
$peers = [['funding'=>['refund'=>$plan([$allocation])]]];
$done = [...$observation('return_a', 'refunded', 100), 'return_key'=>'native_return', 'refund_id'=>'synthetic_refund'];
$r = $assertMoney([...$base, $nativeRow(30, -100, 'native_return')], $peers, [$done, $done], false, 400, 400, false);
same(100, $r['receipts'][10]['completed'], 'Crash before local result still counts completion once');
$released = [['funding'=>['refund'=>$plan([$allocation]), 'receipt_return_results'=>['return_a'=>['state'=>'released','credit_minor'=>60]]]]];
$r = $assertMoney($base, $released, [$observation('return_a', 'pending', 100)], false, 400, 400, false);
same(0, $r['receipts'][10]['credit'], 'Existing gateway allocation prevents a released credit projection');
$r = $assertMoney([...$base, $nativeRow(30, -100, 'native_return')], [], [[...$done, 'allocation_key'=>'excess']], false, 400, 400, false);
foreach (['open', 'inquiry', 'won', 'cleared', 'lost'] as $state) {
    $lost = $state === 'lost' ? 100 : 0; $hold = in_array($state, ['open','inquiry'], true);
    $r = $assertMoney($base, [], [$observation('stripe:dispute:synthetic_dispute', $state, 100)], false, 500-$lost, $hold ? 0 : 500-$lost, false);
    same(false, $r['receipts'][10]['cardOk'], 'Every dispute observation disables original-card returns');
}
foreach (['open', 'refunded', 'failed'] as $state) {
    $loss = $state === 'refunded' ? 100 : 0;
    $assertMoney($base, [], [$observation('stripe:refund:external', $state, 100)], false, 500-$loss, $state === 'open' ? 0 : 500-$loss, false);
}
$recovery = [['funding'=>['refund'=>['to'=>'account_credit','amount'=>'3.00','payments'=>[['id'=>10,'amount_minor'=>300]]],
    'dispute_holds'=>[['payment_id'=>10,'dispute_id'=>'synthetic_dispute','removed_minor'=>100]]]]];
$r = $assertMoney($base, $recovery, [$observation('stripe:dispute:synthetic_dispute','lost',300)], false, 0, 0, true);
same(100, $r['receipts'][10]['recovered'], 'Removed credit restores only its exact receipt');
$legacy = [['funding'=>['refund'=>['to'=>'account_credit','amount'=>'1.00','payments'=>[['id'=>10,'amount_minor'=>300],['id'=>20,'amount_minor'=>200]]]]]];
$r = $assertMoney([...$base, $nativeRow(30, -100, 'unrelated')], $legacy, [], false, 300, 300, false);
same(100, $r['U'], 'Ambiguous old credit stays unattributed'); same(100, $r['F'], 'Unmatched native negative is an invoice deficit');
$assertMoney([$nativeRow(10, 0, 'pending', false, 'processing')], [], [], false, 0, 0, false);
$assertMoney([$nativeRow(10, 300, 'failed', false, 'failed')], [], [], false, 0, 0, true);
foreach ([
    static fn()=>InvoiceMoney::record($base, $peers, [$done]),
    static fn()=>InvoiceMoney::record([...$base, $nativeRow(30,-100,'wrong_key')], $peers, [$done]),
    static fn()=>InvoiceMoney::record($base, [['funding'=>['refund'=>['amount'=>'unreadable']]]], []),
] as $invalid) {
    $refused = false; try { $invalid(); } catch (DomainException) { $refused = true; }
    expect($refused, 'Unreadable completion and plan evidence fail closed');
}
// Load uses the caller's HoldingLocks, reads owner holds and treats an old gateway as credit-only.
$clock->instant = $now; activeCommerceFacts($now, $now->modify('+30 days'), $ports);
$probe = $new('money-record-load-placeholder.com', false);
$oldGateway = Gateway::create(['extension'=>'MoneyReceipt','name'=>'Legacy offline receipt','type'=>'gateway','enabled'=>true]);
InvoiceTransaction::create(['invoice_id'=>$probe->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$oldGateway->id,'transaction_id'=>'synthetic_legacy']);
InvoiceTransaction::create(['invoice_id'=>$probe->invoice_id,'amount'=>'1.50','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$receiptGateway->id,'transaction_id'=>'synthetic_readable']);
DB::transaction(static function() use ($probe): void {
    $l = HoldingLocks::take($probe->domain); $money = InvoiceMoney::load($l, (int)$probe->invoice_id);
    same(250, $money['held'], 'Legacy gateway retains credit availability without ledger reads');
    same(false, reset($money['receipts'])['cardOk'], 'No receiptLedger means no card capability');
    DB::table('domains_obligation_alerts')->insert(['alert_key'=>hash('sha256','synthetic_hold'),'domain_id'=>$probe->domain_id,
        'generation'=>1,'code'=>'account_money_hold','operation_id'=>$probe->id,'first_seen'=>1,'last_seen'=>1]);
    $held = InvoiceMoney::load($l, (int)$probe->invoice_id);
    same(0, $held['held'], 'Open owner hold zeroes load availability'); same(250, $held['pool'], 'Owner hold preserves invoice pool');
    same(false, $held['settled'], 'Retained principal on a held account is not returned');
    InvariantReceiptGateway::$unreadable = true;
    $refused = false; try { InvoiceMoney::load($l, (int)$probe->invoice_id); } catch (RuntimeException) { $refused = true; }
    InvariantReceiptGateway::$unreadable = false;
    expect($refused, 'Unreadable gateway source throws instead of authorizing money');
    InvariantReceiptGateway::$ledger = [['invoice_id'=>$probe->invoice_id,'user_id'=>$probe->domain->user_id,'currency'=>'EUR']];
    $refused = false; try { InvoiceMoney::load($l, (int)$probe->invoice_id); } catch (DomainException) { $refused = true; }
    InvariantReceiptGateway::$ledger = [];
    expect($refused, 'Gateway currency mismatch throws before projecting money');
});
echo 'PROJECTION_SUMMARY '.json_encode(['checks'=>$projectionChecks, 'cases'=>$cases, 'steps'=>$steps], JSON_THROW_ON_ERROR)."\n";
echo 'INVARIANT_SUMMARY '.json_encode(['cases'=>$cases, 'steps'=>$steps, 'checks'=>$checks,
    'failures'=>count($failures), 'coverage'=>$coverage], JSON_THROW_ON_ERROR)."\n";
expect($failures === [], 'Money invariant violations; see INVARIANT_FAIL sequences above');
$nativeSuccessful = true;
