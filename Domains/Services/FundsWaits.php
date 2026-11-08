<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use Illuminate\Support\Facades\{Cache,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Data\BalanceRetry;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
/** Acquisition funds deadline and the existing provider-safe renewal boundary. No provider I/O. */
final class FundsWaits
{
    public const RENEWAL_CLOSED = 'renewal_funds_expired';
    public static function closedRenewal(Operation $op,\Paymenter\Extensions\Servers\Domains\Models\Domain $d): bool
    {
        return $op->action==='renew'&&$op->safe_code===self::RENEWAL_CLOSED&&OperationTerminalProof::safe($op,$d);
    }
    public static function elapsed(Operation $op,\DateTimeImmutable $now): bool
    {
        if($op->action==='renew'){
            return (($deadline=LateRenewalQuote::deadline($op))!==null&&$deadline<=$now)
                ||(isset($op->policy['funds_wait_until'])&&new \DateTimeImmutable($op->policy['funds_wait_until'])<=$now);
        }
        if(!in_array($op->action,['register','transfer'],true)){return false;}
        $retry=BalanceRetry::from($op);
        // Legacy holds have no first-hold timestamp. The attempt start is a conservative upper bound.
        $at=isset($op->policy['funds_wait_at'])?new \DateTimeImmutable($op->policy['funds_wait_at']):
            (in_array($op->safe_code,BalanceRetries::BALANCE_CODES,true)?($retry?new \DateTimeImmutable($retry->record['held_at']):($op->started_at??$op->updated_at)):null);
        return $at!==null&&$at<=$now->modify('-3 days');
    }
    /** Same unsent closure for a collection deadline and a cancelled receipt already in flight. */
    public static function closeRenewal(Operation $op,array $l,\DateTimeImmutable $now,?array $receipts=null): void
    {
        if($op->action!=='renew'||!RenewalCycles::registrarVirgin($op)||!in_array($op->funding_basis,['paid_invoice','complimentary'],true)
            ||$op->base_expires_at?->format('Y-m-d')!==$l['domain']->provider_expires_at?->format('Y-m-d')){throw new \DomainException('Renewal funds outcome needs review.');}
        RefundProof::requireUnsent($op,$l['domain']);
        $complimentary=$op->funding_basis==='complimentary';
        if($complimentary){ComplimentaryInvoices::require($op,$l);$receipts=null;}
        if(!$complimentary&&$receipts===null&&($deadline=LateRenewalQuote::deadline($op))!==null&&$deadline<=$now){
            $rows=$l['transactions']->where('invoice_id',$op->invoice_id);
            if($rows->contains('status',InvoiceTransactionStatus::Processing)){throw new \DomainException('Renewal payment is still processing.');}
            // The cutoff closes collection, even when settled receipts could not fund a renewal.
            $receipts=$rows->pluck('id')->all();
        }
        if(!$complimentary){if($receipts===null){RefundPayment::requireCurrent($op,$l);}else{RefundPayment::requireLine($op,$l);}}
        $attempt=OperationTerminalProof::rejectedAttempt($op,$l['domain']);$attempt['terminal']['invoice_id']=$op->invoice_id;
        // These are retained receipt identities, never the empty zero-money retirement proof.
        if($receipts!==null){$attempt['terminal']['transactions']=$receipts;}
        $op->update(['state'=>'rejected','safe_code'=>self::RENEWAL_CLOSED,'completed_at'=>$now,'attempt'=>$attempt]);
        if(!$complimentary){$l['invoices']->firstWhere('id',$op->invoice_id)->update(['status'=>'cancelled']);}
    }
    public static function sweep(Account $a,\DateTimeImmutable $now): void
    {
        $alerts=Schema::hasTable('domains_obligation_alerts');
        if($alerts){DB::table('domains_obligation_alerts')->where('code','renewal_funds')->whereNull('resolved_at')
            ->whereIn('operation_id',Operation::select('id')->where('server_id',$a->server_id)->whereIn('state',['succeeded','rejected','disposed']))
            ->update(['resolved_at'=>$now->getTimestamp()]);}
        $key='domains.funds-wait-cursor.'.$a->server_id;
        $ops=Operation::where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)
            ->whereIn('action',['register','transfer','renew'])->where(fn($q)=>$q->whereIn('state',['held','queued'])->orWhere(fn($q)=>$q->where('action','renew')->where('state','awaiting_payment')))
            ->where('id','>',(int)Cache::get($key,0))->orderBy('id')->limit(100)->get();
        Cache::forever($key,$ops->count()===100?$ops->last()->id:0);
        foreach($ops as $op){
            if($op->action==='renew'){
                if($alerts&&($op->safe_code==='registrar_balance_insufficient_or_unknown'||isset($op->policy['funds_wait_until']))){
                    DB::transaction(function()use($op,$now):void {
                        $d=$op->domain()->lockForUpdate()->firstOrFail();ObligationAlerts::record($d,'renewal_funds',$now->getTimestamp(),(int)$op->id);
                        if(!isset($op->policy['funds_wait_until'])&&DomainBilling::expired($d,$now)){ObligationAlerts::record($d,'funds_wait_review',$now->getTimestamp(),(int)$op->id);}
                    });
                }
            }
            try{
                if(!self::elapsed($op,$now)){continue;}
                DB::transaction(function()use($op,$now):void {
                    $l=HoldingLocks::take($op->domain()->firstOrFail());$current=$l['operations']->firstWhere('id',$op->id);
                    if(!$current||!in_array($current->state,$current->action==='renew'?['held','queued','awaiting_payment']:['held','queued'],true)||!self::elapsed($current,$now)){return;}
                    if($current->action==='renew'){
                        self::closeRenewal($current,$l,$now);
                        return;
                    }
                    // Queued retries still carry their original claim. Normalize only the proof copy, preserving all evidence.
                    $proof=clone $current;$proof->state='held';$proof->safe_code='registrar_balance_insufficient';
                    RefundProof::requireUnsent($proof,$l['domain']);
                    if($proof->funding_basis==='complimentary'){ComplimentaryInvoices::require($proof,$l);}else{RefundPayment::requireCurrent($proof,$l);}
                    if(isset($proof->attempt['claim_key'])&&!OperationTerminalProof::unsentClaim($proof,$l['domain'],$l['operations'])){throw new \DomainException('Funds hold outcome needs review.');}
                    $current->update(['state'=>'rejected','safe_code'=>'registrar_balance_insufficient','completed_at'=>$now,
                        'attempt'=>OperationTerminalProof::rejectedAttempt($proof,$l['domain'])]);
                    AcquisitionRetirement::retainClosure($current,$l['transactions']->where('invoice_id',$current->invoice_id),
                        $l['invoices']->firstWhere('id',$current->invoice_id)?->status,$now);
                    $l['domain']->update(['status'=>$current->action==='transfer'?'transfer_rejected':'registration_rejected']);
                });
                // A complimentary receipt has no customer payment to return.
                if($op->funding_basis!=='complimentary'){app(Refunds::class)->creditRejected((int)$op->id);}
                if($alerts){DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','funds_wait_review')->whereNull('resolved_at')->update(['resolved_at'=>$now->getTimestamp()]);}
            }catch(\Throwable){
                if($alerts){DB::transaction(function()use($op,$now):void {
                    $d=$op->domain()->lockForUpdate()->firstOrFail();ObligationAlerts::record($d,'funds_wait_review',$now->getTimestamp(),(int)$op->id);
                });}
            }
        }
    }
}
