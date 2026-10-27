<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,InvoiceItem};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Exact owner retirement of an unattempted privacy charge. Historical markers stay in review. */
final class PrivacyRetirement
{
    public static function line(Invoice $invoice,InvoiceItem $item): array
    {
        return ['invoice_id'=>(int)$invoice->id,'invoice_item_id'=>(int)$item->id,'description'=>$item->description,
            'quantity'=>(string)$item->quantity,'price'=>(string)$item->price,'reference_type'=>$item->reference_type,'reference_id'=>$item->reference_id];
    }
    public static function terminal(Operation $op,Domain $d,int $actor,\DateTimeImmutable $at): array
    {
        return ['actor_id'=>$actor,'at'=>$at->format(DATE_ATOM),'operation_key'=>$op->operation_key,'domain_id'=>$d->id,
            'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,
            'generation'=>$d->authority_generation,'invoice_id'=>$op->invoice_id,'invoice_item_id'=>$op->invoice_item_id,'transactions'=>[]];
    }
    public static function safe(Operation $op,Domain $d): bool
    {
        try {
            $a=$op->attempt??[];$p=$op->policy??[];$m=$p['management']??[];$line=$p['privacy']['issued_line']??null;
            if(!in_array($op->action,['privacy_purchase','privacy_renew'],true)||$op->state!=='rejected'||$op->safe_code!=='management_retired'
                ||$op->domain_id!==$d->id||$op->service_id!==$d->service_id||$op->server_id!==$d->server_id||$op->registrar_key!==$d->registrar_key
                ||$op->account_fingerprint!==$d->account_fingerprint||$op->authority_generation<1||$op->authority_generation!==$d->authority_generation
                ||$op->funding_basis!=='paid_invoice'||$op->currency!=='USD'||$op->term!==1||PricingRule::minor($op->amount)<=0
                ||!$op->invoice_id||!$op->invoice_item_id||!$op->completed_at||$op->lease_expires_at!==null||$op->request_fingerprint!==null
                ||$op->provider_order_id!==null||$op->confirmed_expires_at!==null
                ||array_diff(array_keys($p),['management','privacy','staff_actions'])||array_diff(array_keys($op->funding??[]),['refund'])
                ||array_diff(array_keys($op->cost??[]),['estimated','currency','unit'])
                ||array_diff(array_keys($m),['actor_id','reason','input','at','generation'])
                ||array_diff(array_keys($p['privacy']??[]),['user_id','order_id','product_id','annual_plan_id','due','base','retail','issued_line'])
                ||($m['actor_id']??null)!==(int)$d->user_id||(int)$op->authorized_by!==(int)$d->user_id
                ||($m['reason']??null)!=='owner_request'||($m['generation']??null)!==$op->authority_generation
                ||($a['seal']??null)!=='absent'||($a['send_state']??null)!=='definitely_unsent'
                ||array_diff(array_keys($a),['seal','send_state','management_terminal','management_claim','management_deferrals'])){return false;}
            $at=$op->completed_at->toDateTimeImmutable();$issued=self::instant($m['at']??null);
            if(!$issued||$issued>$at||($a['management_terminal']??null)!==self::terminal($op,$d,(int)$d->user_id,$at)
                ||!is_array($line)||array_keys($line)!==['invoice_id','invoice_item_id','description','quantity','price','reference_type','reference_id']
                ||$line['invoice_id']!==$op->invoice_id||$line['invoice_item_id']!==$op->invoice_item_id
                ||!is_string($line['description'])||$line['description']===''||$line['quantity']!=='1'||$line['price']!==$op->amount
                ||$line['reference_type']!==null||$line['reference_id']!==null){return false;}
            if(array_key_exists('management_claim',$a)){
                if(!is_string($a['management_claim'])||!preg_match('/^[a-f0-9]{32}$/D',$a['management_claim'])||!$op->started_at
                    ||$op->started_at<$issued||$op->started_at>$at){return false;}
            }elseif($op->started_at!==null||array_key_exists('management_deferrals',$a)){return false;}
            if(!ManagementEvidence::deferrals($a)){return false;}
            if(array_key_exists('management_deferrals',$a)&&$a['management_deferrals']===[]){return false;}
            $last=$issued;
            foreach($a['management_deferrals']??[] as $value){$date=self::instant($value);if(!$date||$date<$last||$date>$at){return false;}$last=$date;}
            return true;
        }catch(\Throwable){return false;}
    }
    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        if(!is_string($value)){return null;}$date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$value);
        return $date&&$date->format(DATE_ATOM)===$value?$date:null;
    }
}
