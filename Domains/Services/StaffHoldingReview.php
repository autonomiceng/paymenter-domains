<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Review a single holding. No catalogue publication or issued-debt mutation. */
final class StaffHoldingReview
{
    public const ACTIONS=['reprice'=>'Update renewal price','stop'=>'Stop renewal','resume'=>'Resume renewal'];
    public function __construct(private readonly NativeDomainGuard $native,private readonly Clock $clock){}
    public static function permission(string $action): string
    {if(!isset(self::ACTIONS[$action])){throw new \DomainException('Unknown holding action.');}return 'admin.domains.holdings.'.$action;}
    public static function stamp(array $l): string
    {
        $ids=$l['operations']->pluck('invoice_id')->filter();$itemIds=$l['operations']->pluck('invoice_item_id')->filter();$service=$l['service'];
        $raw=static fn($rows)=>$rows->map(fn($r)=>$r->getRawOriginal())->values()->all();
        // Closure snapshots record a background observation, not a change in reviewed authority.
        $operations=$l['operations']->map(static function($op):array {
            $row=$op->getRawOriginal();$policy=$op->policy;
            if(in_array($op->action,['register','transfer'],true)){
                unset($policy['native_acquisition_closure'],$policy['native_acquisition_cancelled_at'],$row['updated_at']);
            }
            $row['policy']=$policy;return $row;
        })->values()->all();
        return hash('sha256',json_encode([$l['domain']->getRawOriginal(),$service->getRawOriginal(),$l['server']->getRawOriginal(),
            $l['account']->getRawOriginal(),$l['tld']->getRawOriginal(),$raw($l['prices']),$operations,
            $raw($l['cancel']->where('service_id',$service->id)),$raw($l['invoices']->whereIn('id',$ids)),
            $raw($l['items']->whereIn('id',$itemIds)),$raw($l['transactions']->whereIn('invoice_id',$ids)),
            is_int($l['tax'])?$l['tax']:$l['tax']->getRawOriginal(),config('settings.tax_enabled'),config('settings.tax_type')],JSON_THROW_ON_ERROR));
    }
    public static function ticket(int $actor,int $domain,string $action,string $stamp,Clock $clock): string
    {return Crypt::encryptString(json_encode(['actor'=>$actor,'domain'=>$domain,'action'=>$action,'stamp'=>$stamp,'key'=>bin2hex(random_bytes(16)),'until'=>$clock->now()->getTimestamp()+900],JSON_THROW_ON_ERROR));}
    public function run(User $actor,int $domain,string $action,string $ticket,string $reason): void
    {
        OperationClaims::outside();self::permission($action);$seed=Domain::findOrFail($domain);
        DB::transaction(function()use($actor,$seed,$action,$ticket,$reason):void {
            $l=HoldingLocks::take($seed,(int)$actor->id);$d=$l['domain'];$s=$l['service'];$staff=$l['staff'];
            if(!$staff||!$staff->hasPermission('admin.domains.view')||!$staff->hasPermission('admin.domains.manage')
                ||!$staff->hasPermission(self::permission($action))){throw new \DomainException('Current staff permissions required. Reload this page.');}
            StaffReason::require($reason);
            try{$t=json_decode(Crypt::decryptString($ticket),true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \DomainException('Invalid review key. Reload this page.');}
            if(($t['actor']??null)!==(int)$actor->id||($t['domain']??null)!==(int)$d->id||($t['action']??null)!==$action
                ||!is_int($t['until']??null)||$t['until']<=$this->clock->now()->getTimestamp()||!is_string($t['stamp']??null)
                ||!is_string($t['key']??null)||!hash_equals($t['stamp'],self::stamp($l))){throw new \DomainException('Holding changed. Reload this page and review it again.');}
            $history=$d->renewal_policy['staff_holdings']??[];
            if(!is_array($history)||!array_is_list($history)||count($history)>=100){throw new \DomainException('Holding audit full or unavailable.');}
            if(array_filter($history,fn($r)=>($r['key']??null)===$t['key'])){throw new \DomainException('Review key used.');}
            $before=self::state($l);$price=null;
            if($action!=='stop'){
                $binding=CatalogueAcceptances::binding($l['account']);$acquisitions=$l['operations']->whereIn('action',['register','transfer']);
                $anchor=$l['operations']->firstWhere('operation_key',$d->renewal_policy['billing_anchor']['operation_key']??null);
                if($s->status!=='active'||!$anchor||!RenewalAuthority::completed($anchor,$d,$binding)||$acquisitions->contains(fn($op)=>$op->state!=='succeeded'||!RenewalAuthority::resolved($op,$d,$binding))
                    ||$l['cancel']->where('service_id',$s->id)->contains(fn($r)=>!($r instanceof \App\Models\ServiceCancellation)||$r->type!=='end_of_period')){
                    throw new \DomainException('Review confirmed domain ownership and billing status before resuming or repricing this holding.');}
            }
            if($action==='reprice'){$price=$this->native->project($l);}
            else{
                if($action==='resume'){foreach($l['cancel']->where('service_id',$s->id)as$row){$row->delete();}}
                $d->update(['renewal_policy'=>[...($d->renewal_policy??[]),'renewals_stopped'=>$action==='stop']]);
                // No native Service write: retain its paid status and already-null expiry.
            }
            $history[]=['key'=>$t['key'],'actor_id'=>(int)$staff->id,'action'=>$action,'reason'=>$reason,'at'=>$this->clock->now()->format(DATE_ATOM),
                'before'=>$before,'after'=>self::state($l),'price'=>$price];
            $d->update(['renewal_policy'=>[...($d->renewal_policy??[]),'staff_holdings'=>$history]]);
        });
    }
    private static function state(array $l): array
    {
        $d=$l['domain'];$s=$l['service'];return ['annual_gross'=>RetailLine::decimal($s->price),'currency'=>$s->currency_code,
            'renewals_stopped'=>(bool)($d->renewal_policy['renewals_stopped']??false),
            'native_end_of_period'=>$s->cancellation()->where('type','end_of_period')->exists()];
    }
}
