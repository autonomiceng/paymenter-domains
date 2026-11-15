<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/money-receipt.php';

use App\Models\{Credit, Gateway, Invoice, InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, RefundCandidates, Refunds};

class HeldPlanGateway extends MoneyReceiptGateway
{
    public static array $observations = [];
    public function receiptLedger(Invoice $invoice): array { return self::ledger($invoice, 'HeldPlan'); }
    public static function ledger(Invoice $invoice, string $extension): array
    {
        return array_values(array_filter(self::$observations[$invoice->id] ?? [], static fn($o) =>
            InvoiceTransaction::where('invoice_id',$invoice->id)->where('transaction_id',$o['payment_intent_id'])
                ->first()?->gateway?->extension === $extension));
    }
}
class HeldPlanCreditGateway extends \App\Classes\Extension\Gateway
{
    public function pay(Invoice $invoice, $total) { return ''; }
    public function receiptLedger(Invoice $invoice): array { return HeldPlanGateway::ledger($invoice, 'HeldPlanCredit'); }
    public function receiptReversals(Invoice $invoice, string $receipt): array { return []; }
}
class_alias(HeldPlanGateway::class, 'Paymenter\\Extensions\\Gateways\\HeldPlan\\HeldPlan');
class_alias(HeldPlanCreditGateway::class, 'Paymenter\\Extensions\\Gateways\\HeldPlanCredit\\HeldPlanCredit');
$card = Gateway::create(['extension'=>'HeldPlan','name'=>'Offline card','type'=>'gateway','enabled'=>true]);
$creditOnly = Gateway::create(['extension'=>'HeldPlanCredit','name'=>'Offline credit','type'=>'gateway','enabled'=>true]);
$staff = commerceUser();
$role = Role::create(['name'=>'Refund plans','permissions'=>['admin.domains.manage','admin.invoices.update']]);
$staff->update(['role_id'=>$role->id]);
$refunds = new Refunds($clock);
MoneyReceiptGateway::$lost = false;
MoneyReceiptGateway::$outcome = 'pending';
$failures = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; echo 'HELD_PLAN_FAIL '.$message."\n"; }
};
$equal = static fn($expected, $actual, string $message) => $check($expected === $actual, $message);
$creditWrites = 0;
Credit::saving(static function() use (&$creditWrites): void { $creditWrites++; });
$balance = static fn(): int => PricingRule::minor((string)(Credit::where('user_id',$user->id)->value('amount') ?? '0.00'));
$held = static function(string $name) use ($new): array {
    $op = $new($name, false); $op->update(['state'=>'held','safe_code'=>'staff_review']);
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
    return [$op, Invoice::findOrFail($op->invoice_id)];
};
$pay = static fn($invoice, $gateway, int $minor, string $receipt) => InvoiceTransaction::create([
    'invoice_id'=>$invoice->id,'amount'=>PricingRule::decimal($minor),'fee'=>'0.00',
    'status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>$receipt]);
$observe = static function($invoice, string $receipt, string $state, int $minor): void {
    HeldPlanGateway::$observations[$invoice->id] = [['invoice_id'=>(int)$invoice->id,'user_id'=>(int)$invoice->user_id,
        'currency'=>'USD','payment_intent_id'=>$receipt,'allocation_key'=>'stripe:dispute:synthetic_'.$invoice->id,
        'state'=>$state,'amount_minor'=>$minor]];
};

// Both destinations must leave zero availability retryable without a plan, a credit or a gateway request.
foreach ([[$card,'open'],[$creditOnly,'lost']] as [$gateway,$state]) {
    [$op,$invoice] = $held('held-plan-'.$state.'-placeholder.com'); $minor = PricingRule::minor($op->amount);
    $pay($invoice,$gateway,$minor,'synthetic_'.$state); $observe($invoice,'synthetic_'.$state,$state,$minor);
    $before = $balance(); $sends = MoneyReceiptGateway::$sends; $writes = $creditWrites;
    $equal(null,$refunds->refundHeld($staff,$op->id,'customer_cancelled'),'No completion when held is zero');
    $check(!isset($op->fresh()->funding['refund']),'Zero held writes no immutable refund plan');
    $check(RefundCandidates::query(\Paymenter\Extensions\Servers\Domains\Models\Operation::query())->where('id',$op->id)->exists(),'Zero held remains a refund candidate');
    $funding = $op->fresh()->getRawOriginal('funding');
    $refunds->creditRejected($op->id);
    $equal($funding,$op->fresh()->getRawOriginal('funding'),'Retry with zero held writes no funding');
    $equal($before,$balance(),'Zero held creates no account credit');
    $equal($writes,$creditWrites,'Zero held never saves a Credit row');
    $equal($sends,MoneyReceiptGateway::$sends,'Zero held sends no card allocation');
    $equal(1,InvoiceTransaction::where('invoice_id',$invoice->id)->count(),'Zero held leaves native receipts unchanged');
    if ($state === 'open') {
        $observe($invoice,'synthetic_'.$state,'won',$minor);
        $refunds->creditRejected($op->id); $refunds->creditRejected($op->id);
        $equal($before+$minor,$balance(),'A won dispute retries to credit exactly once');
        $equal('account_credit',$op->fresh()->funding['refund']['to'],'Dispute observation sends its share to credit');
        $equal($sends,MoneyReceiptGateway::$sends,'Won dispute does not return to the card');
    }
}

// Receipt identity determines the split: a won card dispute and an unsupported gateway become credit,
// while the other card receipt keeps its original-payment allocation.
[$op,$invoice] = $held('held-plan-mixed-placeholder.com'); $gross = PricingRule::minor($op->amount);
$disputed = $pay($invoice,$card,200,'synthetic_won');
$original = $pay($invoice,$card,200,'synthetic_original');
$unsupported = $pay($invoice,$creditOnly,$gross-400,'synthetic_credit');
$observe($invoice,'synthetic_won','won',200); $before = $balance();
$refunds->refundHeld($staff,$op->id,'customer_cancelled');
$plan = $op->fresh()->funding['refund'];
$equal($op->amount,$plan['amount'],'Mixed plan covers exactly the issued line');
$equal([['id'=>$disputed->id,'amount_minor'=>200],['id'=>$original->id,'amount_minor'=>200],
    ['id'=>$unsupported->id,'amount_minor'=>$gross-400]],$plan['payments'],'Plan retains each exact receipt share');
$equal($gross-200,$plan['bridge_minor'],'Only the healthy card share stays out of bridge credit');
$equal($before+$gross-200,$balance(),'Mixed bridge credit is exact');
$equal(1,count($plan['allocations']),'Only the healthy card receipt gets an allocation');
$equal([$original->id,200],[$plan['allocations'][0]['transaction_id'],$plan['allocations'][0]['amount_minor']],
    'Card allocation has the correct receipt identity and amount');
$refunds->creditRejected($op->id);
$equal($before+$gross-200,$balance(),'Pending card retry cannot duplicate bridge credit');
expect($failures === [], 'Held plan regressions: '.json_encode($failures, JSON_THROW_ON_ERROR));
echo "Zero-held refunds and mixed receipt plan shares passed\n";
$nativeSuccessful = true;
