<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Acknowledge exact late-payment evidence; never change money, reservations or registrar work. */
final class AcquisitionPaymentReviews
{
    public const PERMISSION='admin.domains.acquisitions.review-payment';
    public function __construct(private readonly Clock $clock){}
    public static function allowed(?User $staff): bool
    {
        if(!$staff){return false;}
        foreach(['admin.domains.view','admin.domains.manage-policy','admin.invoices.view',self::PERMISSION]as$p){
            if(!$staff->hasPermission($p)){return false;}
        }
        return true;
    }
    public function ticket(User $actor,int $domain): string
    {
        OperationClaims::outside();$seed=Domain::findOrFail($domain);
        $l=$this->snapshot($seed,$actor,true);
        return StaffHoldingReview::ticket((int)$actor->id,$domain,'acquisition_payment_review',self::stamp($l),$this->clock);
    }
    public function review(User $actor,int $domain,string $ticket,string $reason,bool $confirmed): void
    {
        OperationClaims::outside();$seed=Domain::findOrFail($domain);
        DB::transaction(function()use($actor,$seed,$ticket,$reason,$confirmed):void {
            $l=$this->snapshot($seed,$actor,false);$d=$l['domain'];StaffReason::require($reason);
            if(!$confirmed){throw new \DomainException('Confirm that you reviewed the invoice, payments and account credit.');}
            try{$t=json_decode(Crypt::decryptString($ticket),true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \DomainException('Invalid review key. Reload this page.');}
            $now=$this->clock->now()->getTimestamp();
            if(($t['actor']??null)!==(int)$actor->id||($t['domain']??null)!==(int)$d->id||($t['action']??null)!=='acquisition_payment_review'
                ||!is_int($t['until']??null)||$t['until']<=$now||$t['until']>$now+900
                ||!is_string($t['stamp']??null)||!is_string($t['key']??null)||preg_match('/\A[a-f0-9]{32}\z/',$t['key'])!==1
                ||!hash_equals($t['stamp'],self::stamp($l))){throw new \DomainException('Payment evidence changed. Reload this page and review it again.');}
            $history=$d->renewal_policy['acquisition_payment_reviews']??[];
            if(!is_array($history)||!array_is_list($history)||count($history)>=100){throw new \DomainException('Payment review audit full or unavailable.');}
            if(array_filter($history,fn($r)=>($r['key']??null)===$t['key'])){throw new \DomainException('Review key used.');}
            $ids=$l['alerts']->pluck('id')->map(fn($id)=>(int)$id)->all();
            $history[]=['key'=>$t['key'],'actor_id'=>(int)$actor->id,'reason'=>$reason,'at'=>$this->clock->now()->format(DATE_ATOM),
                'stamp'=>$t['stamp'],'alert_ids'=>$ids];
            if(!$d->update(['renewal_policy'=>[...($d->renewal_policy??[]),'acquisition_payment_reviews'=>$history]])){
                throw new \DomainException('Payment review audit could not be saved.');
            }
            if(DB::table('domains_obligation_alerts')->whereIn('id',$ids)->whereNull('resolved_at')->update(['resolved_at'=>$now])!==count($ids)){
                throw new \DomainException('Payment alerts changed. Reload this page.');
            }
        });
    }
    private function snapshot(Domain $seed,User $actor,bool $advisory): array
    {
        // Match retirement's native prefix, then domain, operations and alerts in ID order.
        $l=HoldingNativeLocks::take((int)$seed->user_id,(int)$seed->service_id,(int)$actor->id,$advisory,$seed,completeInvoices:true);
        if(!self::allowed($l['staff'])){throw new \DomainException('Current staff permissions required. Reload this page.');}
        $d=Domain::whereKey($seed->id)->when(!$advisory,fn($q)=>$q->lockForUpdate())->firstOrFail();
        if($d->user_id!==$seed->user_id||$d->service_id!==$seed->service_id||$d->account_fingerprint!==$seed->account_fingerprint
            ||$d->authority_generation!==$seed->authority_generation||$l['service']->user_id!==$d->user_id){throw new \DomainException('Payment review identity changed. Reload this page.');}
        $ops=$d->operations()->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        $alerts=DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','acquisition_late_money')->whereNull('resolved_at')
            ->orderBy('id')->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        if($alerts->isEmpty()){throw new \DomainException('No late payment alerts need review.');}
        foreach($alerts as$a){
            $op=$ops->firstWhere('id',$a->operation_id);
            if(!$op||!in_array($op->action,['register','transfer'],true)||!$l['invoices']->contains('id',$op->invoice_id)){
                throw new \DomainException('Payment review invoice or request missing.');
            }
        }
        return [...$l,'domain'=>$d,'operations'=>$ops,'alerts'=>$alerts];
    }
    private static function stamp(array $l): string
    {
        $ids=$l['operations']->pluck('invoice_id')->filter();$raw=static fn($rows)=>$rows->map(fn($r)=>$r->getRawOriginal())->values()->all();
        return hash('sha256',json_encode([$l['domain']->getRawOriginal(),$l['service']->getRawOriginal(),$l['credit']?->getRawOriginal(),
            $raw($l['operations']),$raw($l['invoices']->whereIn('id',$ids)),$raw($l['items']->whereIn('invoice_id',$ids)),
            $raw($l['transactions']->whereIn('invoice_id',$ids)),$l['alerts']->map(fn($a)=>[(int)$a->id,$a->alert_key,(int)$a->domain_id,(int)$a->generation,$a->operation_id,$a->code,$a->resolved_at])->all()],JSON_THROW_ON_ERROR));
    }
}
