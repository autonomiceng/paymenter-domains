<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Collection;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** A fresh attempt on the SAME paid debt, only after exact retained prewire proof. */
final class RenewalRetries
{
    private static ?array $creating=null;
    public static function guardCreation(Operation $op): void
    {
        $n=$op->retry_number??0;
        if ($n === 0 && $op->renewal_previous_proof === null) { return; }
        if($n<1||$n>3||self::$creating!==[$op->operation_key,$op->invoice_item_id,$n,$op->renewal_previous_proof]||$op->action!=='renew'||$op->funding_basis!=='paid_invoice'
            ||!$op->invoice_item_id){throw new \DomainException('Renewal retry requires exact retained no-send proof.');}
    }
    public static function candidate(Operation $op): bool
    {return $op->action==='renew'&&$op->state==='held'&&$op->safe_code==='renewal_definitely_unsent';}
    public static function recover(int $id,Clock $clock): Operation
    {
        OperationClaims::outside();$seed=Operation::findOrFail($id)->domain()->firstOrFail();
        return FreshTransaction::run(function()use($seed,$id,$clock):Operation{
            $l=HoldingLocks::take($seed);$op=$l['operations']->firstWhere('id',$id);
            $next = self::successor($op, $l['operations']);
            if ($next !== null) {
                return $op->state === 'rejected' && $next->renewal_previous_proof === EnomAnswers::identity($op) ? $next : $op;
            }
            $count=$op->retry_number??0;
            if(!self::candidate($op)||!is_int($count)||$count>=3||!$op->started_at||$op->started_at>$clock->now()->modify('-15 minutes')
                ||$op->base_expires_at<=$clock->now()||FundsWaits::elapsed($op,$clock->now())||isset($op->funding['refund'])){return $op;}
            try{
                app(AccountBindings::class)->requireProcessingLocked($l['server'],$l['account']);
                RefundProof::requireUnsent($op,$l['domain']);RenewalFunding::requireCurrent($op,$l);
            }catch(\PDOException $e){throw $e;}catch(\Throwable){return $op;}
            $key=RenewalCycles::key($l['domain']).':replacement:'.RenewalCycles::current($l['domain'],$l['operations'])->count();
            $policy=$op->policy;unset($policy['renewal_execution'],$policy['enom_answer']);
            $proof = EnomAnswers::identity($op);
            $funding=$op->funding??[];unset($funding['financial_adjustments']);
            self::$creating=[$key,$op->invoice_item_id,$count+1,$proof];
            try{$child=Operation::create([...$op->only(['domain_id','service_id','server_id','registrar_key','account_fingerprint','authority_generation',
                'invoice_id','invoice_item_id','action','term','currency','amount','funding_basis','authorized_by','funding_reason','base_expires_at','expected_expires_at','payload']),
                'operation_key'=>$key,'state'=>'queued','funding'=>$funding,'policy'=>$policy,'retry_number'=>$count+1,'renewal_previous_proof'=>$proof]);}finally{self::$creating=null;}
            // Preserve the sealed original. A stopped old worker cannot use its one-use grant again.
            $op->update(['state'=>'rejected']);
            return $child;
        });
    }
    /** The first public request and each retry share one debt. Its unique ordinal addresses each attempt. */
    public static function successor(Operation $op, ?Collection $operations = null): ?Operation
    {
        if ($op->action !== 'renew' || $op->funding_basis !== 'paid_invoice' || $op->invoice_item_id === null) { return null; }
        $number = $op->retry_number + 1;
        if ($operations !== null) {
            return $operations->first(static fn (Operation $other) => $other->invoice_item_id === $op->invoice_item_id
                && $other->domain_id === $op->domain_id && $other->action === 'renew' && $other->retry_number === $number);
        }
        return Operation::where(['domain_id' => $op->domain_id, 'action' => 'renew', 'invoice_item_id' => $op->invoice_item_id,
            'retry_number' => $number])->first();
    }

    public static function resolved(Operation $op, Domain $domain, ?Collection $operations = null): bool
    {
        if ($op->state !== 'rejected' || $op->safe_code !== 'renewal_definitely_unsent' || $op->domain_id !== $domain->id) { return false; }
        $child = self::successor($op, $operations);
        if (!$child || $child->renewal_previous_proof !== EnomAnswers::identity($op) || $child->invoice_id !== $op->invoice_id
            || $child->account_fingerprint !== $op->account_fingerprint || $child->authority_generation !== $op->authority_generation
            || $child->base_expires_at != $op->base_expires_at || $child->term !== $op->term || $child->amount !== $op->amount) { return false; }
        try { RefundProof::requireUnsent($op, $domain); }
        catch (\PDOException $error) { throw $error; }
        catch (\Throwable) { return false; }
        return true;
    }
}
