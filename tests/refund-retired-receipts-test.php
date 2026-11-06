<?php
// Native renewal refunds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$renewalFixtureNames=['retired-card-partial'];
require __DIR__.'/Support/renewal-refund.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
// Closed-invoice returns belong to the gateway. Domains only adopts and polls the same automatic allocation.
require __DIR__.'/Support/money-receipt.php';MoneyReceiptGateway::$lost=false;
$gateway=\App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Money receipt fixture','type'=>'gateway','enabled'=>true]);
[$card,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('retired-card-partial-placeholder.com'),false);$cycles->retireUnpaid($card->id);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_partial']);
same('pending',$presenter->invoiceOutcome($user,(int)$bill->id)->money,'A capable receipt is not promised account credit before the sweep');
$before=$credit();same(null,$refunds->creditRejected($card->id),'Missing automatic return needs review, never a second allocation');
same(0,MoneyReceiptGateway::$sends,'Domains cannot send a closed-invoice return');same($before,$credit(),'Missing automatic return cannot mint credit');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$card->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Missing automatic return is durable');
MoneyReceiptGateway::automatic($bill,'receipt_partial',100,'pending');
same(null,$refunds->creditRejected($card->id),'Pending automatic return is unfinished');
MoneyReceiptGateway::complete($bill,'receipt_partial:excess');
same(['amount'=>'1.00','currency'=>'USD'],$refunds->creditRejected($card->id),'Domains observes original-method completion');
same(null,$refunds->creditRejected($card->id),'Observed automatic refund is once-only');
$outcome=$presenter->invoiceOutcome($user,(int)$bill->id);same(['returned','$1.00'],[$outcome->money,$outcome->amount],'Observed original-method completion is not reported as credit');
// Completion can precede Domains observing the positive receipt and its matching negative native row.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.50','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_later']);
MoneyReceiptGateway::automatic($bill,'receipt_later',50);
same(['amount'=>'0.50','currency'=>'USD'],$refunds->creditRejected($card->id),'Already-completed automatic return is adopted even with zero net funding');
same(null,$refunds->creditRejected($card->id),'Later automatic return is once-only');
same($before,$credit(),'Gateway returns cannot also mint account credit');same(2,MoneyReceiptGateway::$sends,'Only the gateway produces two automatic returns');
// Mixed late money cannot credit the capable receipt while its automatic handoff is pending.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.25','fee'=>'0.00','status'=>'succeeded']);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.50','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_mixed']);
MoneyReceiptGateway::automatic($bill,'receipt_mixed',50,'pending');
same(null,$refunds->creditRejected($card->id),'Mixed late money waits for the known automatic handoff');same($before,$credit(),'No speculative credit while automatic return is pending');
MoneyReceiptGateway::complete($bill,'receipt_mixed:excess');
same(['amount'=>'0.25','currency'=>'USD'],$refunds->creditRejected($card->id),'Only unsupported money reaches the credit bridge');
same(null,$refunds->creditRejected($card->id),'Mixed bridge retry is once-only');same($before+25,$credit(),'Mixed bridge excludes all capable receipts');
$outcome=$presenter->invoiceOutcome($user,(int)$bill->id);same(['mixed','$2.25'],[$outcome->money,$outcome->amount],'Mixed cash return and bridge credit retain both destinations');
same([],array_keys($registrations),'Every independent refund scenario ran');
echo "Retired renewal original-method and mixed receipt returns passed\n"; $nativeSuccessful=true;
