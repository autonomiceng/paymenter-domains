<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
require __DIR__.'/Support/staff-operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
use Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainHistory;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,ReceiptReturns};
$op=$held('walk3-activity-placeholder.com');$run($op,'refund');
$events=array_column(array_filter(DomainHistory::entries($op->domain->fresh()),'is_array'),'event');
expect(in_array('Cancelled',$events,true),'Activity retains the cancellation');
expect(in_array('Credit added',$events,true),'Activity retains the completed payment return');
// The walked order returned to its original payment, so retain that outcome too.
$card=$held('walk3-card-activity-placeholder.com');
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Receipt fixture','type'=>'gateway','enabled'=>true]);
App\Models\InvoiceTransaction::where('invoice_id',$card->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-activity-receipt']);
MoneyReceiptGateway::$lost=false;$run($card,'refund');
$events=array_column(array_filter(DomainHistory::entries($card->domain->fresh()),'is_array'),'event');
expect(in_array('Cancelled',$events,true)&&in_array('Refunded',$events,true),'Activity retains cancellation and the original payment refund');
$refundEvent=static fn($op)=>array_values(array_filter(DomainHistory::entries($op->domain->fresh()),
    static fn($row)=>is_array($row)&&($row['__key']??null)==='refund-'.$op->id));
same('Refunded',$refundEvent($card)[0]['event']??null,'The original completed refund has its original-payment label');
// A pending original return must still have no completed money event.
$pending=$held('walk3-pending-activity-placeholder.com');
App\Models\InvoiceTransaction::where('invoice_id',$pending->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-pending-activity']);
MoneyReceiptGateway::$outcome='pending';$run($pending,'refund');MoneyReceiptGateway::$outcome='refunded';
same([],$refundEvent($pending),'A pending original refund has no completed payment event');
// A later closed-invoice receipt has its own gateway-owned return and cannot erase the earlier refund.
$invoice=App\Models\Invoice::findOrFail($card->invoice_id);
App\Models\InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-late-activity']);
MoneyReceiptGateway::automatic($invoice,'synthetic-late-activity',100,'pending');
Illuminate\Support\Facades\DB::transaction(function()use($card,$clock):void {
    $domain=$card->domain->fresh();$l=HoldingLocks::take($domain,released:$domain->reservation_name===null);
    expect(ReceiptReturns::adoptClosed($l['operations']->firstWhere('id',$card->id),$l,$clock->now()),'The late receipt gets its own retained return plan');
});
ReceiptReturns::resume($card->id,$clock->now(),'refund_rejected');
same(false,ReceiptReturns::settled($card->fresh()),'The late return is still pending for retry settlement');
same('Refunded',$refundEvent($card)[0]['event']??null,'A pending late return retains the earlier completed refund');
// Settle the later receipt as credit to prove it cannot change the earlier refund destination label.
MoneyReceiptGateway::complete($invoice,'synthetic-late-activity:excess');
MoneyReceiptGateway::credit($invoice,'synthetic-late-activity:excess');
ReceiptReturns::resume($card->id,$clock->now(),'refund_rejected');
same(true,ReceiptReturns::settled($card->fresh()),'The later return has settled');
same('Refunded',$refundEvent($card)[0]['event']??null,'Later account credit cannot relabel the original payment refund');
$nativeSuccessful=true;
