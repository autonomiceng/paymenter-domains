<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/money-receipt.php';

use App\Models\{Credit, Gateway, Invoice, InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,NativeIntentSchema,PricingRule,RefundCandidates,RefundPayment,Refunds};

class HeldPlanGateway extends MoneyReceiptGateway
{
    public static array $observations = [];
    public static ?int $unreadableInvoice = null;
    public function receiptLedger(Invoice $invoice): array { return self::ledger($invoice, 'HeldPlan'); }
    public static function ledger(Invoice $invoice, string $extension): array
    {
        if(self::$unreadableInvoice===$invoice->id){throw new RuntimeException('Synthetic unreadable held ledger');}
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
$role = Role::create(['name'=>'Refund plans','permissions'=>['admin.domains.manage','admin.domains.view','admin.invoices.update']]);
$staff->update(['role_id'=>$role->id]);
$refunds = new Refunds($clock);
MoneyReceiptGateway::$lost = false;
MoneyReceiptGateway::$outcome = 'pending';
$failures = []; $reasons = [];
$check = static function(bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; echo 'HELD_PLAN_FAIL '.$message."\n"; }
};
$equal = static fn($expected, $actual, string $message) => $check($expected === $actual, $message);
$staffFacts = static function($op) use ($staff): array {
    $prior = Illuminate\Support\Facades\Auth::user(); Illuminate\Support\Facades\Auth::setUser($staff);
    try { return Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($op->fresh()); } finally { $prior ? Illuminate\Support\Facades\Auth::setUser($prior) : Illuminate\Support\Facades\Auth::forgetUser(); }
};
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
    $facts=$staffFacts($op);$reasons[$state]=$facts['refund_sub']??null;
    $check(($facts['refund']??null)==='No credit added'&&is_string($reasons[$state])&&$reasons[$state]!=='','Staff see why a held refund waits ('.$state.')');
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

$check(($reasons['open']??null)!==($reasons['lost']??null),'An open dispute and a lost one give staff different reasons');

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
$equal(null,Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::refundLimit($op->fresh(),false),'A return of the full line has no limit reason');

// Credit-only fallback retains held shares after a partial loss, rather than gross receipts.
[$op,$invoice]=$held('held-plan-credit-only-placeholder.com');$gross=PricingRule::minor($op->amount);
$first=$pay($invoice,$creditOnly,200,'synthetic_credit_first');
$second=$pay($invoice,$creditOnly,$gross-200,'synthetic_credit_second');
$observe($invoice,'synthetic_credit_first','lost',100);$before=$balance();$writes=$creditWrites;$sends=MoneyReceiptGateway::$sends;
$equal(['amount'=>PricingRule::decimal($gross-100),'currency'=>'USD'],$refunds->refundHeld($staff,$op->id,'customer_cancelled'),'Credit-only fallback returns exactly held funds');
$plan=$op->fresh()->funding['refund'];
$equal('account_credit',$plan['to'],'Credit-only fallback destination is account credit');
$equal(PricingRule::decimal($gross-100),$plan['amount'],'Credit-only plan amount equals held funds');
$equal([['id'=>$first->id,'amount_minor'=>100],['id'=>$second->id,'amount_minor'=>$gross-200]],$plan['payments'],'Credit-only plan retains exact held receipt shares');
$equal($before+$gross-100,$balance(),'Credit-only fallback credits exactly the held amount');
$funding=$op->fresh()->getRawOriginal('funding');
$refunds->creditRejected($op->id);$refunds->creditRejected($op->id);
$equal($before+$gross-100,$balance(),'Credit-only retries never duplicate credit');
$equal($writes+1,$creditWrites,'Credit-only fallback saves credit exactly once across retries');
$equal($funding,$op->fresh()->getRawOriginal('funding'),'Credit-only retries retain the immutable plan');
$equal($sends,MoneyReceiptGateway::$sends,'Credit-only fallback never sends a card return');
$limit=Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::refundLimit($op->fresh(),false);
$check($limit!==null&&str_contains((string)($staffFacts($op)['refund_sub']??''),$limit),'Staff see why the return is smaller than the line');

// Approval survives an unreadable ledger, with no money or plan, for the ordinary retry.
[$op,$invoice]=$held('held-plan-unreadable-placeholder.com');$gross=PricingRule::minor($op->amount);
$pay($invoice,$creditOnly,$gross,'synthetic_unreadable');$before=$balance();$writes=$creditWrites;$sends=MoneyReceiptGateway::$sends;
HeldPlanGateway::$unreadableInvoice=$invoice->id;
foreach(['approve','retry'] as $attempt){
    $refused=false;
    try{if($attempt==='approve'){$refunds->refundHeld($staff,$op->id,'customer_cancelled');}else{$refunds->creditRejected($op->id);}}
    catch(RuntimeException $e){if($e->getMessage()!=='Synthetic unreadable held ledger'){throw $e;}$refused=true;}
    $check($refused,'Unreadable ledger refuses payout: '.$attempt);
    $check(!isset($op->fresh()->funding['refund']),'Unreadable ledger saves no plan: '.$attempt);
    $check(RefundCandidates::query(Operation::query())->whereKey($op->id)->exists(),'Unreadable ledger remains retryable: '.$attempt);
}
$equal($before,$balance(),'Unreadable ledger creates no credit');$equal($writes,$creditWrites,'Unreadable ledger never saves credit');
$equal($sends,MoneyReceiptGateway::$sends,'Unreadable ledger sends no card return');
HeldPlanGateway::$unreadableInvoice=null;
$refunds->creditRejected($op->id);$refunds->creditRejected($op->id);
$equal($before+$gross,$balance(),'Readable ledger recovers the pending credit once');

// Closed-order coverage by ID can be partial; every pass must see current gateway facts.
NativeIntentSchema::install();$retirement=new AcquisitionRetirement($clock);app()->instance(AcquisitionRetirement::class,$retirement);
$op=$new('held-plan-closed-partial-placeholder.com',false);
DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'cancelled']);
DB::table('services')->where('id',$op->service_id)->update(['status'=>'cancelled']);
$retirement->retire($op->domain_id);$op->refresh();$invoice=Invoice::findOrFail($op->invoice_id);
$receipt=$pay($invoice,$creditOnly,300,'synthetic_closed_credit');$account=Account::where('server_id',$op->server_id)->sole();
$observe($invoice,'synthetic_closed_credit','lost',100);$before=$balance();
$refunds->creditRetired($account);
$equal($before+200,$balance(),'Closed cursor credits only the currently held share');
$equal([$receipt->id=>200],RefundPayment::lateCredited($op->fresh()),'Coverage retains a partially credited payment ID and amount');
$observe($invoice,'synthetic_closed_credit','won',100);
$refunds->creditRetired($account);$refunds->creditRetired($account);
$equal($before+300,$balance(),'Closed cursor processes remaining coverage on the same payment ID once');
$equal([$receipt->id=>300],RefundPayment::lateCredited($op->fresh()),'Closed coverage now equals the full receipt');
HeldPlanGateway::$observations[$invoice->id]=[['invoice_id'=>(int)$invoice->id,'user_id'=>(int)$invoice->user_id,'currency'=>'USD',
    'payment_intent_id'=>'synthetic_closed_credit','allocation_key'=>'stripe:refund:synthetic_external','state'=>'refunded','amount_minor'=>100]];
$refunds->creditRetired($account);
$check(DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Later external refund is processed into overdraw repair despite full retained coverage');
$equal($before+300,$balance(),'Later external refund creates no extra account credit');
DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->update(['resolved_at'=>1]);
$funding=$op->fresh()->funding;$funding['late_credits'][0]['amount']='unreadable';
DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($funding,JSON_THROW_ON_ERROR))]);
$refunds->creditRetired($account);
$check(DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Malformed retained plan still reaches repair reporting');
expect($failures === [], 'Held plan regressions: '.json_encode($failures, JSON_THROW_ON_ERROR));
echo "Zero-held refunds and mixed receipt plan shares passed\n";
$nativeSuccessful = true;
