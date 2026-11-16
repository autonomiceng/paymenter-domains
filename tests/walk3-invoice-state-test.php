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
// A two-domain cart: this request's own return covers its own line, so it reads Refunded, not Paid for the whole invoice.
$second=App\Models\InvoiceItem::create(['invoice_id'=>$op->invoice_id,'description'=>'second-cart-placeholder.com','price'=>'12.49','quantity'=>1]);
expect(Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)App\Models\Invoice::findOrFail($op->invoice_id)->total)>ReceiptReturns::amount($op->fresh()->funding['refund']),'The cart invoice is larger than this request\'s own return');
same('Refunded',RequestFacts::for($op->fresh())['invoice_state'],'A cart refund of one domain reports that domain refunded');
$second->delete();
$invoice=App\Models\Invoice::findOrFail($op->invoice_id);
App\Models\InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>'10.99','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-late-invoice']);
MoneyReceiptGateway::automatic($invoice,'synthetic-late-invoice',1099);
MoneyReceiptGateway::credit($invoice,'synthetic-late-invoice:excess');
Illuminate\Support\Facades\DB::transaction(function()use($op,$clock):void {
    $domain=$op->domain->fresh();$l=Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($domain,released:$domain->reservation_name===null);
    expect(ReceiptReturns::adoptClosed($l['operations']->firstWhere('id',$op->id),$l,$clock->now()),'The later payment has its own retained return');
});
ReceiptReturns::resume($op->id,$clock->now(),'refund_rejected');
same(['credited','Refunded'],[array_values($op->fresh()->funding['receipt_return_results'])[1]['state'],RequestFacts::for($op->fresh())['invoice_state']],'A separate later credit preserves the full original payment refund');
MoneyReceiptGateway::credit(App\Models\Invoice::findOrFail($op->invoice_id),$key);
ReceiptReturns::resume($op->id,$clock->now(),'refund_rejected');
same('credited',$op->fresh()->funding['receipt_return_results'][$key]['state'],'A failed original payment refund becomes account credit');
same(true,ReceiptReturns::settled($op->fresh()),'The account credit return settled');
same('Paid',RequestFacts::for($op->fresh())['invoice_state'],'A return credited to the account stays Paid');
// Request money follow-ups report every failing case before stopping.
$followups=[];$want=static function(bool $ok,string $message)use(&$followups):void{if(!$ok){$followups[]=$message;echo 'FOLLOWUP_FAIL '.$message."\n";}};
$staffFacts=static function($op)use($staff):array{Illuminate\Support\Facades\Auth::setUser($staff);return RequestFacts::for($op->fresh());};
// Two card receipts on one request: one share was released with no credit after a lost dispute, the other was refunded.
// Only part went back, so the invoice stays Paid and staff see the smaller return.
$returnSetup=static function(string $name,\Closure $funding)use($held,$gateway):array {
    $op=$held($name);$gross=Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$op->amount);
    App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>$name.'-a','amount'=>'5.00']);
    $a=App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole();
    $b=App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal($gross-500),'fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>$name.'-b']);
    $op->update(['state'=>'rejected','funding'=>$funding($op,$a,$b,$gross)]);return [$op->fresh(),$a,$b,$gross];
};
$allocation=static fn($row,string $key,int $minor)=>['transaction_id'=>(int)$row->id,'gateway_id'=>(int)$row->gateway_id,'receipt'=>$row->transaction_id,'key'=>$key,'amount_minor'=>$minor];
[$op,$a,$b,$gross]=$returnSetup('request-invoice-half-placeholder.com',static fn($op,$a,$b,$gross)=>[
    'refund'=>['amount'=>$op->amount,'currency'=>'USD','payments'=>[['id'=>(int)$a->id,'amount_minor'=>500],['id'=>(int)$b->id,'amount_minor'=>$gross-500]],
        'to'=>'original_payment','allocations'=>[$allocation($a,'half-a',500),$allocation($b,'half-b',$gross-500)],'bridge_minor'=>0,'credit_id'=>null,'at'=>'2026-11-01T00:00:00+00:00'],
    'receipt_return_results'=>['half-a'=>['state'=>'released','amount_minor'=>500,'credit_minor'=>0,'credit_id'=>null,'reason'=>'recovery','at'=>'2026-11-01T00:00:00+00:00'],
        'half-b'=>['state'=>'refunded','amount_minor'=>$gross-500,'refund_id'=>'synthetic-half-return','key'=>'fixture-return:half-b']]]);
App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal(500-$gross),'fee'=>'0.00','status'=>'succeeded','transaction_id'=>'fixture-return:half-b']);
App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-5.00','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-half-chargeback']);
$facts=$staffFacts($op);$limit=RequestFacts::refundLimit($op->fresh(),false);
same('Refunded',$facts['refund']??null,'The refunded share settled to the original payment');
$want('Paid'===$facts['invoice_state'],'A request with only part of its line returned stays Paid');
$want($limit!==null&&str_contains($limit,Paymenter\Extensions\Servers\Domains\Services\Storefront::money(Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal($gross-500)))
    &&str_contains((string)($facts['refund_sub']??''),$limit),'Staff see the smaller amount actually returned');
// A late payment on this request already has a gateway return in progress, so nothing is held yet nothing is lost:
// staff see no reason until the return finishes.
$op=$held('request-invoice-late-return-placeholder.com');$op->update(['state'=>'rejected']);$invoice=App\Models\Invoice::findOrFail($op->invoice_id);
App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-late-return']);
MoneyReceiptGateway::automatic($invoice,'synthetic-late-return',Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole()->amount),'pending');
same(0,Paymenter\Extensions\Servers\Domains\Services\InvoiceMoney::project((int)$op->invoice_id)['held'],'The return in progress leaves nothing held');
$facts=$staffFacts($op);
$want(['No credit added',null]===[$facts['refund']??null,$facts['refund_sub']??null],'A return still in progress gives staff no lost or returned reason');
// A rejected provider transfer is never refunded by Domains, so a lost payment gives staff no refund wait reason.
$op=$held('request-invoice-provider-rejected-placeholder.com');$op->update(['state'=>'rejected','safe_code'=>'transfer_provider_rejected']);
App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-provider-rejected']);
App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-'.App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole()->amount,'fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-provider-chargeback']);
same(0,Paymenter\Extensions\Servers\Domains\Services\InvoiceMoney::project((int)$op->invoice_id)['held'],'The lost payment leaves nothing held');
$facts=$staffFacts($op);
$want(['No credit added',null]===[$facts['refund']??null,$facts['refund_sub']??null],'A request no refund runs for gives staff no refund wait reason');
// A refund capped by a lost dispute, then the dispute was won and the rest went back as account credit: nothing is short.
[$op,$a,$b,$gross]=$returnSetup('request-invoice-late-credit-placeholder.com',static function($op,$a,$b,$gross){
    $credit=App\Models\Credit::firstOrCreate(['user_id'=>App\Models\Invoice::findOrFail($op->invoice_id)->user_id,'currency_code'=>'USD'],['amount'=>'0.00']);
    return ['refund'=>['payments'=>[['id'=>(int)$a->id,'amount_minor'=>500]],'amount'=>'5.00','currency'=>'USD','to'=>'account_credit',
        'bridge_reason'=>'gateway_return_unavailable','credit_id'=>(int)$credit->id,'at'=>'2026-11-01T00:00:00+00:00']];
});
$chargeback=App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal(500-$gross),'fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-late-credit-chargeback']);
$capped=RequestFacts::refundLimit($op->fresh(),false);
expect($capped!==null&&str_contains((string)($staffFacts($op)['refund_sub']??''),$capped),'A capped refund tells staff why it is smaller');
// The won dispute reverses the chargeback.
Illuminate\Support\Facades\DB::table('invoice_transactions')->where('id',$chargeback->id)->delete();$funding=$op->fresh()->funding;
$funding['late_credits']=[['payments'=>[['id'=>(int)$b->id,'amount_minor'=>$gross-500]],'amount'=>Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal($gross-500),'currency'=>'USD','to'=>'account_credit','credit_id'=>$funding['refund']['credit_id'],'at'=>'2026-11-02T00:00:00+00:00']];
$op->update(['funding'=>$funding]);
same(0,Paymenter\Extensions\Servers\Domains\Services\InvoiceMoney::project((int)$op->invoice_id)['held'],'The later credit returned the rest');
$want(RequestFacts::refundLimit($op->fresh(),false)===null&&!str_contains((string)($staffFacts($op)['refund_sub']??''),$capped),'Money returned later as account credit counts toward the return');
expect($followups===[],'Request money follow-ups: '.json_encode($followups,JSON_THROW_ON_ERROR));
$nativeSuccessful=true;
