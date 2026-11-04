<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** One finally failed transfer, with an audited account-specific answer. Never a no-send proof or retry authority. */
final class TransferRejections
{
    public const CODE='transfer_fee_absorbed';
    public static function eligible(Operation $op): bool
    {
        return in_array($op->registrar_key,['enom','namesilo'],true)&&$op->action==='transfer'&&$op->term===1
            &&($op->attempt['seal']??null)==='sealed'&&$op->started_at!==null&&$op->lease_expires_at===null
            &&($op->registrar_key==='namesilo'||is_string($op->attempt['evidence']['transferReference']??null))
            &&($op->state==='rejected'&&$op->safe_code==='transfer_provider_rejected'||in_array($op->state,['pending','uncertain','held'],true)
                &&($op->policy['reconciliation']['transfer_status']??null)==='rejected'
                &&in_array($op->policy['reconciliation']['reason']??null,['transfer_rejection_unreconciled','transfer_status_unavailable','transfer_deadline_passed',...($op->registrar_key==='namesilo'?['transfer_rejection_contradiction']:[])],true))
            &&$op->provider_order_id===null&&$op->confirmed_expires_at===null
            &&!isset($op->attempt['reconciliation'])&&!array_key_exists('enom_answer',$op->policy??[])
            &&!array_intersect(['refund','refund_authorization'],array_keys($op->funding??[]))&&!array_key_exists('actual',$op->cost??[]);
    }
    /** Caller holds the staff, account, holding and invoice locks used by RegistrarAnswers. */
    public static function evidence(Operation $op,array $l,array $input): array
    {
        $d=$l['domain'];
        if(!self::eligible($op)||!$l['staff']->hasPermission('admin.domains.manage')||!$l['staff']->hasPermission('admin.invoices.update')){
            throw new \DomainException('Current transfer and refund permissions required.');}
        if(!in_array($d->status,['transfer_pending','transfer_rejected'],true)||!$d->billing_managed||$d->reservation_name!==$d->domain
            ||$d->provider_expires_at!==null||$d->provider_domain_id!==null||$d->provider_order_id!==null
            ||$l['operations']->contains(fn($other)=>$other->id!==$op->id&&!OperationTerminalProof::safe($other,$d))){
            throw new \DomainException('Transfer records disagree. Ask the registrar to check this request.');}
        $attempt=ReconciliationEvidence::attempt($op,$d,AccountBindings::binding($l['account']));
        if(($input['final_confirmed']??false)!==true
            ||($op->registrar_key==='enom'?($attempt->transferReference===null||($input['order_reference']??null)!==$attempt->transferReference)
                :\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\TransferState::read($input['transfer_status']??null)!==\Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Rejected)||($input['outcome']??null)!=='transfer_rejected'){
            throw new \DomainException('Ask the registrar to confirm this exact transfer is finally rejected and cannot complete later.');}
        // Explicit settled amounts only, including zero. Missing, pending or over-precise money stays with staff.
        $amounts=[];
        foreach(['charge','registrar_refund'] as $key){
            if(!is_string($input[$key]??null)){throw new \DomainException('Record the registrar’s final USD charge and settled refund, including zero.');}
            try{$amounts[$key]=PricingRule::units($input[$key],2);}
            catch(\InvalidArgumentException){throw new \DomainException('Record final USD amounts in dollars and cents. If the registrar cannot confirm them, leave this request open.');}
        }
        if($amounts['registrar_refund']>$amounts['charge']){throw new \DomainException('Registrar refund exceeds the charge. Ask the registrar to check it.');}
        RefundPayment::requireCurrent($op,$l);
        return ['charge'=>PricingRule::decimal($amounts['charge']),'registrar_refund'=>PricingRule::decimal($amounts['registrar_refund']),
            'absorbed_fee'=>PricingRule::decimal($amounts['charge']-$amounts['registrar_refund']),'currency'=>'USD','final_confirmed'=>true,...($op->registrar_key==='namesilo'?['transfer_status'=>$input['transfer_status']]:[])];
    }
    public static function proved(Operation $op,Domain $d): bool
    {
        $a=RegistrarAnswers::answer($op,$d);
        return $op->action==='transfer'&&$op->state==='rejected'&&$op->safe_code===self::CODE
            &&($a['outcome']??null)==='transfer_rejected'&&($a['final_confirmed']??false)===true&&($a['currency']??null)==='USD'
            &&($op->attempt['seal']??null)==='sealed'&&($a['order_reference']??null)!==null
            &&($op->registrar_key==='enom'?$a['order_reference']===($op->attempt['evidence']['transferReference']??null)
                :\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\TransferState::read($a['transfer_status']??null)===\Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Rejected)
            &&$op->provider_order_id===null&&$op->confirmed_expires_at===null&&$op->lease_expires_at===null
            &&$d->status==='transfer_rejected'&&$d->provider_expires_at===null&&$d->provider_domain_id===null&&$d->provider_order_id===null
            &&($op->cost['actual']??null)===['amount'=>$a['absorbed_fee'],'currency'=>'USD'];
    }
}
