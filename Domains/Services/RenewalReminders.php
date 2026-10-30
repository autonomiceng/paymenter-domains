<?php
// Adapted from Enom 444f31c Services/Reminders.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Carbon\CarbonImmutable;
/** Produces only scalar customer data from current locked holding/obligation evidence. */
final class RenewalReminders
{
    public static function plan(array $l,object $schedule,\DateTimeImmutable $now,array &$alerts,AccountBindings $bindings):?array
    {
        $d=$l['domain'];$s=$l['service'];$at=$now->getTimestamp();
        $alert=static function(string $code,?int $op=null)use(&$alerts,$d,$at):void {$alerts[]=ObligationAlerts::record($d,$code,$at,$op);};
        if($d->adoptionPending()||RenewalStop::adopted($l)){return null;}
        if($s->status!=='active'||!in_array($d->status,['active','grace','expired'],true)){return null;}
        if(!$d->provider_expires_at){$alert('unknown_expiry');return null;}
        $day=CarbonImmutable::instance($now)->utc()->startOfDay();$expiry=$d->provider_expires_at->utc()->startOfDay();
        $days=(int)$day->diffInDays($expiry,false);$expired=$days<0;
        $cycle=RenewalCycles::current($d,$l['operations']);
        $ops=$cycle->filter(fn($op)=>$op->safe_code!=='renewal_cancelled_unpaid'&&!RenewalReplacements::reviewed($op,$d));
        $retired=$cycle->last()?->safe_code==='renewal_cancelled_unpaid';
        if($retired){$alert('renewal_unpaid');}
        $op=$ops->last();$renewOn=!RenewalStop::stopped($l);
        $rate=$l['prices']->where('term',1)->where('currency','USD')->where('account_fingerprint',$d->account_fingerprint)->first();
        $unknown=false;$complimentary=$d->price_mode==='complimentary';
        if(!$rate||$rate->retail===null||PricingRule::minor(PriceCatalogue::retail($rate->retail))<=0){$alert('unknown_price');$unknown=true;}
        if(!$rate||$rate->wholesale===null||!$rate->fresh_until||!$rate->observed_at||$rate->observed_at>$now){$alert('unknown_cost');}
        if($op&&in_array($op->state,['held','uncertain'],true)){$alert('held_obligation',(int)$op->id);if(!$expired){return null;}}
        if(!$expired&&$op&&in_array($op->state,['queued','running','pending','succeeded'],true)){return null;}
        $settings=$l['settings']->pluck('value','key');
        if(!$expired&&!$op&&$renewOn){try{$calendar=DomainBilling::calendarFor($l);}catch(\Throwable){$alert('reminder_failed');return null;}
            if($day->format('Y-m-d')>=($complimentary?$calendar['due']:$calendar['issue'])){if(!$retired){$alert($complimentary?'missing_execution':'missing_obligation');}return null;}}
        if(!$expired&&$unknown&&!$op&&$renewOn&&!$complimentary){return null;}
        $offsets=[...json_decode($schedule->before_days,true,8,JSON_THROW_ON_ERROR),...array_map(fn($v)=>-$v,json_decode($schedule->after_days,true,8,JSON_THROW_ON_ERROR))];
        $due=array_filter($offsets,fn($offset)=>$offset>=$days&&$offset-$days<=2&&(($days<0)==($offset<0)));
        if(!$due){return null;}$offset=min($due);
        $data=['domain'=>$d->domain,'expires'=>$expiry->format('F j, Y'),'days'=>abs($days),'invoice'=>false,'due'=>null,
            'invoice_on'=>null,'overdue'=>false,'complimentary'=>$complimentary&&$renewOn,'years'=>1,'amount'=>null,'invoice_url'=>null,'domain_url'=>url('/domains/'.$d->id),
            'can_turn_on'=>RenewalStop::offReason($l)==='owner'&&OwnerAutoRenew::available($l)];
        if(!$expired&&$op){
            try{$bindings->requireProcessingLocked($l['server'],$l['account']);
                if(RenewalStop::blocksRenewal($l)||($d->renewal_policy['automatic_spend_paused']??false)){throw new \DomainException('Renewal paused.');}
                RenewalFunding::requireAuthority($op,$l);
            }catch(\Throwable){$alert('held_obligation',(int)$op->id);return null;}
            try{RefundPayment::requireLine($op,$l);}catch(\Throwable){$alert('invalid_invoice',(int)$op->id);return null;}
            $invoice=$l['invoices']->firstWhere('id',$op->invoice_id);$item=$l['items']->firstWhere('id',$op->invoice_item_id);
            if($ops->count()!==1||$op->state!=='awaiting_payment'||$op->funding_basis!=='paid_invoice'||!$invoice||!$item
                ||$invoice->status!=='pending'||(int)$invoice->user_id!==(int)$d->user_id||$invoice->currency_code!==$op->currency||$op->currency!=='USD'
                ||$op->service_id!==$d->service_id||$op->server_id!==$d->server_id||$op->registrar_key!==$d->registrar_key
                ||(int)$item->invoice_id!==(int)$invoice->id||$item->reference_id!==null||$item->reference_type!==null||(string)$item->quantity!=='1'
                ||PricingRule::minor($op->amount)<=0||PricingRule::minor($op->amount)!==PricingRule::minor($item->price)
                ||$l['items']->where('invoice_id',$invoice->id)->count()!==1||!$invoice->due_at
                ||$invoice->due_at->format('Y-m-d')!==($op->policy['calendar']['due']??null)){$alert('invalid_invoice',(int)$op->id);return null;}
            $data=[...$data,'invoice'=>true,'years'=>(int)$op->term,'amount'=>self::money($op->amount),
                'due'=>$invoice->due_at->format('F j, Y'),'overdue'=>$day->format('Y-m-d')>$invoice->due_at->format('Y-m-d'),'invoice_url'=>route('invoices.show',$invoice)];
        }elseif(!$expired&&$renewOn&&!$complimentary){
            // Source's month-out invoice notice requires an enabled, saved billing calendar.
            if($days<0||$offset!==30){return null;}
            if(!filter_var($settings->get('domains_billing_enabled',false),FILTER_VALIDATE_BOOLEAN)
                ||!$l['account']->processing_enabled||$d->price_mode!=='standard'||($d->renewal_policy['automatic_spend_paused']??false)
                ||($d->renewal_policy['billing_anchor']['expiry']??null)!==$expiry->format('Y-m-d')){$alert('missing_obligation');return null;}
            try{DomainBilling::requireAuthority($l,$bindings);}catch(\Throwable){$alert('missing_obligation');return null;}
            $data['invoice_on']=CarbonImmutable::parse($calendar['issue'])->format('F j, Y');
        }
        if(!$expired&&$complimentary&&$renewOn&&$offset!==30){return null;}
        return ['identity'=>'renew:'.$d->authority_generation.':'.$expiry->format('Y-m-d').':'.$offset,
            'template'=>$days<0?'domains_domain_expired':'domains_domain_expiring','data'=>$data];
    }
    public static function money(string $value):string
    {
        if(PricingRule::minor($value)<=0){throw new \DomainException('Known positive amount required.');}
        return (new \App\Classes\Price(['price'=>$value,'currency'=>\App\Models\Currency::where('code','USD')->sole()]))->formatted->price;
    }
}
