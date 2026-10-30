<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;

use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{PriceCatalogue,PricingRule,PricingRules};

/** Saved results for the staff sync notification. No provider reads or price calculations. */
final class PriceSyncResult
{
    public static function lines(int $server,array $endings): array
    {
        if($endings===[]){return [];}
        try{
            $tlds=TldFacts::bound(Tld::where('server_id',$server)->whereIn('tld',$endings))->orderBy('tld')->get();
            $prices=Price::where('server_id',$server)->whereIn('tld',$endings)->where('currency','USD')->orderBy('id')->get();
            $rules=new PricingRules;$lines=[];
            foreach($tlds as $tld){
                $line='.'. $tld->tld.': '.($tld->use_default_rule?'account default':'own pricing').', '.PricingRule::describe($rules->rule($tld)).'.';
                $rows=$prices->filter(fn(Price $price)=>$price->tld===$tld->tld&&$price->account_fingerprint===$tld->account_fingerprint&&$price->registrar_key===$tld->registrar_key);
                foreach(PriceCatalogue::sold($tld,$rows) as $price){
                    $action=['register'=>'Registration','renew'=>'Renewal','transfer'=>'Transfer'][$price->action];
                    $line.=' '.$action.' for '.$price->term.' '.($price->term===1?'year':'years').': '.(TldFacts::money($price->retail)??'Missing').'.';
                }
                $lines[]=$line;
            }
            if(count($lines)!==count($endings)){throw new \DomainException('Synced pricing result is missing.');}
            return $lines;
        }catch(\Throwable $error){
            // A display failure must not report that the completed sync failed.
            \Illuminate\Support\Facades\Log::warning('Domains synced pricing result could not be read.',['exception_class'=>$error::class]);
            return ['Prices synced, but the saved pricing result could not be read. Check the TLD list.'];
        }
    }
}
