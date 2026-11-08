<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,InvoiceItem};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Informational receipts, never native payment events or registrar funding grants. */
final class ComplimentaryInvoices
{
    public static function create(int $user,string $description,?int $service=null,?string $due=null):InvoiceItem
    {
        $invoice=new Invoice(['user_id'=>$user,'currency_code'=>'USD','status'=>'pending','due_at'=>$due??now()]);
        $invoice->send_create_email=false;$invoice->save();
        return $invoice->items()->create(['description'=>mb_substr($description,0,190).': complimentary, registrar cost awaiting confirmation','price'=>'0.00','quantity'=>1,
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
        if($op->domain_id!==$l['domain']->id||$op->service_id!==$l['domain']->service_id||$op->server_id!==$l['domain']->server_id
            ||$op->account_fingerprint!==$l['domain']->account_fingerprint||$op->authority_generation!==$l['domain']->authority_generation
            ||$op->funding_basis!=='complimentary'||PricingRule::minor($op->amount)!==0||!$invoice||!$item||$invoice->status!=='paid'
            ||(int)$invoice->user_id!==(int)$l['domain']->user_id||$invoice->currency_code!=='USD'||(int)$item->invoice_id!==$op->invoice_id
            ||!self::receipt($op,$invoice,$item,$items,$l['transactions']->where('invoice_id',$op->invoice_id))) {throw new \DomainException('Complimentary receipt authority changed.');}
        return ['basis'=>'complimentary','authorization'=>$a,'invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id];
    }
    /** Exact informational receipt, including its confirmed charge/offset pair. Never accepts a payment. */
    public static function receipt(Operation $op,Invoice $invoice,InvoiceItem $item,$items,$transactions):bool
    {
        try{
            $auth=$op->policy['account_complimentary']??[];ComplimentaryAccounts::require($auth,(int)$invoice->user_id);
            if($op->funding_basis!=='complimentary'||PricingRule::minor($op->amount)!==0||$op->currency!=='USD'
                ||$invoice->id!==$op->invoice_id||$invoice->currency_code!=='USD'||$invoice->status!=='paid'
                ||$item->id!==$op->invoice_item_id||(int)$item->invoice_id!==$invoice->id||$transactions->isNotEmpty()
                ||$items->contains(fn($i)=>(string)$i->quantity!=='1')||$items->sum(fn($i)=>PricingRule::minor($i->price))!==0){return false;}
            if($items->count()===1){return PricingRule::minor($item->price)===0;}
            if($items->count()!==2||$op->state!=='succeeded'){return false;}
            $cost=$op->cost['actual']??null;
            if(!is_array($cost)||($cost['currency']??null)!=='USD'){return false;}
            $amount=self::nativeCharge($cost['amount']);$offset=$items->firstWhere('id','!==',$item->id);
            return PricingRule::minor($item->price)===PricingRule::minor($amount)&&$offset!==null
                &&PricingRule::minor($offset->price)===-PricingRule::minor($amount)&&$offset->description==='Complimentary'
                &&$offset->reference_type===null&&$offset->reference_id===null;
        }catch(\Throwable){return false;}
    }
    private static function nativeCharge(string $amount):string
    {
        [$whole,$fraction]=explode('.',$amount);
        if(strlen($whole)>10){throw new \DomainException('Registrar charge exceeds native invoice precision.');}
        return PricingRule::decimal((int)$whole*100+(int)str_pad(substr($fraction,0,2),2,'0')+((int)($fraction[2]??0)>=5?1:0));
    }
    /** Only confirmed actual registrar charges enter the receipt. Caller holds the native invoice prefix. */
    public static function complete(Operation $op):void
    {
        if(!isset($op->policy['account_complimentary'])||$op->state!=='succeeded'){return;}
        $cost=$op->cost['actual']??null;if(!$cost||$cost['currency']!=='USD'){throw new \DomainException('Actual registrar charge required.');}
        $invoice=Invoice::findOrFail($op->invoice_id);$item=InvoiceItem::findOrFail($op->invoice_item_id);
        $description=InvoiceDescription::complimentary($op->domain->domain,$op->action,(int)$op->term);
        // Native invoices store cents; retain the exact provider amount in the operation.
        $amount=self::nativeCharge($cost['amount']);
        if($invoice->items()->count()===2){return;}
        if($invoice->status!=='paid'||(float)$invoice->total!==0.0||$invoice->items()->count()!==1||$invoice->transactions()->exists()){throw new \DomainException('Complimentary receipt changed.');}
        // Exact owned receipt only. Suppressing observers prevents Paid/InvoiceItem hooks from launching a second path.
        $item->forceFill(['price'=>$amount,'description'=>$description])->saveQuietly();
        $discount=new InvoiceItem(['invoice_id'=>$invoice->id,'price'=>'-'.$amount,'quantity'=>1,'description'=>'Complimentary','reference_type'=>null,'reference_id'=>null]);
        $discount->saveQuietly();
    }
}
