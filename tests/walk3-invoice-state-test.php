<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
require __DIR__.'/staff-request-invoice-test.php';
require __DIR__.'/Support/money-receipt.php';
$nativeSuccessful=false;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
use Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns;
foreach(['partial'=>['amount_minor'=>500],'open'=>['status'=>'open'],'failed'=>['status'=>'failed'],'read failure'=>'unreadable'] as $name=>$over){
    StaffInvoiceReversalGateway::$observations['pi_staff_invoice']=is_array($over)?$row($over):$over;
    same('Paid',RequestFacts::for($op->fresh())['invoice_state'],$name.' does not report a full refund');
}
StaffInvoiceReversalGateway::$observations['pi_staff_invoice']=$row(['kind'=>'refund','status'=>'refunded','amount_minor'=>1099]);
same('Refunded',RequestFacts::for($op->fresh())['invoice_state'],'The staff request reports a full webhook refund');

$op=$held('request-invoice-return-placeholder.com');
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Receipt fixture','type'=>'gateway','enabled'=>true]);
App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-receipt']);
MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='pending';
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$op->id])
    ->callAction('refund',data:['reason'=>'Customer cancelled the request'])->assertHasNoActionErrors();
same('rejected',$op->fresh()->state,'Cancel and refund closes the request');
same('original_payment',$op->fresh()->funding['refund']['to']??null,'Cancel and refund reserves the original payment return');
same('Paid',RequestFacts::for($op->fresh())['invoice_state'],'An unfinished original payment return stays Paid');
$key=array_key_last(MoneyReceiptGateway::$returns);
MoneyReceiptGateway::complete(App\Models\Invoice::findOrFail($op->invoice_id),$key);
$run($op->fresh(),'credit');
same(true,ReceiptReturns::settled($op->fresh()),'The original payment return settled');
same('Refunded',RequestFacts::for($op->fresh())['invoice_state'],'The staff request reports a full Cancel and refund return');
MoneyReceiptGateway::credit(App\Models\Invoice::findOrFail($op->invoice_id),$key);
ReceiptReturns::resume($op->id,$clock->now());
same('credited',$op->fresh()->funding['receipt_return_results'][$key]['state'],'A failed original payment refund becomes account credit');
same(true,ReceiptReturns::settled($op->fresh()),'The account credit return settled');
same('Paid',RequestFacts::for($op->fresh())['invoice_state'],'A return credited to the account stays Paid');
$nativeSuccessful=true;
