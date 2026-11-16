<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/money-receipt.php';

use App\Models\{Credit, Gateway, Invoice, InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{FundsWaits, HoldingLocks, InvoiceMoney, PricingRule, Refunds};

// Both gateway ports use only process-local observations. The native runner disables network I/O.
class InvariantReceiptGateway extends MoneyReceiptGateway
{
    public static array $observations = [];
    public static array $requests = []; public static array $ledger = []; public static bool $unreadable = false;
    public static int $conflicts = 0; public static array $lostRows = [];
    public function lostDisputes(int $afterAt,string $afterKey,int $limit):array
    {
        same(0,DB::transactionLevel(),'Discovery runs outside money transactions');
        return array_slice(array_values(array_filter(self::$lostRows,fn($e)=>$e['updated_at']>$afterAt||$e['updated_at']===$afterAt&&strcmp($e['payment_intent_id'].':'.$e['allocation_key'],$afterKey)>0)),0,$limit);
    }
    public function allocateReceiptReturn(Invoice $invoice, string $receipt, string $key, int $minor): array
    {
        self::$requests[$key] = ['receipt'=>$receipt, 'amount'=>$minor];
        return parent::allocateReceiptReturn($invoice, $receipt, $key, $minor);
    }
    public function receiptLedger(Invoice $invoice, string $extension = 'InvariantReceipt'): array
    {
        expect(DB::transactionLevel() > 0, 'Ledger reads require the caller transaction');
        if(self::$conflicts>0){self::$conflicts--; $e=new PDOException('Synthetic contention');$e->errorInfo=['40001',1213,'Synthetic'];throw $e;}
        if (self::$unreadable) { throw new RuntimeException('Synthetic unreadable ledger'); }
        $ledger = self::$ledger;
        foreach (self::$observations as $receipt=>$observations) {
            foreach ($observations as $o) {
                if ($o['invoice_id'] === (int)$invoice->id && InvoiceTransaction::where('invoice_id',$invoice->id)
                    ->where('transaction_id',$receipt)->first()?->gateway?->extension === $extension) {
                    $ledger[] = [...$o, 'allocation_key'=>'stripe:'.$o['kind'].':'.$o['id'], 'state'=>$o['status']];
                }
            }
        }
        return $ledger;
    }
    public function receiptReversals(Invoice $invoice, string $receipt): array
    {
        return self::$observations[$receipt] ?? [];
    }
}
class InvariantCreditGateway extends \App\Classes\Extension\Gateway
{
    public function pay(Invoice $invoice, $total) { return ''; }
    public function receiptLedger(Invoice $invoice): array { return (new InvariantReceiptGateway)->receiptLedger($invoice, 'InvariantCredit'); }
    public function receiptReversals(Invoice $invoice, string $receipt): array
    {
        return InvariantReceiptGateway::$observations[$receipt] ?? [];
    }
}
class InvariantLegacyGateway extends \App\Classes\Extension\Gateway
{
    public function pay(Invoice $invoice, $total) { return ''; }
    public function allocateReceiptReturn(Invoice $invoice, string $receipt, string $key, int $minor): array { return []; }
    public function receiptReturnStatus(Invoice $invoice, string $receipt, string $key): ?array { return null; }
}
class_alias(InvariantLegacyGateway::class, 'Paymenter\\Extensions\\Gateways\\InvariantLegacy\\InvariantLegacy');
class_alias(InvariantReceiptGateway::class, 'Paymenter\\Extensions\\Gateways\\InvariantReceipt\\InvariantReceipt');
class_alias(InvariantCreditGateway::class, 'Paymenter\\Extensions\\Gateways\\InvariantCredit\\InvariantCredit');
$receiptGateway = Gateway::create(['extension'=>'InvariantReceipt', 'name'=>'Offline receipt', 'type'=>'gateway', 'enabled'=>true]);
$creditGateway = Gateway::create(['extension'=>'InvariantCredit', 'name'=>'Offline credit', 'type'=>'gateway', 'enabled'=>true]);
$staff = commerceUser();
$role = Role::create(['name'=>'Invariant refunds', 'permissions'=>['admin.domains.manage', 'admin.invoices.update']]);
$staff->update(['role_id'=>$role->id]);
$refunds = new Refunds($clock);
$balance = static fn(): int => PricingRule::minor((string) (Credit::where('user_id', $user->id)->where('currency_code', 'USD')->value('amount') ?? '0.00'));
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Reminders::class,new \Paymenter\Extensions\Servers\Domains\Services\Reminders($clock,$bindings));
$projectionChecks = 0; $projectionFailures = [];
$assertProjection = static function($expected, $actual, string $message) use (&$projectionFailures): void {
    if ($expected !== $actual) {
        $projectionFailures[] = $message;
        echo 'PROJECTION_FAIL '.json_encode(compact('expected','actual','message'), JSON_THROW_ON_ERROR)."\n";
    }
};
$failures = []; $checks = 0; $steps = 0; $cases = 0; $coverage = [];

// Each scenario has its own invoice. The oracle reads actual native payouts and the credit balance,
// never Domains' available(), retained plan amounts, or a service's returned observation.
$run = static function (int $seed, string $mode, array $actions) use (
    $new, $makeRenewal, $cycles, $clock, $now, $ports, $refunds, $staff, $balance, $receiptGateway, $creditGateway,
    &$failures, &$checks, &$steps, &$cases, &$coverage, &$projectionChecks, $assertProjection
): void {
    $cases++;
    MoneyReceiptGateway::$returns = []; MoneyReceiptGateway::$lost = false;
    MoneyReceiptGateway::$outcome = 'refunded'; MoneyReceiptGateway::$onStatus = null;
    InvariantReceiptGateway::$observations = []; InvariantReceiptGateway::$requests = [];
    $name = 'money-invariant-'.$cases.'-placeholder.com';
    $clock->instant = $now;
    activeCommerceFacts($now, $now->modify('+30 days'), $ports);
    if ($mode !== 'ordinary') {
        [$op, , , $invoice] = $makeRenewal($name, false);
        if ($mode === 'late') { $cycles->retireUnpaid($op->id); }
        else { DB::table('invoices')->where('id', $invoice->id)->update(['status'=>'paid']); }
    } else {
        $op = $new($name, false); $op->update(['state'=>'held', 'safe_code'=>'staff_review']);
        $invoice = Invoice::findOrFail($op->invoice_id);
        DB::table('invoices')->where('id', $invoice->id)->update(['status'=>'paid']);
    }
    $gross = PricingRule::minor($op->amount);
    $beforeCredit = $balance(); $receipts = []; $trace = []; $seen = []; $closing = false; $previousOut = 0; $promised = 0; $planCrashed = false;
    $record = static function (string $rule, array $facts) use (&$failures, &$seen, &$trace, $seed, $mode): void {
        if (isset($seen[$rule][$facts['out']])) { return; }
        $seen[$rule][$facts['out']] = true;
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
            } elseif (in_array($kind, ['open', 'lost', 'won', 'external_refund'], true)) {
                [, $label, $minor] = $action; $r = &$receipts[$label];
                $minor = $minor === 'full' ? $r['amount'] : $minor;
                $r['dispute'] = $kind === 'external_refund' ? null : $kind; $r['lost'] = in_array($kind, ['lost','external_refund'], true) ? $minor : 0;
                InvariantReceiptGateway::$observations[$r['receipt']] = [[
                    'kind'=>$kind === 'external_refund' ? 'refund' : 'dispute', 'id'=>'synthetic_dispute_'.$cases.'_'.$label, 'status'=>$kind === 'external_refund' ? 'refunded' : $kind, 'needs_review'=>$kind !== 'won',
                    'invoice_id'=>(int) $invoice->id, 'user_id'=>(int) $invoice->user_id,
                    'payment_intent_id'=>$r['receipt'], 'currency'=>'usd', 'amount_minor'=>$minor, 'credited_minor'=>0,
                ]];
                unset($r);
                if($kind==='lost'){
                    $e=InvariantReceiptGateway::$observations[$receipts[$label]['receipt']][0];
                    (new \Paymenter\Extensions\Servers\Domains\Services\DisputeRecoveries($clock))->recover([...$e,'allocation_key'=>'stripe:dispute:'.$e['id'],'state'=>'lost']);
                    $promised=0;
                }
            } elseif ($kind === 'close_renewal') {
                DB::transaction(static function() use ($op, $clock): void {
                    $l = HoldingLocks::take($op->domain);
                    FundsWaits::closeRenewal($l['operations']->firstWhere('id', $op->id), $l, $clock->now());
                });
                expect(FundsWaits::closedRenewal($op->fresh(), $op->domain), 'Closed renewal retains its no-send proof');
            } elseif ($kind === 'pending') {
                MoneyReceiptGateway::$outcome = 'pending';
            } elseif ($kind === 'plan_crash') {
                $planCrashed = true;
                MoneyReceiptGateway::$onStatus = static fn()=>throw new RuntimeException('Synthetic reply lost after acceptance');
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
            $record(in_array($kind, ['lost','external_refund'], true) ? 'external_deficit' : 'returned_exceeds_paid_less_lost', $facts);
        }
        if ($out > $previousOut && $held < 0) { $record('new_return_exceeds_available', $facts); }
        $open = (bool) array_filter($receipts, static fn($r)=>$r['dispute'] === 'open');
        $heldOwner=(bool)array_filter($receipts,static fn($r)=>$r['dispute']==='lost');
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
                    'allocation_key'=>'stripe:'.$observation['kind'].':'.$observation['id'], 'state'=>$observation['status']];
            }
        }
        foreach (InvariantReceiptGateway::$requests as $key=>$request) {
            $result = MoneyReceiptGateway::$returns[$key] ?? null;
            if (!$result) { continue; }
            if (!in_array($result['state'], ['refunded','credited'], true)) { $reserved += $request['amount']; }
            $ledger[] = [...$result, 'gateway_id'=>(int)$receiptGateway->id, 'payment_intent_id'=>$request['receipt'],
                'allocation_key'=>$key, 'return_key'=>$result['key']];
        }
        // Plan coverage comes from the independent pre-payout balance and open-dispute facts,
        // including unsent work after a lost gateway reply, capped at the issued line.
        if ($kind === 'refund' && $error !== DomainException::class && !$heldOwner) { $promised = $open ? 0 : min($gross, max(0, $paid-$lost-$previousOut)); }
        // After an unsent plan's retry, only funds still held can remain promised; no gateway accepted this crash.
        if ($planCrashed && $kind === 'retry' && !$open) { $promised = min($gross, max($out, $paid-$lost)); }
        $reserved = max($reserved, max(0, $promised-$out));
        $peers = \Paymenter\Extensions\Servers\Domains\Models\Operation::where('invoice_id', $invoice->id)->orderBy('id')->get()
            ->map(fn($peer)=>['funding'=>$peer->funding ?? []])->all();
        $heldOwner=(bool)array_filter($receipts,static fn($r)=>$r['dispute']==='lost');
        $money = InvoiceMoney::record($input, $peers, $ledger, $heldOwner);
        $assertProjection($held-$reserved, $money['pool'], 'Projection pool mismatch '.json_encode(compact('seed','mode','trace','held','reserved','money','peers'), JSON_THROW_ON_ERROR));
        $assertProjection($open || $heldOwner ? 0 : max(0, $held-$reserved), InvoiceMoney::held($money), 'Projection held matches independent oracle');
        $assertProjection($held-$reserved < 0, $money['overdrawn'], 'Projection preserves the signed deficit');
        $assertProjection($held-$reserved === 0 && !$reserved && !$pending && !$open, InvoiceMoney::settled($money), 'Settled uses pool and unresolved work');
        $parts = array_map(fn($id)=>array_sum(InvoiceMoney::heldFor($money, $id)), array_keys($money['receipts']));
        $assertProjection(InvoiceMoney::held($money), array_sum($parts), 'Receipt shares cover exactly the held pool');
        $assertProjection($money, InvoiceMoney::record($input, $peers, $ledger, $heldOwner), 'Pure record replay is idempotent');
        $projectionChecks += 6;
        $previousOut = $out;
        // One retry must finish synchronous fake returns once funding and the dispute are resolved.
        // Ordinary refunds owe their line; retired invoices return every incremental receipt.
        if ($kind === 'retry' && $closing && !$open && !$pending && !$heldOwner) {
            $due = $mode !== 'ordinary' ? max(0, $paid-$lost) : min($gross, max(0, $paid-$lost));
            if ($out < $due) { $record('refund_stalled', [...$facts, 'due'=>$due]); }
        }
    }
    DB::table('domains_obligation_alerts')->where('code','account_money_hold')->whereNull('resolved_at')->update(['resolved_at'=>$clock->now()->getTimestamp()]);
    echo 'INVARIANT_CASE '.json_encode(['seed'=>$seed, 'mode'=>$mode, 'steps'=>count($trace), 'rules'=>array_keys($seen)], JSON_THROW_ON_ERROR)."\n";
};

// Short witnesses also make the review findings reproducible without random retry noise.
$run(1, 'ordinary', [['payment','a','full','refund'], ['lost','a','full'], ['refund'], ['retry']]);
$run(2, 'ordinary', [['payment','a','half','refund'], ['payment','b','rest','refund'], ['refund'], ['retry']]);
$run(3, 'late', [['late_payment','a',200,'credit'], ['credit'], ['lost','a','full'],
    ['late_payment','b',200,'credit'], ['credit'], ['retry'], ['late_payment','c',100,'credit'], ['retry']]);
$run(4, 'ordinary', [['payment','a','full','credit'], ['open','a','full'], ['refund'], ['won','a','full'], ['retry']]);
$run(5, 'late', [['late_payment','a',100,'credit'], ['credit'], ['lost','a','full'],
    ['late_payment','b',300,'credit'], ['retry']]);
$run(6, 'late', [['late_payment','a',200,'credit'], ['lost','a','full'],
    ['late_payment','b',200,'credit'], ['credit'], ['retry']]);

$run(7, 'ordinary', [['payment','a','full','refund'], ['plan_crash'], ['refund'], ['lost','a','full'], ['retry']]);

// A capped renewal refund must not turn the lost share into a later credit.
foreach (['lost', 'external_refund'] as $loss) {
    $run($loss === 'lost' ? 8 : 9, 'closed', [['payment','a','full','credit'], [$loss,'a',100],
        ['close_renewal'], ['credit'], ['credit'], ['retry']]);
}

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
        foreach ([150, 50] as $observedMinor) {
            $refused = false;
            try { InvoiceMoney::record($rows, $peers, [$observation('stripe:refund:synthetic_refund', 'refunded', $observedMinor)]); }
            catch (DomainException) { $refused = true; }
            expect($refused, 'Conflicting own-refund observation must fail closed');
        }
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
$sharedRecovery = ['funding'=>['refund'=>['to'=>'account_credit','amount'=>'1.50','payments'=>[['id'=>10,'amount_minor'=>150]]],
    'dispute_holds'=>[['payment_id'=>10,'dispute_id'=>'synthetic_dispute','removed_minor'=>50]]]];
$r = $assertMoney($base, [$sharedRecovery, $sharedRecovery], [$observation('stripe:dispute:synthetic_dispute','lost',300)], false, 0, 0, true);
same(100, $r['receipts'][10]['recovered'], 'Different operations can each recover their own credited share of one dispute');
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
$oldGateway = Gateway::create(['extension'=>'InvariantLegacy','name'=>'Legacy offline receipt','type'=>'gateway','enabled'=>true]);
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
DB::table('domains_obligation_alerts')->where('alert_key',hash('sha256','synthetic_hold'))->update(['resolved_at'=>$clock->now()->getTimestamp()]);
// A closed-invoice gateway row can commit before the native receipt. Refuse the whole
// payout until both identities are present; skipping it could conceal a return or loss.
[$orphanOp, , , $orphanInvoice] = $makeRenewal('money-orphan-ledger-placeholder.com', false);
$cycles->retireUnpaid($orphanOp->id);
$orphanBefore = $balance(); $orphanSends = MoneyReceiptGateway::$sends;
InvoiceTransaction::create(['invoice_id'=>$orphanInvoice->id, 'amount'=>'1.00', 'fee'=>'0.00', 'status'=>'succeeded',
    'gateway_id'=>$receiptGateway->id, 'transaction_id'=>'synthetic_known']);
InvariantReceiptGateway::$ledger = [];
foreach (['synthetic_known'=>100, 'synthetic_before_native'=>200] as $receipt=>$minor) {
    InvariantReceiptGateway::$ledger[] = ['invoice_id'=>(int)$orphanInvoice->id, 'user_id'=>(int)$orphanInvoice->user_id,
        'currency'=>'usd', 'payment_intent_id'=>$receipt, 'allocation_key'=>'excess', 'state'=>'pending', 'amount_minor'=>$minor];
}
$refused = false;
try { $refunds->creditRejected($orphanOp->id); } catch (DomainException) { $refused = true; }
expect($refused, 'An unposted closed receipt refuses the complete late payout');
same($orphanBefore, $balance(), 'Unposted gateway receipt creates no account credit');
same([], $orphanOp->fresh()->funding['late_credits'] ?? [], 'Unposted gateway receipt creates no return plan');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$orphanOp->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),
    'Unposted gateway receipt retains repair evidence for retry');
InvoiceTransaction::create(['invoice_id'=>$orphanInvoice->id, 'amount'=>'2.00', 'fee'=>'0.00', 'status'=>'succeeded',
    'gateway_id'=>$receiptGateway->id, 'transaction_id'=>'synthetic_before_native']);
foreach (['synthetic_known'=>100, 'synthetic_before_native'=>200] as $receipt=>$minor) {
    MoneyReceiptGateway::automatic($orphanInvoice, $receipt, $minor, 'pending');
}
same(null, $refunds->creditRejected($orphanOp->id), 'Posted receipts adopt the existing pending gateway returns');
same(2, count($orphanOp->fresh()->funding['late_credits'][0]['allocations']), 'Both gateway returns are retained once');
foreach (InvariantReceiptGateway::$ledger as &$entry) {
    MoneyReceiptGateway::complete($orphanInvoice, $entry['payment_intent_id'].':excess');
    $entry = [...$entry, ...MoneyReceiptGateway::$returns[$entry['payment_intent_id'].':excess']];
    $entry['return_key'] = $entry['key'];
}
unset($entry);
same(['amount'=>'3.00','currency'=>'USD'], $refunds->creditRejected($orphanOp->id), 'Posted gateway receipts finish through their existing returns');
same(null, $refunds->creditRejected($orphanOp->id), 'Completed orphan handoff replays without another payout');
same($orphanBefore, $balance(), 'Gateway-owned money is never also credited by Domains');
same($orphanSends+2, MoneyReceiptGateway::$sends, 'Only the two existing gateway-owned returns send');
InvariantReceiptGateway::$ledger = [];
echo 'PROJECTION_SUMMARY '.json_encode(['checks'=>$projectionChecks, 'failures'=>count($projectionFailures), 'cases'=>$cases, 'steps'=>$steps], JSON_THROW_ON_ERROR)."\n";
echo 'INVARIANT_SUMMARY '.json_encode(['cases'=>$cases, 'steps'=>$steps, 'checks'=>$checks,
    'failures'=>count($failures), 'coverage'=>$coverage], JSON_THROW_ON_ERROR)."\n";
// Step 5 owns deficits created by external losses after a completed credit, including
// later observations of that same deficit without any new payout. Match them exactly.
// Entry is [scenario (mode:seed), one-based action step, violation kind, total payout in minor units].
$callerBaseline = [
];
$callerIdentities = array_map(static fn($failure)=>[$failure['mode'].':'.$failure['seed'], count($failure['sequence']), $failure['rule'], $failure['facts']['out']], $failures);
$encodeIdentity = static fn(array $identity): string => json_encode($identity, JSON_THROW_ON_ERROR);
$expectedIdentities = array_map($encodeIdentity, $callerBaseline);
$actualIdentities = array_map($encodeIdentity, $callerIdentities);
$unexpected = array_values(array_diff($actualIdentities, $expectedIdentities));
$missing = array_values(array_diff($expectedIdentities, $actualIdentities));
echo 'INVARIANT_RATCHET '.json_encode(['baseline'=>count($callerBaseline), 'observed'=>count($callerIdentities),
    'unexpected'=>$unexpected, 'missing'=>$missing], JSON_THROW_ON_ERROR)."\n";
expect($unexpected === [] && $missing === [], 'Caller violation baseline changed: '.json_encode(compact('unexpected','missing'), JSON_THROW_ON_ERROR));
expect($projectionFailures === [], 'Projection checks failed: '.count($projectionFailures));

// Recovery oracle: native Credit deltas and immutable peer shares, independently of the recovery calculation.
use Paymenter\Extensions\Servers\Domains\Services\{AccountMoneyHolds,DisputeRecoveries,MoneyWorkSchema,ReminderSchema,ReminderSchedules,ReminderTemplates,Reminders,StaffOperationReview};
require __DIR__.'/Support/reminder-mail.php';
ReminderSchema::install();MoneyWorkSchema::install();ReminderTemplates::ensure();
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(Reminders::class,new Reminders($clock,$bindings));
\Illuminate\Support\Facades\Mail::fake();config(['settings.mail_disable'=>false]);
$staff->role->update(['permissions'=>['admin.domains.view','admin.domains.manage',StaffOperationReview::permission('release'),'admin.invoices.update']]);
$recovery=new DisputeRecoveries($clock);$clock->instant=$now;activeCommerceFacts($now,$now->modify('+30 days'),$ports);
$loss=static function($op,int $lost,string $id)use($creditGateway):array {
    $payment=InvoiceTransaction::where('invoice_id',$op->invoice_id)->where('amount','>','0')->sole();
    DB::table('invoice_transactions')->where('id',$payment->id)->update(['gateway_id'=>$creditGateway->id,'transaction_id'=>'synthetic_recovery_'.$id]);$payment->refresh();
    $entry=['invoice_id'=>(int)$op->invoice_id,'user_id'=>(int)$op->domain->user_id,'currency'=>'USD','payment_intent_id'=>$payment->transaction_id,
        'allocation_key'=>'stripe:dispute:'.$id,'state'=>'lost','amount_minor'=>$lost,'updated_at'=>1];
    InvariantReceiptGateway::$ledger=[$entry];InvariantReceiptGateway::$observations=[];
    return $entry;
};
$credit=Credit::firstOrCreate(['user_id'=>$user->id,'currency_code'=>'USD'],['amount'=>'0.00']);
foreach([0,70,300] as $unused){
    $op=$new('recovery-'.$unused.'-placeholder.com');$payment=InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole();
    $op->update(['funding'=>['refund'=>['to'=>'account_credit','amount'=>$op->amount,'currency'=>'USD','credit_id'=>$credit->id,
        'at'=>$now->format(DATE_ATOM),'payments'=>[['id'=>(int)$payment->id,'amount_minor'=>PricingRule::minor($op->amount)]]]]]);
    $credit->refresh()->update(['amount'=>PricingRule::decimal($unused)]);$entry=$loss($op,300,'synthetic_recovery_'.$unused);
    ReminderSchedules::enroll((int)$op->server_id);
    $before=$balance();$service=DB::table('services')->find($op->service_id);$recovery->recover($entry);
    same($before-min(300,$unused),$balance(),'Loss removes exactly the available returned credit');
    $holds=$op->fresh()->funding['dispute_holds'];
    same([300,min(300,$unused),300-min(300,$unused)],[$holds[0]['owed_minor'],$holds[0]['removed_minor'],$holds[0]['remainder_minor']],'Unused credit and spent remainder are recorded once');
    expect(InvoiceMoney::ownerHeld((int)$user->id),'Loss holds the owner');
    same((array)$service,(array)DB::table('services')->find($op->service_id),'A money hold leaves paid service terms unchanged');
    $snapshot=[$balance(),$op->fresh()->funding];$recovery->recover($entry);
    same($snapshot,[$balance(),$op->fresh()->funding],'Recovery replay changes no money or evidence');
    same($unused>0?1:0,DB::table('domains_reminder_deliveries')->where('domain_id',$op->domain_id)->where('template','domains_credit_removed')->count(),'One notice only when credit was removed');
    $alert=DB::table('domains_obligation_alerts')->where('alert_key',DisputeRecoveries::key('account_money_hold',DisputeRecoveries::dispute($entry)))->sole();
    AccountMoneyHolds::release($staff,(int)$alert->id);$recovery->recover($entry);
    expect(DB::table('domains_obligation_alerts')->where('id',$alert->id)->value('resolved_at')!==null,'Replay never reopens a staff-released hold');
}
// One shared receipt, two $1.50 credits, $5 gross and a $3 loss: only $1 is owed across the peers.
$op=$new('recovery-shared-placeholder.com');$payment=InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole();DB::table('invoice_transactions')->where('id',$payment->id)->update(['amount'=>'5.00']);$payment->refresh();
$other=$new('recovery-peer-placeholder.com');
DB::table('domains_operations')->where('id',$other->id)->update(['invoice_id'=>$op->invoice_id]);$other->refresh();
foreach([$op,$other] as $peer){$peer->update(['funding'=>['refund'=>['to'=>'account_credit','amount'=>'1.50','payments'=>[['id'=>(int)$payment->id,'amount_minor'=>150]]]]]);}
$credit->refresh()->update(['amount'=>'3.00']);$entry=$loss($op,300,'synthetic_shared');$recovery->recover($entry);
same(200,$balance(),'Shared receipt recovers $1 once, not once per operation');
$before=[$op->fresh()->funding,$other->fresh()->funding,$balance()];$recovery->recover($entry);
same($before,[$op->fresh()->funding,$other->fresh()->funding,$balance()],'Shared recovery replay retains both peer shares');
AccountMoneyHolds::release($staff,(int)DB::table('domains_obligation_alerts')->where('alert_key',DisputeRecoveries::key('account_money_hold','synthetic_shared'))->value('id'));
// A loss with no return still holds a successful operation. Failed and exhausted decisions write durable evidence.
$op=$new('recovery-deferred-placeholder.com');$entry=$loss($op,300,'synthetic_deferred');
$op->update(['state'=>'succeeded']);InvariantReceiptGateway::$conflicts=2;
$recovery->recover($entry,['at'=>1,'key'=>'synthetic_cursor']);
$retry=DB::table('domains_obligation_alerts')->where('code','dispute_recovery')->where('operation_id',$op->id)->sole();
expect($retry->resolved_at===null,'Exhausted recovery commits its retry evidence');
same(0,DB::table('domains_money_work')->count(),'Dispute retry never uses an operation queue key');
(new ReflectionMethod(Refunds::class,'retryPending'))->invoke($refunds,$op->id-1,1);
expect(DB::table('domains_obligation_alerts')->where('id',$retry->id)->value('resolved_at')===null,'Refund retries preserve dispute evidence');
$recovery->recover($entry);
expect(DB::table('domains_obligation_alerts')->where('id',$retry->id)->value('resolved_at')!==null,'Successful owed-zero recovery resolves its own retry evidence');
same([], $op->fresh()->funding['dispute_holds']??[], 'Owed-zero recovery adds no amount entry');
$nativeSuccessful = true;
