<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
final class ManagementEvidence
{
    public static function resolved(Operation $op,Domain $d): bool
    {
        $p=$op->policy['management']??null;$reads=$op->attempt['management_reads']??[];$r=$reads?end($reads):[];
        if(!$p||$op->domain_id!==$d->id||$op->service_id!==$d->service_id||$op->server_id!==$d->server_id||$op->registrar_key!==$d->registrar_key
            ||$op->account_fingerprint!==$d->account_fingerprint||$op->authority_generation!==$d->authority_generation){return false;}
        if(self::idle($op)){return true;}
        if($op->state==='rejected'&&$op->funding_basis==='non_billable_management'&&$op->completed_at!==null&&($op->attempt['seal']??null)==='sealed'&&($op->attempt['management_refusal']??null)===['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,'action'=>$op->action]){return true;}
        if($op->state==='rejected'&&isset($op->funding['refund'])){try{RefundProof::requireUnsent($op,$d);return true;}catch(\Throwable){return false;}}
        return $op->state==='succeeded'&&$op->completed_at!==null&&($op->attempt['seal']??null)==='sealed'
            &&($r['operation_key']??null)===$op->operation_key&&($r['generation']??null)===$op->authority_generation&&($r['action']??null)===$op->action
            &&(($r['confirmed']??null)===true||$op->action==='auth_code_email'&&($r['email_accepted']??null)===true)
            &&($op->action!=='auth_code_email'||($op->attempt['management_ack']['email_accepted']??null)===true)
            &&($op->action!=='contact_profile'||isset($op->attempt['management_ack']['profile_id']))
            &&(!in_array($op->action,['privacy_purchase','privacy_renew'],true)||isset($op->attempt['management_ack']['order_id']));
    }
    /** No dispatch authority: unsent management debt never blocks the domain's renewal. */
    public static function idle(Operation $op): bool
    {
        $a=$op->attempt??[];if(!self::deferrals($a)){return false;}
        if(isset($a['management_claim'])&&(!is_string($a['management_claim'])||!preg_match('/^[a-f0-9]{32}$/D',$a['management_claim'])||$op->started_at===null)){return false;}
        return isset($op->policy['management'])&&in_array($op->state,['queued','awaiting_payment','held','rejected'],true)
            &&$op->provider_order_id===null&&$op->confirmed_expires_at===null&&!array_key_exists('seal',$op->funding??[])
            &&($a===[]||(($a['seal']??null)==='absent'&&($a['send_state']??null)==='definitely_unsent'
                &&!array_diff(array_keys($a),['seal','send_state','management_claim','management_terminal','management_deferrals'])));
    }
    public static function deferrals(array $a): bool
    { $h=array_key_exists('management_deferrals',$a)?$a['management_deferrals']:[];return is_array($h)&&array_is_list($h)&&count($h)<=5&&!array_filter($h,fn($v)=>!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D',$v)); }
    public static function retryable(Operation $op): bool
    { return $op->state==='queued'&&$op->safe_code==='management_deferred'&&self::idle($op)&&isset($op->attempt['management_claim'])&&count($op->attempt['management_deferrals']??[])>0&&count($op->attempt['management_deferrals'])<=5; }
    public static function guard(Operation $op): void
    {
        $old=$op->getOriginal('attempt')??[];$new=$op->attempt??[];
        $x=$old['management_deferrals']??[];$y=$new['management_deferrals']??[];if(!self::deferrals($new)||count($y)<count($x)||array_slice($y,0,count($x))!==$x){throw new \DomainException('Management deferrals frozen.');}
        if(isset($old['management_refusal'])&&($new['management_refusal']??null)!==$old['management_refusal']){throw new \DomainException('Management refusal frozen.');}
        $before=$old['management_reads']??[];$after=$new['management_reads']??[];
        if(!is_array($before)||!array_is_list($before)||!is_array($after)||!array_is_list($after)||count($after)>20||count($after)<count($before)||array_slice($after,0,count($before))!==$before){throw new \DomainException('Management reads are append-only.');}
        if(array_key_exists('management_terminal',$old)&&($new['management_terminal']??null)!==$old['management_terminal']){throw new \DomainException('Management retirement frozen.');}
        if(array_key_exists('management_claim',$old)&&($new['management_claim']??null)!==$old['management_claim']){throw new \DomainException('Management claim frozen.');}
        if(array_key_exists('management_ack',$old)&&($new['management_ack']??null)!==$old['management_ack']){throw new \DomainException('Management acknowledgement frozen.');}
        if(isset($old['management_reads'])&&$op->getOriginal('state')==='succeeded'&&$op->isDirty(['state','completed_at'])){throw new \DomainException('Management completion frozen.');}
    }
}
