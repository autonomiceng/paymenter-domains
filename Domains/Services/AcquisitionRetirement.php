<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,InvoiceTransaction,Service,ServiceCancellation,User};
use App\Enums\InvoiceTransactionStatus as PaymentStatus;
use Illuminate\Support\Facades\{Crypt,DB,Log,Schema};
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
        if($reason===null){throw new StaffReviewRefused('A review reason is required.');}
        StaffReason::require($reason);
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
            $this->lateMoney($d);
            if($d->operations()->where('safe_code',UnpaidAcquisitionProof::CODE)->exists()){return;}
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
        $service=Service::whereKey($seed->service_id)->value('status');
        $cancelled=ServiceCancellation::where('service_id',$seed->service_id)->exists()
            ||(Schema::hasTable('domains_native_intents')&&DB::table('domains_native_intents')->where('service_id',$seed->service_id)->exists());
        // Quiet closed history never locks the owner's unrelated hosting/money rows.
        $hit=$seed->operations()->whereIn('action',['register','transfer'])->get()->contains(function($op)use($seed,$service,$cancelled){
            return self::needsSnapshot($op,$service)||(self::closed($op,$seed,$service,$cancelled)&&self::activity($op,$service,
                Invoice::whereKey($op->invoice_id)->value('status'),InvoiceTransaction::where('invoice_id',$op->invoice_id)->orderBy('id')->get()));
        });
        if(!$hit){return;}
        DB::transaction(function()use($seed):void {
            $l=HoldingNativeLocks::take((int)$seed->user_id,(int)$seed->service_id,seed:$seed);
            $d=Domain::whereKey($seed->id)->lockForUpdate()->firstOrFail();$ops=$d->operations()->orderBy('id')->lockForUpdate()->get();
            $service=$l['service']->status;$cancelled=$l['cancel']->where('service_id',$d->service_id)->isNotEmpty();
            foreach($ops->whereIn('action',['register','transfer'])as$op){
                $transactions=$l['transactions']->where('invoice_id',$op->invoice_id);$status=$l['invoices']->firstWhere('id',$op->invoice_id)?->status;
                if(self::needsSnapshot($op,$service)){
                    self::retainClosure($op,$transactions,$status,$this->clock->now(),cancelled:true);
                    continue;
                }
                if(!self::closed($op,$d,$service,$cancelled)||!self::activity($op,$service,$status,$transactions)){continue;}
                // Existing alert identities distinguish later activity from repeated observations.
                $identity=hash('sha256',json_encode([$service,$status,$transactions->map(fn($t)=>[(int)$t->id,$t->status->value,(string)$t->amount])->values()->all()],JSON_THROW_ON_ERROR));
                ObligationAlerts::record($d,'acquisition_late_money',$this->clock->now()->getTimestamp(),(int)$op->id,$identity);
            }
        });
    }
    private static function closed($op,Domain $d,?string $service,bool $cancelled): bool
    {
        if(isset($op->policy['native_acquisition_closure'])){return true;}
        if(isset($op->funding['refund_authorization'])){return true;}
        if($op->state==='succeeded'){return false;}
        return $op->safe_code===UnpaidAcquisitionProof::CODE||isset($op->funding['refund'])
            ||(($service==='cancelled'||$cancelled)&&in_array($d->status,['registration_pending','transfer_pending','cancelled'],true));
    }
    private static function needsSnapshot($op,?string $service): bool
    {return $service==='cancelled'&&$op->safe_code!==UnpaidAcquisitionProof::CODE&&!isset($op->funding['refund'])&&(!isset($op->policy['native_acquisition_closure'])||!isset($op->policy['native_acquisition_cancelled_at']));}
    /** Caller already owns the native invoice/service prefix and the operation lock. */
    public static function retainClosure(\Paymenter\Extensions\Servers\Domains\Models\Operation $op,\Illuminate\Support\Collection $transactions,?string $invoice,\DateTimeInterface $at,bool $cancelled=false): void
    {
        if(!in_array($op->action,['register','transfer'],true)||$op->safe_code===UnpaidAcquisitionProof::CODE
            ||isset($op->funding['refund'])){return;}
        $policy=$op->policy??[];
        if(!isset($policy['native_acquisition_closure'])){$policy['native_acquisition_closure']=[
            'at'=>$at->format(DATE_ATOM),'payments'=>self::payments($transactions),'invoice_status'=>$invoice];}
        if($cancelled&&!isset($policy['native_acquisition_cancelled_at'])){$policy['native_acquisition_cancelled_at']=$at->format(DATE_ATOM);}
        if($policy!==($op->policy??[])){$op->update(['policy'=>$policy]);}
    }
    private static function payments(\Illuminate\Support\Collection $transactions): array
    {return $transactions->map(fn($t)=>[(int)$t->id,$t->status->value,(string)$t->amount])->values()->all();}
    private static function activity($op,?string $service,?string $invoice,\Illuminate\Support\Collection $transactions): bool
    {
        if($service==='active'&&(isset($op->policy['native_acquisition_cancelled_at'])||!isset($op->funding['refund_authorization'])||isset($op->funding['refund']))){return true;}
        $closure=$op->policy['native_acquisition_closure']??null;
        if(is_array($closure)){return self::payments($transactions)!==$closure['payments']||($invoice==='paid'&&$closure['invoice_status']!=='paid');}
        $refund=$op->funding['refund']??null;
        if(is_array($refund)&&($refund['to']??null)==='account_credit'){
            $payments=$transactions->filter(fn($t)=>$t->status===PaymentStatus::Succeeded)
                ->map(fn($t)=>['id'=>(int)$t->id,'amount_minor'=>PricingRule::minor($t->amount)])->values()->all();
            return $payments!==($refund['payments']??null)||$transactions->contains(fn($t)=>$t->status===PaymentStatus::Processing);
        }
        return $transactions->isNotEmpty()||$invoice==='paid';
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
