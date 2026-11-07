<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{CartItem,Invoice,Service,User};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,NativeIntent,Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{InvoiceOutcome,OwnedDomain};
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
            ->with(['service.cancellation','service.user'])->get() as $d){$dto=$this->owned->present($d,false);
                $result[(int)$d->service_id]=new OwnedDomain(...[...get_object_vars($dto),'serviceStatus'=>$d->service->status==='cancelled'?'Cancelled':null]);}
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
                &&Domain::whereKey($op->domain_id)->where('service_id',$op->service_id)->where('user_id',$owner->id)->where('account_fingerprint',$op->account_fingerprint)->where('authority_generation',$op->authority_generation)->exists()){
                if($op->action!=='renew'||$invoice->status!=='pending'||($dto->renewNow['invoice']??null)!==route('invoices.show',$invoice)){
                    $dto=new OwnedDomain(...[...get_object_vars($dto),'renewalDue'=>null]);
                }
                $result[(int)$op->invoice_item_id]=$dto;
            }}
        return $result;
    }
    /** Closed line facts are available at Paid/afterCommit, before any money-return sweep. */
    public function invoiceOutcome(User $owner,int $invoiceId): ?InvoiceOutcome
    {
        $invoice=Invoice::whereKey($invoiceId)->where('user_id',$owner->id)->first();if(!$invoice){return null;}
        $items=$invoice->items()->get()->keyBy('id');$closed=[];$lines=[];$pending=false;
        $ops=Operation::where('invoice_id',$invoiceId)->where('currency',$invoice->currency_code)->where(fn($q)=>$q->whereIn('safe_code',
            ['renewal_cancelled_unpaid',FundsWaits::RENEWAL_CLOSED,UnpaidAcquisitionProof::CODE])->orWhereIn('action',['register','transfer']))->with(['domain.service.order','domain.service.cancellation'])->orderBy('id')->limit(251)->get();
        if($ops->count()>250){throw new \DomainException('Invoice domain history needs review.');}
        foreach($ops as $op){
            $d=$op->domain;$item=$items[$op->invoice_item_id]??null;
            if(!$d||(int)$d->user_id!==(int)$owner->id||(int)$d->service?->user_id!==(int)$owner->id
                ||(int)$d->service?->order?->user_id!==(int)$owner->id||!$item||!$this->line($item,$op)){continue;}
            $terminal=$this->closedLine($op,$d);$native=!$terminal&&$this->nativeAcquisitionClosed($op,$d);
            if(!$terminal&&!$native){continue;}
            $pending=$pending||$native;$closed[]=$op;$lines[]=['kind'=>$native?UnpaidAcquisitionProof::CODE:$op->safe_code,'domain'=>$d->domain];
        }
        if(!$closed){return null;}
        [$money,$amount]=$pending?['pending',null]:$this->outcomeMoney($closed,$invoice);
        $kinds=array_unique(array_column($lines,'kind'));
        return new InvoiceOutcome(count($kinds)===1?reset($kinds):'mixed',$money,$amount,array_values(array_unique(array_column($lines,'domain'))),$lines);
    }
    /** An unfinished native cancellation counts only while a retained lifecycle fence prevents the unsent purchase. */
    private function nativeAcquisitionClosed(Operation $op,Domain $d): bool
    {
        if(!in_array($op->action,['register','transfer'],true)||!in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true)
            ||$op->funding_basis!=='paid_invoice'||$op->domain_id!==$d->id||$op->service_id!==$d->service_id
            ||$op->authority_generation!==$d->authority_generation||$op->account_fingerprint!==$d->account_fingerprint
            ||!in_array($d->status,['registration_pending','transfer_pending','cancelled'],true)
            ||$d->provider_domain_id!==null||$d->provider_order_id!==null||$d->provider_expires_at!==null
            ||($op->attempt??[])!==[]||$op->started_at!==null||$op->completed_at!==null||$op->request_fingerprint!==null
            ||$op->lease_expires_at!==null||$op->provider_order_id!==null||$op->confirmed_expires_at!==null
            ||array_intersect(['claim','seal','refund','refund_authorization'],array_keys($op->funding??[]))){return false;}
        if($d->service->status==='cancelled'||$d->service->cancellation){return true;}
        $intent=NativeIntent::where('service_id',$op->service_id)->value('kind');
        return $intent==='cancellation'||($intent!==null&&isset($op->policy['native_acquisition_closure']));
    }
    /** Historical display follows the issued operation, even after the domain moves to another authority. No refund authority is granted. */
    private function closedLine(Operation $op,Domain $d): bool
    {
        $a=$op->attempt??[];$t=$a['terminal']??[];
        if($op->state!=='rejected'||!$op->completed_at||$op->funding_basis!=='paid_invoice'||$op->domain_id!==$d->id||$op->service_id!==$d->service_id
            ||$op->authority_generation<1||$op->provider_order_id!==null||$op->confirmed_expires_at!==null||$op->lease_expires_at!==null
            ||($a['seal']??null)!=='absent'||($a['send_state']??null)!=='definitely_unsent'||($a['disposition']??null)!=='rejected'
            ||($a['rejection_basis']??null)!=='definitely_unsent'||($t['operation_key']??null)!==$op->operation_key
            ||($t['generation']??null)!==$op->authority_generation||($t['invoice_id']??null)!==$op->invoice_id
            ||array_intersect(array_keys($a),['execution','dispatch_attempted_at'])||array_intersect(array_keys($op->cost??[]),['actual','execution'])
            ||array_key_exists('dispatch_attempted_at',$op->policy??[])){return false;}
        return ($op->action==='renew'&&in_array($op->safe_code,['renewal_cancelled_unpaid',FundsWaits::RENEWAL_CLOSED],true))
            ||(in_array($op->action,['register','transfer'],true)&&$op->safe_code===UnpaidAcquisitionProof::CODE&&$a===UnpaidAcquisitionProof::attempt($op));
    }
    /** Read only retained local results. A capable gateway's unobserved return stays pending, never assumed credit. */
    private function outcomeMoney(array $ops,Invoice $invoice): array
    {
        $minor=0;$states=[];$covered=[];
        foreach($ops as $op){
            foreach([...(isset($op->funding['refund'])?[$op->funding['refund']]:[]),...($op->funding['late_credits']??[])] as $p){
                $minor+=PricingRule::minor($p['amount']);
                foreach($p['payments']??[] as $payment){$covered[$payment['id']]=($covered[$payment['id']]??0)+max(0,$payment['amount_minor']);}
                if(($p['to']??null)==='account_credit'){$states[]='credit';continue;}
                if(($p['bridge_minor']??0)>0){$states[]='credit';}
                foreach($p['allocations']??[] as $a){$states[]=match($op->funding['receipt_return_results'][$a['key']]['state']??null){'refunded'=>'returned','credited'=>'credit',default=>'pending'};}
            }
        }
        foreach($invoice->transactions()->where('status',\App\Enums\InvoiceTransactionStatus::Succeeded)->get() as $row){
            if(PricingRule::minor($row->amount)>($covered[$row->id]??0)){$states[]='pending';}
        }
        $states=array_unique($states);
        $money=!$states||in_array('pending',$states,true)?'pending':(count($states)===1?reset($states):'mixed');
        return [$money,$minor>0?Storefront::money(PricingRule::decimal($minor)):null];
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
        try{$price=$this->prices->renewal(new Domain(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,'tld'=>$t->tld]),'USD',app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now());$renewal=Storefront::money($price->retail);}catch(\Throwable){}
        return ['name'=>$name,'action'=>$mapping['action']==='transfer'?'Transfer':'Registration','years'=>$mapping['term'],
            'renewalPrice'=>$renewal,'editUrl'=>route('domains.cart.edit',['item'=>$item->id])];
    }
}
