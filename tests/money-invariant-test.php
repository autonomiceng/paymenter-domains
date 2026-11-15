<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/money-receipt.php';

use App\Models\{Credit, Gateway, Invoice, InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds};

// Both gateway ports use only process-local observations. The native runner disables network I/O.
class InvariantReceiptGateway extends MoneyReceiptGateway
{
    public static array $observations = [];
    public function receiptReversals(Invoice $invoice, string $receipt): array
    {
        return self::$observations[$receipt] ?? [];
    }
}
class InvariantCreditGateway extends \App\Classes\Extension\Gateway
{
    public function pay(Invoice $invoice, $total) { return ''; }
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
$failures = []; $checks = 0; $steps = 0; $cases = 0; $coverage = [];

// Each scenario has its own invoice. The oracle reads actual native payouts and the credit balance,
// never Domains' available(), retained plan amounts, or a service's returned observation.
$run = static function (int $seed, string $mode, array $actions) use (
    $new, $makeRenewal, $cycles, $clock, $now, $ports, $refunds, $staff, $balance, $receiptGateway, $creditGateway,
    &$failures, &$checks, &$steps, &$cases, &$coverage
): void {
    $cases++;
    MoneyReceiptGateway::$returns = []; MoneyReceiptGateway::$lost = false;
    MoneyReceiptGateway::$outcome = 'refunded'; MoneyReceiptGateway::$onStatus = null;
    InvariantReceiptGateway::$observations = [];
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
    $beforeCredit = $balance(); $receipts = []; $trace = []; $seen = []; $closing = false; $previousOut = 0;
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
echo 'INVARIANT_SUMMARY '.json_encode(['cases'=>$cases, 'steps'=>$steps, 'checks'=>$checks,
    'failures'=>count($failures), 'coverage'=>$coverage], JSON_THROW_ON_ERROR)."\n";
expect($failures === [], 'Money invariant violations; see INVARIANT_FAIL sequences above');
$nativeSuccessful = true;
