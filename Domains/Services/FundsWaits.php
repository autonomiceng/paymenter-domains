<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use Illuminate\Support\Facades\{Cache,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionResult, AcquisitionResultSchema, OperationWriter, Transition};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Money\Returns\RefundPayment;
/** Acquisition funds deadline and the existing provider-safe renewal boundary. No provider I/O. */
final class FundsWaits
{
    public const RENEWAL_CLOSED = 'renewal_funds_expired';
    private const KIND = 'funds_wait';
    /** Days a new order waits for registrar funds before its money returns as account credit. */
    public const WAIT_DAYS = 3;
    public static function closedRenewal(Operation $op,\Paymenter\Extensions\Servers\Domains\Models\Domain $d): bool
    {
        return $op->action==='renew'&&$op->safe_code===self::RENEWAL_CLOSED&&OperationTerminalProof::safe($op,$d);
    }
    public static function elapsed(Operation $op,\DateTimeImmutable $now,bool $lock=false): bool
    {
        if($op->action==='renew'){
            return (($deadline=LateRenewalQuote::deadline($op))!==null&&$deadline<=$now)
                ||(isset($op->policy['funds_wait_until'])&&new \DateTimeImmutable($op->policy['funds_wait_until'])<=$now);
        }
        return self::deadlinePassed(AcquisitionResult::continuation($op, $lock)?->shortfallAt, $now);
    }

    public static function deadlinePassed(?\DateTimeImmutable $at, \DateTimeImmutable $now): bool
    {
        return $at !== null && $at->modify('+'.self::WAIT_DAYS.' days') <= $now;
    }

    private static function due(Operation $op, \DateTimeImmutable $now, bool $lock = false): bool
    {
        $waiting = $op->action === 'renew' ? in_array($op->state, ['held', 'queued', 'awaiting_payment'], true)
            : in_array($op->state, ['held', 'queued'], true) && $op->completed_at === null && $op->lease_expires_at === null;
        return $waiting && self::elapsed($op, $now, $lock);
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
        // The closure proof is the terminal row alone.
        $terminal=['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,'invoice_id'=>$op->invoice_id];
        // These are retained receipt identities, never the empty zero-money retirement proof.
        if($receipts!==null){$terminal['transactions']=$receipts;}
        $op->update(['state'=>'rejected','safe_code'=>self::RENEWAL_CLOSED,'completed_at'=>$now]);
        if(OperationWriter::at($now)->recordTerminal((int)$op->id,self::RENEWAL_CLOSED,$terminal)
            !==Transition::Applied){throw new \DomainException('Renewal already has terminal proof.');}
        if(!$complimentary){$l['invoices']->firstWhere('id',$op->invoice_id)->update(['status'=>'cancelled']);}
    }
    public static function sweep(Account $a,\DateTimeImmutable $now): void
    {
        $alerts=Schema::hasTable('domains_obligation_alerts');
        if($alerts){DB::table('domains_obligation_alerts')->where('code','renewal_funds')->whereNull('resolved_at')
            ->whereIn('operation_id',Operation::select('id')->where('server_id',$a->server_id)->where('action','renew')->whereIn('state',['running','pending','uncertain','succeeded','rejected','disposed']))
            ->update(['resolved_at'=>$now->getTimestamp()]);}
        // Deferred closures run first within their own budget. A deferral that cannot be queued puts the cursor back
        // before its operation. Losing the cursor only restarts the pass.
        foreach(MoneyDecisions::queued((int)$a->server_id,25,[self::KIND]) as $work){
            $op=Operation::find($work->subject_id);
            try{if($op){self::close($op,$now,$alerts);}}catch(MoneyWorkUnqueued $e){throw $e;}catch(\Throwable){continue;} // Kept queued without its review.
            MoneyDecisions::done($work);
        }
        $key='domains.funds-wait-cursor.'.$a->server_id;$reached=(int)Cache::get($key,0);
        $ops=Operation::where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)
            ->where(fn($q)=>$q->where(fn($renew)=>$renew->where('action','renew')->whereIn('state',['held','queued','awaiting_payment']))
                ->orWhere(fn($purchase)=>$purchase->whereIn('action',['register','transfer'])->whereIn('state',['held','queued'])
                    ->whereExists(fn($result)=>$result->from(AcquisitionResultSchema::TABLE)
                        ->whereColumn(AcquisitionResultSchema::TABLE.'.operation_id','domains_operations.id')
                        ->whereNotNull('confirmed_shortfall_at'))))
            ->where('id','>',$reached)->orderBy('id')->limit(100)->get();
        Cache::forever($key,$ops->count()===100?$ops->last()->id:0);
        foreach($ops as $op){
            if($op->action==='renew'){
                if($alerts&&$op->safe_code==='registrar_balance_insufficient_or_unknown'){
                    DB::transaction(function()use($op,$now):void {
                        $d=$op->domain()->lockForUpdate()->firstOrFail();$current=Operation::whereKey($op->id)->lockForUpdate()->firstOrFail();
                        if(!in_array($current->state,['held','queued','awaiting_payment'],true)||$current->safe_code!=='registrar_balance_insufficient_or_unknown'){return;}
                        ObligationAlerts::record($d,'renewal_funds',$now->getTimestamp(),(int)$current->id);
                        if(!isset($current->policy['funds_wait_until'])&&DomainBilling::expired($d,$now)){ObligationAlerts::record($d,'funds_wait_review',$now->getTimestamp(),(int)$current->id);}
                    });
                }
            }
            try{self::close($op,$now,$alerts);}catch(MoneyWorkUnqueued $e){Cache::forever($key,$reached);throw $e;}
            $reached=(int)$op->id;
        }
    }
    /** One elapsed wait through the money runner. Contention twice leaves it queued with no review; a real failure keeps
     * today's funds_wait_review. A review that cannot be written throws, so the caller keeps the operation for later. */
    private static function close(Operation $op,\DateTimeImmutable $now,bool $alerts): void
    {
        try{
            if(!self::due($op,$now)){return;}
            $decision=MoneyDecisions::run(new MoneyRequest($op->domain()->firstOrFail(),self::KIND,(int)$op->id),function(array $l)use($op,$now):?bool {
                $current=$l['operations']->firstWhere('id',$op->id);
                if(!$current||!self::due($current,$now,true)){return null;}
                if($current->action==='renew'){
                    self::closeRenewal($current,$l,$now);
                    return true;
                }
                // The recorded shortfall authorizes this closure reason even when Orders projects processing.
                // The usual ownership, unsent attempt and contact checks still run on the locked evidence.
                $proof = clone $current;
                $proof->state = 'held';
                $proof->safe_code = 'registrar_balance_insufficient';
                RefundProof::requireUnsent($proof,$l['domain']);
                if($current->funding_basis==='complimentary'){ComplimentaryInvoices::require($current,$l);}else{RefundPayment::requireCurrent($current,$l);}
                if(isset($proof->attempt['claim_key'])&&!OperationTerminalProof::unsentClaim($proof,$l['domain'],$l['operations'])){throw new \DomainException('Funds hold outcome needs review.');}
                OperationTerminalProof::recordUnsent($proof, $l['domain'], $now);
                $current->reloadAcquisitionAttempt();
                $current->update(['state'=>'rejected','safe_code'=>'registrar_balance_insufficient','completed_at'=>$now]);
                AcquisitionRetirement::retainClosure($current,$l['transactions']->where('invoice_id',$current->invoice_id),
                    $l['invoices']->firstWhere('id',$current->invoice_id)?->status,$now);
                $l['domain']->update(['status'=>$current->action==='transfer'?'transfer_rejected':'registration_rejected']);
                if($current->funding_basis==='complimentary'){NativeDomainGuard::terminal($l,$current);}
                return true;
            });
            if($decision->outcome===MoneyOutcome::Deferred){return;}
            if($decision->error){throw $decision->error;}
            if($decision->value!==true){return;}
            // A complimentary receipt has no customer payment to return.
            if($op->funding_basis!=='complimentary'){app(Refunds::class)->creditRejected((int)$op->id);}
            if($alerts){DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','funds_wait_review')->whereNull('resolved_at')->update(['resolved_at'=>$now->getTimestamp()]);}
        }catch(MoneyWorkUnqueued $e){throw $e;}
        catch(\Throwable){
            if($alerts){DB::transaction(function()use($op,$now):void {
                $d=$op->domain()->lockForUpdate()->firstOrFail();ObligationAlerts::record($d,'funds_wait_review',$now->getTimestamp(),(int)$op->id);
            });}
        }
    }
}
