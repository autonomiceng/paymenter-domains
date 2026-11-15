<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
require __DIR__.'/Support/staff-operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory;
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
$nativeSuccessful=true;
