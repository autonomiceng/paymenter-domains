<?php
// Enom 444f31c DomainOrders::billPrivacy; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Currency, Invoice};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ManagementProtocol};
use Paymenter\Extensions\Servers\Domains\Data\PrivacyMode;
final class ManagementPrivacy
{
    /** Same-action debt admission is independent of whether an unsent request blocks renewal. */
    public static function pending(array $l,string $action): ?\Paymenter\Extensions\Servers\Domains\Models\Operation
    {
        $op=$l['operations']->first(fn($op)=>$op->action===$action&&isset($op->policy['management'])
            &&in_array($op->state,['awaiting_payment','queued','held','running','pending','uncertain'],true));
        if(!$op){return null;}
        if($op->funding_basis!=='paid_invoice'||!ManagementEvidence::resolved($op,$l['domain'])&&!in_array($op->state,['running','pending','uncertain'],true)){throw new \DomainException('Privacy obligation requires review.');}
        RefundPayment::requireLine($op,$l);return $op;
    }
    public static function invoice(array $data, array $l, ManagementProtocol $driver, Clock $clock): array
    {
        if($driver->capabilities()->privacy!==PrivacyMode::Paid){throw new \DomainException('Paid privacy unsupported.');}
        $retail=$l['settings']->firstWhere('key','domains_privacy_price')?->value;
        if(!is_string($retail)||PricingRule::minor($retail)<=0){throw new \DomainException('Positive privacy retail required.');}
        $price=new \App\Classes\Price(['price'=>$retail,'currency'=>Currency::where('code','USD')->sole()],apply_exclusive_tax:true,tax:$l['tax']);
        $amount=RetailLine::decimal($price->price);$due=$clock->now()->modify('+7 days')->format('Y-m-d');
        $invoice=new Invoice(['user_id'=>$l['domain']->user_id,'currency_code'=>'USD','status'=>Invoice::STATUS_PENDING,'due_at'=>$due]);
        $invoice->send_create_email=false;$invoice->save();
        $item=$invoice->items()->create(['reference_type'=>null,'reference_id'=>null,'quantity'=>1,'price'=>$amount,'description'=>InvoiceDescription::privacy($l['domain']->domain, $data['payload']['privacy_terms']['base'], $clock->now())]);
        $data['policy']['privacy']=['user_id'=>(int)$l['service']->user_id,'order_id'=>(int)$l['service']->order_id,'product_id'=>(int)$l['service']->product_id,
            'annual_plan_id'=>(int)$l['service']->plan_id,'due'=>$due,'base'=>$data['payload']['privacy_terms']['base'],'retail'=>$retail,'issued_line'=>PrivacyRetirement::line($invoice,$item)];
        return array_replace($data,['invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id,'amount'=>$amount,'funding_basis'=>'paid_invoice','state'=>'awaiting_payment']);
    }
    public static function requireLine(\Paymenter\Extensions\Servers\Domains\Models\Operation $op,array $l,Invoice $invoice,\App\Models\InvoiceItem $item): void
    {
        $p=$op->policy['privacy']??[];
        if(array_key_exists('issued_line',$p)&&$p['issued_line']!==PrivacyRetirement::line($invoice,$item)){throw new \DomainException('Issued privacy line changed.');}
        foreach(['user_id','order_id','product_id'] as $k){if(($p[$k]??null)!==(int)$l['service']->$k){throw new \DomainException('Privacy route changed.');}}
        if(($p['annual_plan_id']??null)!==(int)$l['service']->plan_id||$op->term!==1||$item->reference_type!==null||$item->reference_id!==null
            ||$l['items']->where('invoice_id',$invoice->id)->count()!==1||$invoice->due_at?->format('Y-m-d')!==($p['due']??null)) {throw new \DomainException('Privacy line changed.');}
    }
}
