<?php
// A request names its bill the way the invoice list does and says when the gateway took money back.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
$op=$held('staff-request-invoice-placeholder.com');
// An unpaid bill has no number yet: it reads Proforma #id, never a bare id that another bill's INV number could match.
DB::table('invoices')->where('id',$op->invoice_id)->update(['number'=>null]);
same('Proforma #'.$op->invoice_id,RequestFacts::for($op->fresh())['invoice'],'An unnumbered invoice reads Proforma #id');
DB::table('invoices')->where('id',$op->invoice_id)->update(['number'=>'INV-29']);
same('INV-29',RequestFacts::for($op->fresh())['invoice'],'A numbered invoice reads its INV number');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSee('INV-29')->assertDontSee('Invoice #'.$op->invoice_id)->assertDontSee('lost to a dispute');
// A dispute lost on that invoice shows on the request in amber.
class StaffInvoiceReversalGateway extends \App\Classes\Extension\Gateway {
    public static array $observations=[];
    public function pay(\App\Models\Invoice $invoice,$total){return '';}
    public function receiptReversals(\App\Models\Invoice $invoice,string $receipt):array {return self::$observations[$receipt]??[];}
}
class_alias(StaffInvoiceReversalGateway::class,'Paymenter\\Extensions\\Gateways\\StaffInvoiceReversal\\StaffInvoiceReversal');
$gateway=\App\Models\Gateway::create(['extension'=>'StaffInvoiceReversal','name'=>'Reversal fixture','type'=>'gateway','enabled'=>true]);
InvoiceTransaction::where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'pi_staff_invoice']);
$invoice=\App\Models\Invoice::findOrFail($op->invoice_id);
StaffInvoiceReversalGateway::$observations['pi_staff_invoice']=[['kind'=>'dispute','id'=>'dp_staff','status'=>'lost','needs_review'=>true,
    'invoice_id'=>(int)$invoice->id,'user_id'=>(int)$invoice->user_id,'payment_intent_id'=>'pi_staff_invoice','currency'=>strtolower($invoice->currency_code),
    'amount_minor'=>1099,'credited_minor'=>0,'credit_balance_minor'=>0]];
same(['words'=>'$10.99 lost to a dispute.','act'=>true],RequestFacts::for($op->fresh())['payment_reversed']??null,'The request states the lost dispute and its amount');
$html=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSee('$10.99 lost to a dispute.')->html();
$at=strpos($html,'$10.99 lost to a dispute.');$before=substr($html,max(0,$at-3000),3000);
preg_match_all('/data-beco-tone="([a-z]+)"/',$before,$tones);
same('warn',end($tones[1])?:null,'The lost dispute reads amber');
echo "Request invoice name and reversal passed.\n";
$nativeSuccessful=true;
