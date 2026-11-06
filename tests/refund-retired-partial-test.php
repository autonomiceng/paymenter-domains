<?php
// Native renewal refunds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$renewalFixtureNames=['refund-retired-partial'];
require __DIR__.'/Support/renewal-refund.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
// A retired invoice with only a partial/failed payment cannot borrow the replacement payment.
[$partial,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('refund-retired-partial-placeholder.com'),false); $cycles->retireUnpaid($partial->id);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
$before=$credit();
same(['amount'=>'1.00','currency'=>'USD'],$refunds->creditRejected($partial->id),'Partial retired payment returns immediately');
same(null,$refunds->creditRejected($partial->id),'Partial return retries once');
commerceReject(fn()=>DB::transaction(fn()=>RenewalCycles::next(HoldingLocks::take($d)))); // Returning an installment does not authorize a replacement cycle.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.75','fee'=>'0.00','status'=>'succeeded']);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'-0.25','fee'=>'0.00','status'=>'succeeded']);
same(['amount'=>'0.50','currency'=>'USD'],$refunds->creditRejected($partial->id),'New money nets the reversal and earlier credits');
same($before+150,$credit(),'Incremental credit never exceeds net settlement');
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'-0.50','fee'=>'0.00','status'=>'succeeded']);
commerceReject(fn()=>$refunds->creditRejected($partial->id));
expect(DB::table('domains_obligation_alerts')->where('operation_id',$partial->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Post-credit reversal needs durable repair');
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.50','fee'=>'0.00','status'=>'succeeded']);
same(null,$refunds->creditRejected($partial->id),'Repaired net settlement creates no extra money');
same(0,DB::table('domains_obligation_alerts')->where('operation_id',$partial->id)->where('code','refund_repair')->whereNull('resolved_at')->count(),'Successful retry resolves repair');
// A changed invoice line is never guessed; restoring the exact line lets the same retry recover.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.25','fee'=>'0.00','status'=>'succeeded']);
DB::table('invoice_items')->where('id',$partial->invoice_item_id)->update(['quantity'=>2]);
commerceReject(fn()=>$refunds->creditRejected($partial->id));
expect(DB::table('domains_obligation_alerts')->where('operation_id',$partial->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Line refusal survives the failed transaction');
DB::table('invoice_items')->where('id',$partial->invoice_item_id)->update(['quantity'=>1]);
same(['amount'=>'0.25','currency'=>'USD'],$refunds->creditRejected($partial->id),'Repair retries original obligation');
// Marker failure rolls an incremental credit back together with its payment allocation.
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'0.25','fee'=>'0.00','status'=>'succeeded']);
$failLate=true;$before=$credit();
Operation::updating(static function($row)use($partial,&$failLate):void {
    if($failLate&&$row->id===$partial->id&&count($row->funding['late_credits']??[])>count($row->getOriginal('funding')['late_credits']??[])){throw new DomainException('Synthetic late marker failure');}
});
commerceReject(fn()=>$refunds->creditRejected($partial->id));same($before,$credit(),'Crash before late marker rolls money back');
$failLate=false;$refunds->creditRejected($partial->id);same($before+25,$credit(),'Late retry returns the same incremental amount once');
same([],array_keys($registrations),'Every independent refund scenario ran');
echo "Retired renewal partial settlement reversal and repair passed\n"; $nativeSuccessful=true;
