<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,InvoiceTransaction,Service,User};
use Illuminate\Support\Facades\{Crypt,DB,Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Owned acquisition closure. Every decision takes the native W/A0 prefix before domain/operations. */
final readonly class AcquisitionRetirement
{
    public const PERMISSION='admin.domains.acquisitions.retire';
    public function __construct(private Clock $clock){}
    public function retire(int $id,?User $actor=null,?string $ticket=null,?string $reason=null): void
    {
        OperationClaims::outside();$seed=Domain::findOrFail($id);
        DB::transaction(function()use($seed,$actor,$ticket,$reason):void {
            $l=HoldingLocks::take($seed,$actor?(int)$actor->id:null);$d=$l['domain'];$s=$l['service'];
            if($actor){$this->review($l,$actor,$ticket,$reason);}
            $op=UnpaidAcquisitionProof::require($l);$invoice=$l['invoices']->firstWhere('id',$op->invoice_id);
            if(!$actor&&($s->status!=='cancelled'||$invoice->status!=='cancelled')){
                if($s->status==='cancelled'){throw new \DomainException('The service is cancelled but its invoice is still open. Review the order and invoice; the name stays reserved.');}return;
            }
            if($invoice->status==='pending'&&$l['items']->where('invoice_id',$invoice->id)->count()!==1){
                throw new \DomainException('This unpaid invoice also contains other items. Cancel it through the native billing workflow before retiring this order.');
            }
            $audit=['actor_id'=>$actor?(int)$l['staff']->id:null,'reason'=>$reason??'Native unpaid cancellation','at'=>$this->clock->now()->format(DATE_ATOM),
                'before'=>['service'=>$s->status,'invoice'=>$invoice->status,'domain'=>$d->status,'operation'=>$op->state],
                'after'=>['service'=>'cancelled','invoice'=>'cancelled','domain'=>'cancelled','operation'=>'rejected']];
            // Intent fencing precedes the native SQL. Retain it after closure; no resend becomes possible.
            if($s->status!=='cancelled'){$s->update(['status'=>'cancelled']);}
            if($invoice->status!=='cancelled'){$invoice->update(['status'=>'cancelled']);}
            $op->update(['state'=>'rejected','safe_code'=>UnpaidAcquisitionProof::CODE,'completed_at'=>$this->clock->now(),
                'attempt'=>UnpaidAcquisitionProof::attempt($op),'policy'=>[...($op->policy??[]),'unpaid_retirement'=>$audit]]);
            $d->update(['status'=>'cancelled']);
            DomainReservations::releaseUnfunded($l);
            DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','acquisition_retirement_review')->whereNull('resolved_at')->update(['resolved_at'=>$this->clock->now()->getTimestamp()]);
        });
    }
    private function review(array $l,User $actor,?string $ticket,?string $reason): void
    {
        $staff=$l['staff'];$d=$l['domain'];
        if(!$staff||!$staff->hasPermission('admin.domains.view')||!$staff->hasPermission('admin.domains.manage')
            ||!$staff->hasPermission(self::PERMISSION)){throw new \DomainException('Current staff permissions required. Reload this page.');}
        if($reason===null||mb_strlen($reason)<5||mb_strlen($reason)>500||$reason!==trim($reason)||preg_match('/[\x00-\x1f\x7f]/',$reason)){
            throw new \DomainException('Enter a one-line reason of 5–500 characters.');
        }
        try{$t=json_decode(Crypt::decryptString($ticket??''),true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \DomainException('Invalid review key. Reload this page.');}
        if(($t['actor']??null)!==(int)$actor->id||($t['domain']??null)!==(int)$d->id||($t['action']??null)!=='retire_unpaid'
            ||!is_int($t['until']??null)||$t['until']<=$this->clock->now()->getTimestamp()||!is_string($t['stamp']??null)
            ||!hash_equals($t['stamp'],StaffHoldingReview::stamp($l))){throw new \DomainException('Order changed. Reload this page and review it again.');}
    }
    /** After-commit optimization only. The account-fair domain cursor is the durable recovery path. */
    public function inspect(int $id): void
    {
        try{
            $d=Domain::find($id);if(!$d){return;}
            $retired=$d->operations()->where('safe_code',UnpaidAcquisitionProof::CODE)->exists();
            if($retired){$this->lateMoney($d);return;}
            $service=Service::find($d->service_id);
            if($d->reservation_name===null||!in_array($d->status,['registration_pending','transfer_pending'],true)||!$service
                ||($service->status!=='cancelled'&&!($service->status==='pending'&&$service->created_at<$this->clock->now()->modify('-'.max(0,(int)config('settings.cronjob_order_cancel',7)).' days')))){return;}
            try{$this->retire($id);}catch(\DomainException){
                DB::transaction(function()use($d):void { $locked=Domain::whereKey($d->id)->lockForUpdate()->firstOrFail();
                    ObligationAlerts::record($locked,'acquisition_retirement_review',$this->clock->now()->getTimestamp()); });
            }
        }catch(\Throwable $e){Log::warning('Domains acquisition retirement deferred.',['domain_id'=>$id,'exception_class'=>$e::class]);}
    }
    private function lateMoney(Domain $seed): void
    {
        // Advisory miss is retried by the durable cursor. Only a hit needs the full money prefix.
        $ids=$seed->operations()->where('safe_code',UnpaidAcquisitionProof::CODE)->pluck('invoice_id');
        if(!InvoiceTransaction::whereIn('invoice_id',$ids)->exists()&&!Invoice::whereKey($ids)->where('status','paid')->exists()
            &&Service::whereKey($seed->service_id)->value('status')!=='active'){return;}
        DB::transaction(function()use($seed):void {
            $l=HoldingNativeLocks::take((int)$seed->user_id,(int)$seed->service_id,seed:$seed);
            $d=Domain::whereKey($seed->id)->lockForUpdate()->firstOrFail();$ops=$d->operations()->orderBy('id')->lockForUpdate()->get();
            foreach($ops->where('safe_code',UnpaidAcquisitionProof::CODE)as$op){
                if($l['transactions']->contains('invoice_id',$op->invoice_id)||$l['invoices']->firstWhere('id',$op->invoice_id)?->status==='paid'||$l['service']->status==='active'){
                    ObligationAlerts::record($d,'acquisition_late_money',$this->clock->now()->getTimestamp(),(int)$op->id);
                }
            }
        });
    }
    public static function boot(): void
    {
        static $booted=false;if($booted){return;}$booted=true;
        Service::updated(static function(Service $s):void {
            if(!$s->wasChanged('status')){return;}$id=(int)$s->id;
            DB::afterCommit(static function()use($id):void {
                try{foreach(Domain::where('service_id',$id)->orderBy('id')->limit(25)->pluck('id')as$d){app(self::class)->inspect((int)$d);}}
                catch(\Throwable $e){Log::warning('Domains acquisition wake deferred.',['service_id'=>$id,'exception_class'=>$e::class]);}
            });
        });
    }
}
