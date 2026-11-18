<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Credit,Invoice,InvoiceItem,Service};
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
        if($op->invoice_id!==null){InvoiceScope::cover($l,(int)$op->invoice_id);} // A scope miss is never a changed receipt.
        $invoice=$l['invoices']->firstWhere('id',$op->invoice_id);$item=$l['items']->firstWhere('id',$op->invoice_item_id);
        $items=$l['items']->where('invoice_id',$op->invoice_id);
        if($op->domain_id!==$l['domain']->id||$op->service_id!==$l['domain']->service_id||$op->server_id!==$l['domain']->server_id
            ||$op->registrar_key!==$l['domain']->registrar_key||$op->account_fingerprint!==$l['domain']->account_fingerprint||$op->authority_generation!==$l['domain']->authority_generation
            ||$op->funding_basis!=='complimentary'||PricingRule::minor($op->amount)!==0||!$invoice||!$item||$invoice->status!=='paid'
            ||(int)$invoice->user_id!==(int)$l['domain']->user_id||$invoice->currency_code!=='USD'||(int)$item->invoice_id!==$op->invoice_id
            ||!self::receipt($op,$invoice,$item,$items,$l['transactions']->where('invoice_id',$op->invoice_id),$l['credit']??null)) {throw new \DomainException('Complimentary receipt authority changed.');}
        return ['basis'=>'complimentary','authorization'=>$a,'invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id];
    }
    /** Every payment on the receipt already returned, as credit or by a settled gateway refund or lost dispute; none processing. */
    private static function returned(Operation $op, Invoice $invoice, $transactions, ?Credit $credit): bool
    {
        $history = ReceiptReturns::complimentaryCredits($op);
        if ($history === null || $transactions->contains(fn ($row) => (int) $row->invoice_id !== (int) $invoice->id)) { return false; }
        if ($transactions->isEmpty()) { return $history['payments'] === []; }
        $ids = $history['credits'];
        // A wallet balance can change after the return. Only its identity corroborates the audit.
        if ($credit && (int) $credit->user_id === (int) $invoice->user_id && $credit->currency_code === $invoice->currency_code) {
            $ids = array_diff($ids, [(int) $credit->id]);
        }
        if ($ids && Credit::whereKey($ids)->where('user_id', $invoice->user_id)->where('currency_code', $invoice->currency_code)->count() !== count($ids)) {
            return false;
        }
        // The dedicated zero receipt has one operation. Use the same funding for the audit and the ledger.
        $money = InvoiceMoney::project($invoice, $transactions, [$op], false);
        foreach ($history['payments'] as $id => $minor) {
            if ($minor > ($money['receipts'][$id]['gross'] ?? 0)) { return false; }
        }
        return InvoiceMoney::settled($money) && !array_filter($money['receipts'], fn ($r) => $r['net'] !== 0);
    }
    /** Exact informational receipt, including its confirmed charge/offset pair. A payment on it counts once returned as credit. */
    public static function receipt(Operation $op,Invoice $invoice,InvoiceItem $item,$items,$transactions,?Credit $credit=null):bool
    {
        try{
            $auth=$op->policy['account_complimentary']??[];ComplimentaryAccounts::require($auth,(int)$invoice->user_id);
            if($op->funding_basis!=='complimentary'||PricingRule::minor($op->amount)!==0||$op->currency!=='USD'
                ||$invoice->id!==$op->invoice_id||$invoice->currency_code!=='USD'||$invoice->status!=='paid'
                ||$item->id!==$op->invoice_item_id||(int)$item->invoice_id!==$invoice->id||!$items->contains('id',$item->id)
                ||$items->contains(fn($i)=>(int)$i->invoice_id!==$invoice->id||(string)$i->quantity!=='1')
                ||$items->sum(fn($i)=>PricingRule::minor($i->price))!==0){return false;}
            $reference = match ($op->action) {
                'register', 'transfer' => $item->reference_type === (new Service)->getMorphClass() && (int) $item->reference_id === $op->service_id,
                'renew' => $item->reference_type === null && $item->reference_id === null,
                default => false,
            };
            if (!$reference || !self::returned($op,$invoice,$transactions,$credit)) { return false; }
            if($items->count()===1){return PricingRule::minor($item->price)===0;}
            if($items->count()!==2||$op->state!=='succeeded'){return false;}
            $cost=$op->cost['actual']??null;
            if(!is_array($cost)||($cost['currency']??null)!=='USD'){return false;}
            $amount=self::nativeCharge($cost['amount']);$offset=$items->firstWhere('id','!==',$item->id);
            return PricingRule::minor($item->price)===PricingRule::minor($amount)&&$offset!==null
                &&PricingRule::minor($offset->price)===-PricingRule::minor($amount)&&$offset->description==='Complimentary'
                &&$offset->reference_type===null&&$offset->reference_id===null;
        }catch(\PDOException $e){throw $e;}catch(\Throwable){return false;}
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
        if(!self::receipt($op,$invoice,$item,$invoice->items,$invoice->transactions()->get())){throw new \DomainException('Complimentary receipt changed.');}
        // Exact owned receipt only. Suppressing observers prevents Paid/InvoiceItem hooks from launching a second path.
        $item->forceFill(['price'=>$amount,'description'=>$description])->saveQuietly();
        $discount=new InvoiceItem(['invoice_id'=>$invoice->id,'price'=>'-'.$amount,'quantity'=>1,'description'=>'Complimentary','reference_type'=>null,'reference_id'=>null]);
        $discount->saveQuietly();
    }
}
