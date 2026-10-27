<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,Server,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Adoption,Domain,Price};
final class BalanceForecast
{
    public static function for(Account $account, \DateTimeImmutable $now): array
    {
        $total='0.000000';$unknown=0;$count=0;
        $settings=Server::find($account->server_id)?->settings()->whereIn('key',['domains_issue_days','domains_due_days'])->get();
        try{$timing=$settings?BillingCalendar::timing($settings):null;}catch(\Throwable){$timing=null;}
        $rates=Price::where('server_id',$account->server_id)->where('account_fingerprint',$account->account_fingerprint)
            ->where('action','renew')->where('currency','USD')->get();
        Domain::where('server_id',$account->server_id)->where('account_fingerprint',$account->account_fingerprint)
            ->whereIn('status',['active','expired','grace'])->with(['service','operations'])->chunkById(100,function($domains)use($rates,$now,$timing,&$total,&$unknown,&$count):void {
            $serviceIds=$domains->pluck('service_id')->all();
            $cancel=NativeCancellation::withIntents(ServiceCancellation::whereIn('service_id',$serviceIds)->get(),$serviceIds,true)->groupBy('service_id');
            $adoptionIds=$domains->pluck('adoption_id')->filter();
            $adoptions=$adoptionIds->isEmpty()?collect():Adoption::whereKey($adoptionIds)->get()->keyBy('id');
            $invoices=Invoice::whereKey($domains->flatMap(fn($d)=>$d->operations->pluck('invoice_id'))->filter()->unique())->get()->keyBy('id');
            foreach($domains as $d){
                if(!$d->billing_managed||!$d->service||$d->service->status!=='active'
                    ||($d->renewal_policy['automatic_spend_paused']??false)){continue;}
                $stop=['domain'=>$d,'adoption'=>$adoptions->get($d->adoption_id),'cancel'=>$cancel->get($d->service_id,collect())];if(RenewalStop::blocksRenewal($stop)){continue;}
                $ops=$d->operations->filter(fn($o)=>$o->action==='renew'&&$o->account_fingerprint===$d->account_fingerprint
                    &&$o->registrar_key===$d->registrar_key&&(int)$o->server_id===(int)$d->server_id&&$o->authority_generation===$d->authority_generation);
                $open=$ops->filter(fn($o)=>in_array($o->state,['awaiting_payment','queued','held','running'],true)
                    &&$o->safe_code!=='renewal_cancelled_unpaid'&&!RenewalReplacements::reviewed($o,$d)&&!isset($o->funding['refund'])&&!self::cancelledInvoice($o,$invoices)&&!self::sent($o,$d));
                // Forecast the earlier known invoice due date or confirmed registrar expiry. Unsent open
                // obligations count even outside 30 days; overdue unsent renewals count as due now.
                // No known date or cost means unknown, never zero.
                // Future-only stops preserve issued obligations; unissued stopped renewals do not count.
                // Cancelled invoices, retired/cancelled holdings and sent/completed cycles do not count.
                if($open->isNotEmpty()){
                    foreach($open as $op){
                        $count++;$due=$invoices->get($op->invoice_id)?->due_at?->format('Y-m-d')??($op->policy['calendar']['due']??null);
                        $cost=self::cost($rates,$d->tld,(int)$op->term,$now);
                        if(self::withinWindow($due,$d->provider_expires_at?->format('Y-m-d'),$now)===null||$cost===null){$unknown++;}else{$total=self::add($total,$cost);}
                    }
                    continue;
                }
                if(RenewalStop::stopped($stop)){continue;}
                $expiry=$d->provider_expires_at?->format('Y-m-d');
                $cycle=$ops->filter(fn($o)=>$o->base_expires_at?->format('Y-m-d')===$expiry);
                if($cycle->contains(fn($o)=>self::sent($o,$d)||in_array($o->state,['succeeded','rejected','cancelled'],true)
                    ||$o->safe_code==='renewal_cancelled_unpaid'||self::cancelledInvoice($o,$invoices)||RenewalReplacements::reviewed($o,$d))){continue;}
                $due=$expiry&&$timing?BillingCalendar::dates($d->provider_expires_at,$timing['issue'],$timing['due'])['due']:null;
                // A retained invoice may provide the only known date; it is never derived from native service expiry.
                foreach($cycle as $op){$invoiceDue=$invoices->get($op->invoice_id)?->due_at?->format('Y-m-d');if($invoiceDue){$due=$due?min($due,$invoiceDue):$invoiceDue;}}
                $within=self::withinWindow($due,$expiry,$now);
                if($within===null){$unknown++;$count++;continue;}
                if(!$within){continue;}
                $count++;$cost=self::cost($rates,$d->tld,1,$now);
                if($cost===null){$unknown++;}else{$total=self::add($total,$cost);}
            }
        });
        return ['total'=>$total,'currency'=>'USD','unknown'=>$unknown,'count'=>$count];
    }
    /** Earlier of the Paymenter renewal due date and confirmed expiry; a missing pair stays unknown. */
    public static function withinWindow(?string $due,?string $expiry,\DateTimeImmutable $now): ?bool
    {
        $dates=array_filter([$due,$expiry]);if(!$dates){return null;}
        $date=min($dates);$utc=$now->setTimezone(new \DateTimeZone('UTC'));
        return $date<=$utc->modify('+30 days')->format('Y-m-d');
    }
    private static function cancelledInvoice($op,$invoices): bool
    {return $op->invoice_id!==null&&$invoices->get($op->invoice_id)?->status==='cancelled';}
    private static function sent($op,Domain $domain): bool
    {
        // A sealed local prewire refusal is still unsent, but only with the full retained proof.
        if($op->state==='held'&&$op->safe_code==='renewal_definitely_unsent'){
            try{RefundProof::requireUnsent($op,$domain);return false;}catch(\DomainException){}
        }
        $a=$op->attempt??[];
        return in_array($op->state,['pending','uncertain','succeeded'],true)||($a['seal']??null)==='sealed'
            ||array_key_exists('dispatch_attempted_at',$a)||array_key_exists('execution',$a)||array_key_exists('actual',$op->cost??[]);
    }
    private static function cost($rates,string $tld,int $years,\DateTimeImmutable $now): ?string
    {
        if($years<1||$years>10){return null;}
        $p=$rates->first(fn($p)=>$p->tld===$tld&&(int)$p->term===$years);
        if(!$p||$p->wholesale===null||$p->unit!=='term_total'||!$p->observed_at||$p->observed_at>$now||!$p->fresh_until||$p->fresh_until<=$now){return null;}
        try{$amount=PriceCatalogue::cost($p->wholesale);}catch(\Throwable){return null;}
        return $amount;
    }
    private static function digits(string $value): string {return ltrim(str_replace('.','',str_pad($value,strpos($value,'.')+7,'0')),'0')?:'0';}
    public static function compare(string $a,string $b): int
    {$a=self::digits($a);$b=self::digits($b);return strlen($a)<=>strlen($b)?:strcmp($a,$b);}
    private static function add(string $a,string $b): string
    {
        $a=strrev(self::digits($a));$b=strrev(self::digits($b));$out='';$carry=0;
        for($i=0;$i<max(strlen($a),strlen($b))||$carry;$i++){$n=(int)($a[$i]??0)+(int)($b[$i]??0)+$carry;$out.=($n%10);$carry=intdiv($n,10);}
        $out=str_pad(strrev($out),7,'0',STR_PAD_LEFT);return substr($out,0,-6).'.'.substr($out,-6);
    }
}
