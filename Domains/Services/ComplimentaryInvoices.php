<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,InvoiceItem};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Informational receipts, never native payment events or registrar funding grants. */
final class ComplimentaryInvoices
{
    public static function create(int $user,string $description,?int $service=null):InvoiceItem
    {
        $invoice=new Invoice(['user_id'=>$user,'currency_code'=>'USD','status'=>'pending','due_at'=>now()]);
        $invoice->send_create_email=false;$invoice->save();
        return $invoice->items()->create(['description'=>$description.': complimentary, registrar cost awaiting confirmation','price'=>'0.00','quantity'=>1,
            'reference_type'=>$service===null?null:(new \App\Models\Service)->getMorphClass(),'reference_id'=>$service]);
    }
    public static function paid(Invoice $invoice):void
    {
        if((float)$invoice->fresh()->total!==0.0||$invoice->transactions()->exists()){throw new \DomainException('Complimentary receipt must have no payment.');}
        $invoice->status='paid';$invoice->saveQuietly();
    }
    public static function require(Operation $op,array $l):array
    {
        $a=$op->policy['account_complimentary']??[];ComplimentaryAccounts::require($a,(int)$l['domain']->user_id);
        $invoice=$l['invoices']->firstWhere('id',$op->invoice_id);$item=$l['items']->firstWhere('id',$op->invoice_item_id);
        $items=$l['items']->where('invoice_id',$op->invoice_id);
        if($op->funding_basis!=='complimentary'||PricingRule::minor($op->amount)!==0||!$invoice||!$item||$invoice->status!=='paid'
            ||(int)$invoice->user_id!==(int)$l['domain']->user_id||$invoice->currency_code!=='USD'||(int)$item->invoice_id!==$op->invoice_id
            ||$items->sum(fn($i)=>PricingRule::minor($i->price)*(int)$i->quantity)!==0||$items->contains(fn($i)=>(string)$i->quantity!=='1')
            ||$l['transactions']->where('invoice_id',$op->invoice_id)->isNotEmpty()) {throw new \DomainException('Complimentary receipt authority changed.');}
        return ['basis'=>'complimentary','authorization'=>$a,'invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id];
    }
    /** Only confirmed actual registrar charges enter the receipt. Caller holds the native invoice prefix. */
    public static function complete(Operation $op):void
    {
        if(!isset($op->policy['account_complimentary'])||$op->state!=='succeeded'){return;}
        $cost=$op->cost['actual']??null;if(!$cost||$cost['currency']!=='USD'){throw new \DomainException('Actual registrar charge required.');}
        $invoice=Invoice::findOrFail($op->invoice_id);$item=InvoiceItem::findOrFail($op->invoice_item_id);
        $description=$op->domain->domain.' '.(['register'=>'registration','renew'=>'renewal','transfer'=>'transfer'][$op->action]).', '.$op->term.' '.($op->term===1?'year':'years');
        $amount=PricingRule::decimal(PricingRule::minor($cost['amount']));
        if($invoice->items()->count()===2){return;}
        if($invoice->status!=='paid'||(float)$invoice->total!==0.0||$invoice->items()->count()!==1||$invoice->transactions()->exists()){throw new \DomainException('Complimentary receipt changed.');}
        // Exact owned receipt only. Suppressing observers prevents Paid/InvoiceItem hooks from launching a second path.
        $item->forceFill(['price'=>$amount,'description'=>$description])->saveQuietly();
        $discount=new InvoiceItem(['invoice_id'=>$invoice->id,'price'=>'-'.$amount,'quantity'=>1,'description'=>'Complimentary','reference_type'=>null,'reference_id'=>null]);
        $discount->saveQuietly();
    }
}
