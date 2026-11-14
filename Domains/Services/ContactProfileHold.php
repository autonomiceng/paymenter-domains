<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Operation,ProfileContactOperation};
/** A paid NameSilo order held at its contact step with nothing sent: the purchase was never sealed and the order's own
 * contact creation is still waiting or proved unsent. Callers hold the operation locks; the contact row is read under them. */
final class ContactProfileHold
{
    public const CODE='contact_profile_proof_required';
    /** Holds an order can reach at its contact step with nothing sent: the contact hold, or the real local cause. */
    public const HOLDS=[self::CODE,'processing','write_rate_limited'];
    public const RETRY_BLOCKED='The contact step cannot be retried with these order details. Cancel and refund this order.';
    public const RETRY_ENDED='This contact step has ended and cannot be retried. Cancel and refund this order.';
    /** A saved step cannot be reopened or send different details. The offer and writer give the same cause. */
    public static function releaseBlocked(Operation $op): ?string
    {
        if(self::unsent($op,retry:true)){
            $environment=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->value('environment');
            $test=$environment==='test';
            if(config('domains.namesilo_'.($test?'test':'live').'.management',$test)!==true){
                return 'Contact creation is off on this site; the site administrator must enable NameSilo contact creation before this order can retry.';
            }
        }
        if(!self::unsent($op)||self::unsent($op,retry:true)){return null;}
        $matches=ProfileContactOperation::where('operation_id',$op->id)->value('payload_digest')===OperationClaims::payloadDigest($op);
        return $matches?self::RETRY_ENDED:self::RETRY_BLOCKED;
    }
    /** Nothing reached NameSilo for this order. $retry also requires the contact step to be able to run again, which
     * only a waiting contact row can: a proved-unsent row stays unsent, so a release would only hold again. */
    public static function unsent(Operation $op,bool $retry=false): bool
    {
        $a=$op->attempt??[];
        if($op->registrar_key!=='namesilo'||!in_array($op->action,['register','transfer'],true)||$op->funding_basis!=='paid_invoice'
            ||$op->lease_expires_at!==null||$op->completed_at!==null||$op->provider_order_id!==null
            ||array_diff(array_keys($a),['claim_key','seal','send_state','capacity_deferrals'])||($a['seal']??null)!=='absent'
            ||($a['send_state']??null)!=='definitely_unsent'||!is_string($a['claim_key']??null)||!preg_match('/^[a-f0-9]{32}$/D',$a['claim_key'])
            ||!OperationClaims::localDeferrals($a)||array_key_exists('seal',$op->funding??[])||!is_array($op->funding['claim']??null)
            ||array_key_exists('dispatch_attempted_at',$op->policy??[])){return false;}
        // An order that never made a contact row may have used the older direct sender, which kept no record of a send.
        try{$row=ProfileContactOperation::where('operation_id',$op->id)->when(DB::transactionLevel()>0,fn($q)=>$q->lockForUpdate())->first();}
        catch(\Illuminate\Database\QueryException){return false;} // No contact ledger installed: no proof.
        $r=$row?->attempt??[];
        if(!$row||$row->authority_generation!==$op->authority_generation||$row->account_fingerprint!==$op->account_fingerprint
            ||($retry&&$row->payload_digest!==OperationClaims::payloadDigest($op))
            ||($r['parent_claim']??null)!==$a['claim_key']||isset($r['dispatch_at'])||isset($r['provider_id'])){return false;}
        if($row->state==='ready'){return ($r['seal']??null)==='absent'&&($r['send_state']??null)==='definitely_unsent'&&!isset($r['worker_key']);}
        return !$retry&&$row->state==='unsent'&&($r['execution']??null)==='definitely_unsent';
    }
}
