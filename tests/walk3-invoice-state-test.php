<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
require __DIR__.'/staff-request-invoice-test.php';
require __DIR__.'/Support/money-receipt.php';
$nativeSuccessful=false;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
use Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns;
$assertRefundedInvoice=static function($op):void {
    $facts=RequestFacts::for($op->fresh());$sub='Auto Pay off';
    $value=Paymenter\Extensions\Servers\Domains\Admin\Support\RequestRows::invoice($facts['invoice'],$facts['invoice_state'],$sub);
    $invoiceRow=Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Row::make('Invoice',$value);
    Filament\Schemas\Schema::make(Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$op->id])->instance())->components([$invoiceRow])->getComponents();
    $rendered=strip_tags($invoiceRow->toEmbeddedHtml());
    expect($value->state!==Paymenter\Extensions\Servers\Domains\Presentation\State::Paid&&!preg_match('/\bPaid\b/',$rendered),'A fully refunded invoice never renders as Paid');
    expect(str_contains($rendered,'Refunded')&&str_contains($rendered,$facts['invoice'])&&str_contains($rendered,$sub),'The invoice row retains its name, refunded money fact and Auto Pay detail');
};
foreach(['partial'=>['amount_minor'=>500],'open'=>['status'=>'open'],'failed'=>['status'=>'failed'],'read failure'=>'unreadable'] as $name=>$over){
    StaffInvoiceReversalGateway::$observations['pi_staff_invoice']=is_array($over)?$row($over):$over;
    same('Paid',RequestFacts::for($op->fresh())['invoice_state'],$name.' does not report a full refund');
}
StaffInvoiceReversalGateway::$observations['pi_staff_invoice']=$row(['kind'=>'refund','status'=>'refunded','amount_minor'=>1099]);
same('Refunded',RequestFacts::for($op->fresh())['invoice_state'],'The staff request reports a full webhook refund');
$assertRefundedInvoice($op);

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
$assertRefundedInvoice($op);
$invoice=App\Models\Invoice::findOrFail($op->invoice_id);
App\Models\InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>'10.99','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-late-invoice']);
MoneyReceiptGateway::automatic($invoice,'synthetic-late-invoice',1099);
MoneyReceiptGateway::credit($invoice,'synthetic-late-invoice:excess');
Illuminate\Support\Facades\DB::transaction(function()use($op,$clock):void {
    $domain=$op->domain->fresh();$l=Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($domain,released:$domain->reservation_name===null);
    expect(ReceiptReturns::adoptClosed($l['operations']->firstWhere('id',$op->id),$l,$clock->now()),'The later payment has its own retained return');
});
ReceiptReturns::resume($op->id,$clock->now());
same(['credited','Refunded'],[array_values($op->fresh()->funding['receipt_return_results'])[1]['state'],RequestFacts::for($op->fresh())['invoice_state']],'A separate later credit preserves the full original payment refund');
MoneyReceiptGateway::credit(App\Models\Invoice::findOrFail($op->invoice_id),$key);
ReceiptReturns::resume($op->id,$clock->now());
same('credited',$op->fresh()->funding['receipt_return_results'][$key]['state'],'A failed original payment refund becomes account credit');
same(true,ReceiptReturns::settled($op->fresh()),'The account credit return settled');
same('Paid',RequestFacts::for($op->fresh())['invoice_state'],'A return credited to the account stays Paid');
$nativeSuccessful=true;
