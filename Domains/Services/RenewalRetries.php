<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** A fresh attempt on the SAME paid debt, only after exact retained prewire proof. */
final class RenewalRetries
{
    private static ?array $creating=null;
    public static function guardCreation(Operation $op): void
    {
        $n=$op->retry_number??0;
        if($n===0&&!isset($op->policy['unsent_retry'])){return;}
        if($n<1||$n>3||self::$creating!==[$op->operation_key,$n]||$op->action!=='renew'||$op->funding_basis!=='paid_invoice'
            ||($op->policy['unsent_retry']['number']??null)!==$n){throw new \DomainException('Renewal retry requires exact retained no-send proof.');}
    }
    public static function candidate(Operation $op): bool
    {return $op->action==='renew'&&$op->state==='held'&&$op->safe_code==='renewal_definitely_unsent';}
    public static function recover(int $id,Clock $clock): Operation
    {
        OperationClaims::outside();$seed=Operation::findOrFail($id)->domain()->firstOrFail();
        return DB::transaction(function()use($seed,$id,$clock):Operation{
            $l=HoldingLocks::take($seed);$op=$l['operations']->firstWhere('id',$id);
            $next=$op->policy['unsent_retry']['next_key']??null;
            if($next!==null){return $l['operations']->firstWhere('operation_key',$next)??$op;}
            $count=$op->retry_number??0;
            if(!self::candidate($op)||!is_int($count)||$count>=3||!$op->started_at||$op->started_at>$clock->now()->modify('-15 minutes')
                ||($op->policy['unsent_retry']['number']??0)!==$count
                ||$op->base_expires_at<=$clock->now()||FundsWaits::elapsed($op,$clock->now())||isset($op->funding['refund'])){return $op;}
            try{
                app(AccountBindings::class)->requireProcessingLocked($l['server'],$l['account']);
                RefundProof::requireUnsent($op,$l['domain']);RenewalFunding::requireCurrent($op,$l);
            }catch(\Throwable){return $op;}
            $key=RenewalCycles::key($l['domain']).':replacement:'.RenewalCycles::current($l['domain'],$l['operations'])->count();
            $policy=$op->policy;unset($policy['renewal_admission'],$policy['renewal_execution'],$policy['enom_answer']);
            $policy['unsent_retry']=['number'=>$count+1,'previous_key'=>$op->operation_key,'at'=>$clock->now()->format(DATE_ATOM)];
            $funding=$op->funding??[];unset($funding['claim'],$funding['seal'],$funding['financial_adjustments']);
            self::$creating=[$key,$count+1];
            try{$child=Operation::create([...$op->only(['domain_id','service_id','server_id','registrar_key','account_fingerprint','authority_generation',
                'invoice_id','invoice_item_id','action','term','currency','amount','funding_basis','authorized_by','funding_reason','base_expires_at','expected_expires_at','payload']),
                'operation_key'=>$key,'state'=>'queued','funding'=>$funding,'policy'=>$policy,'retry_number'=>$count+1]);}finally{self::$creating=null;}
            // Preserve the sealed original. A stopped old worker cannot use its one-use grant again.
            $op->update(['state'=>'rejected','policy'=>[...$op->policy,'unsent_retry'=>[...($op->policy['unsent_retry']??[]),
                'next_key'=>$key,'proof_hash'=>EnomAnswers::identity($op),'next_at'=>$clock->now()->format(DATE_ATOM)]]]);
            return $child;
        });
    }
    public static function resolved(Operation $op,Domain $d): bool
    {
        $p=$op->policy['unsent_retry']??[];
        if($op->state!=='rejected'||$op->safe_code!=='renewal_definitely_unsent'||($p['number']??0)!==($op->retry_number??0)||($p['proof_hash']??null)!==EnomAnswers::identity($op)){return false;}
        try{RefundProof::requireUnsent($op,$d);}catch(\Throwable){return false;}
        $child=$d->operations()->where('operation_key',$p['next_key']??'')->first();
        return $child&&$child->action==='renew'&&$child->account_fingerprint===$op->account_fingerprint
            &&$child->base_expires_at==$op->base_expires_at&&$child->term===$op->term&&$child->amount===$op->amount
            &&($child->policy['unsent_retry']['number']??null)===($p['number']??0)+1&&$child->invoice_id===$op->invoice_id&&$child->invoice_item_id===$op->invoice_item_id
            &&$child->retry_number===($p['number']??0)+1
            &&$child->authority_generation===$op->authority_generation&&($child->policy['unsent_retry']['previous_key']??null)===$op->operation_key;
    }
}
