<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{CartItem,Invoice,Service,User};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
/** Read-only ownership-scoped adapter for themes. No provider I/O, locks or cached actor state. */
final readonly class NativePresenter
{
    public function __construct(private OwnedDomains $owned,private NativeCatalogue $catalogue,private HolderPricing $prices) {}
    public function service(User $owner,int $serviceId): ?OwnedDomain { return $this->services($owner,[$serviceId])[$serviceId]??null; }
    /** Bounded batch keyed by native service ID. Both native and ledger ownership must match. */
    public function services(User $owner,array $ids): array
    {
        if(count($ids)>250){throw new \InvalidArgumentException('At most 250 services per batch.');}
        $result=[];
        foreach(Domain::whereIn('service_id',$ids)->where('user_id',$owner->id)->whereHas('service',fn($q)=>$q->where('user_id',$owner->id))
            ->with(['service.cancellation','service.user'])->get() as $d){$result[(int)$d->service_id]=$this->owned->present($d,false);}
        return $result;
    }
    /** Neutral watch/managed summaries for hosting rows; preserve the original domain-only adapter. */
    public function domainSummaries(User $owner,array $serviceIds): array
    { return (new DomainSummaries())->services($owner,$serviceIds); }
    /** Exact invoice-item binding; a current service alone cannot impersonate an issued domain line. */
    public function invoice(User $owner,int $invoiceId): array
    {
        $invoice=Invoice::whereKey($invoiceId)->where('user_id',$owner->id)->first();if(!$invoice){return [];}
        $items=\App\Models\InvoiceItem::where('invoice_id',$invoiceId)->get()->keyBy('id');
        $ops=Operation::where('invoice_id',$invoiceId)->where('currency',$invoice->currency_code)->whereIn('invoice_item_id',\App\Models\InvoiceItem::where('invoice_id',$invoiceId)->select('id'))->orderBy('id')->limit(250)->get();
        $dtos=$this->services($owner,$ops->pluck('service_id')->unique()->all());$result=[];
        foreach($ops as $op){$dto=$dtos[(int)$op->service_id]??null;if($dto&&isset($items[$op->invoice_item_id])&&$this->line($items[$op->invoice_item_id],$op)
                &&Domain::whereKey($op->domain_id)->where('service_id',$op->service_id)->where('user_id',$owner->id)->where('account_fingerprint',$op->account_fingerprint)->where('authority_generation',$op->authority_generation)->exists()){$result[(int)$op->invoice_item_id]=$dto;}}
        return $result;
    }
    private function line(\App\Models\InvoiceItem $item,Operation $op): bool
    {
        $service=$item->reference_type===(new Service)->getMorphClass()&&(int)$item->reference_id===(int)$op->service_id;
        $retained=in_array($op->action,['renew','privacy_purchase','privacy_renew'],true)&&$item->reference_type===null&&$item->reference_id===null;
        try{return ($service||$retained)&&(string)$item->quantity==='1'&&PricingRule::minor((string)$item->price)===PricingRule::minor((string)$op->amount);}catch(\Throwable){return false;}
    }
    /** Neutral owner-scoped edit target; no receipt or provider identity enters the URL. */
    public function checkout(User $owner,CartItem $item): ?array
    {
        if((int)$item->cart->user_id!==(int)$owner->id){return null;}
        $mapping=$this->catalogue->mapping($item->product,$item->plan);if(!$mapping){return null;}
        $name=$item->checkout_config['domain']??null;
        try{$name=\Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse((string)$name,[$mapping['tld']->tld])['domain'];}catch(\Throwable){return null;}
        $renewal=null;$t=$mapping['tld'];
        try{$price=$this->prices->renewal(new Domain(['server_id'=>$t->server_id,'account_fingerprint'=>$t->account_fingerprint,'tld'=>$t->tld]),'USD',app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now());$renewal=Storefront::money($price->retail);}catch(\Throwable){}
        return ['name'=>$name,'action'=>$mapping['action']==='transfer'?'Transfer':'Registration','years'=>$mapping['term'],
            'renewalPrice'=>$renewal,'editUrl'=>route('domains.cart.edit',['item'=>$item->id])];
    }
}
