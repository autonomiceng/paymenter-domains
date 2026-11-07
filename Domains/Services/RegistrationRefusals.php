<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{Disposition,DriverResult,RegistrationRefusal,RejectionBasis};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};

/** Sent refusal admission only. Refunds retains all native funding, receipt and once-only return handling. */
final class RegistrationRefusals
{
    public const CODE='registration_provider_rejected';
    public static function record(Operation $op,Domain $d,DriverResult $result): ?array
    {
        $proof=$result->evidence()->registrationRefusal?->record;
        if($result->disposition!==Disposition::Rejected||$result->rejectionBasis!==RejectionBasis::ProviderProof
            ||$result->binding->fingerprint!==$op->account_fingerprint||!self::matches($op,$d,$proof)
            ||$d->status!=='registration_pending'||($op->attempt['evidence']['registrationRefusal']['record']??null)!==$proof
            ||($op->attempt['execution']??null)!==$result->jsonSerialize()){return null;}
        $attempt=[...$op->attempt,'send_state'=>'sent','disposition'=>'rejected','rejection_basis'=>'provider_proof','charge_absent'=>true];
        $d->update(['status'=>'registration_rejected']);
        return ['attempt'=>$attempt,'state'=>'rejected','safe_code'=>self::CODE];
    }
    public static function proved(Operation $op,Domain $d): bool
    {
        $a=$op->attempt??[];
        return $op->state==='rejected'&&$op->safe_code===self::CODE&&$op->lease_expires_at===null
            &&$d->status==='registration_rejected'&&self::matches($op,$d,$a['evidence']['registrationRefusal']['record']??null)
            &&($a['execution']??null)===['disposition'=>'rejected','send_state'=>'sent','reason'=>'proven_rejected','discrepancies'=>[]]
            &&($a['send_state']??null)==='sent'&&($a['disposition']??null)==='rejected'
            &&($a['rejection_basis']??null)==='provider_proof'&&($a['charge_absent']??null)===true;
    }
    private static function matches(Operation $op,Domain $d,mixed $proof): bool
    {
        $a=$op->attempt??[];$e=$a['evidence']??[];
        return is_array($proof)&&array_keys($proof)===['operation_key','claim_key','generation','server_id','account_fingerprint','name','years','envelope']
            &&RegistrationRefusal::envelope($proof['envelope'])!==null
            &&$op->action==='register'&&$op->registrar_key==='enom'&&$d->registrar_key==='enom'
            &&$op->domain_id===$d->id&&$op->service_id===$d->service_id&&$op->server_id===$d->server_id
            &&$op->account_fingerprint===$d->account_fingerprint&&$op->authority_generation===$d->authority_generation
            &&$op->authority_generation>0&&$op->provider_order_id===null&&$op->confirmed_expires_at===null
            &&$d->provider_order_id===null&&$d->provider_domain_id===null&&$d->provider_expires_at===null
            &&($a['seal']??null)==='sealed'&&is_string($a['claim_key']??null)&&preg_match('/^[a-f0-9]{32}$/D',$a['claim_key'])
            &&$op->started_at!==null&&$op->request_fingerprint!==null&&isset($a['dispatch_attempted_at'],$a['registration_admission'],$a['authorized_cost'])
            &&($proof['operation_key']??null)===$op->operation_key&&($proof['claim_key']??null)===$a['claim_key']
            &&$proof['generation']===$op->authority_generation&&$proof['server_id']===$op->server_id
            &&$proof['account_fingerprint']===$op->account_fingerprint&&$proof['name']===$d->domain&&$proof['years']===$op->term
            &&($a['charge']??null)===null&&($a['order_candidates']??[])===[]
            &&is_array($op->funding['claim']??null)&&($op->funding['claim']??null)===($op->funding['seal']??null)
            &&($e['domain']??null)===null&&($e['charge']??null)===null&&($e['candidates']??[])===[]&&($e['registration']??null)===null
            &&!array_intersect(['actual','execution'],array_keys($op->cost??[]))
            &&!array_intersect(['registration','reconciliation','send_failure'],array_keys($a));
    }
}
