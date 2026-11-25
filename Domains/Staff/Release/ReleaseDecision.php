<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Staff\Release;
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;
use Paymenter\Extensions\Servers\Domains\Services\{BalanceRetries,ContactProfileHold};
/** Which held requests staff may release, from read facts alone. The offer and the writer both use it. Staff permission,
 * the unsent proof it returns, the service, the money and the saved inputs are still checked by the writer under its locks. */
final class ReleaseDecision
{
    public const ACTIONS=['register','transfer','renew'];
    /** Holds that any held purchase or renewal may leave once its proof passes. */
    public const ORDINARY=['namesilo_account_guard','staff_review_required','funding','processing','write_disabled','write_acceptance_required',
        'configuration_unavailable','balance','renewal_funding_or_authority','renewal_invoice_unpaid','renewal_spend_paused','renewal_authority_invalid','transfer_setup_review_required',
        'registrar_price_missing','registrar_price_increased','registrar_read_failed','registrar_balance_insufficient'];
    /** Enom acquisition holds that only a site with that acquisition type enabled may release. */
    public const DEPLOYMENT=['pre_send_interrupted','seal_authority_invalid','claim_authority_invalid','nameservers_required','write_rate_limited',
        'registry_details_unsupported','usd_balance_required','enom_test_transfer_usd_balance_required','enom_test_setup_required','enom_test_transfer_setup_required'];
    public const RENEWAL_CAP='enom_test_renewal_call_cap';
    /** Renewal checks that refused before anything was sent; identity, spend pause and prior outcome codes are not here. */
    private const RENEWAL_ADMISSION=[RenewalCode::State,RenewalCode::Cost,RenewalCode::Owner,RenewalCode::AutoRenew,RenewalCode::Term];

    public static function decide(ReleaseFacts $f): ReleaseProof|ReleaseRefusal
    {
        if($f->state!=='held'||!in_array($f->action,self::ACTIONS,true)||$f->code==='contact_details_required'||$f->balancePending){return ReleaseRefusal::OriginalRecovery;}
        if(in_array($f->code,BalanceRetries::REVIEW_CODES,true)&&$f->balanceReview!==null){return ReleaseRefusal::PurchaseProof;}
        if(in_array($f->code,ContactProfileHold::HOLDS,true)&&$f->contactBlocked!==null){return ReleaseRefusal::ContactStep;}
        $enom=$f->registrar==='enom'&&in_array($f->environment,['test','live'],true);
        if(!$f->balanceRetry&&!$f->contactRetry&&!in_array($f->code,self::ORDINARY,true)&&!self::renewalAdmission($f->action,$f->code)){
            $deployed=in_array($f->action,['register','transfer'],true)&&$enom&&$f->deployment;
            $renewalCap=$f->action==='renew'&&$enom&&$f->code===self::RENEWAL_CAP&&$f->renewalCap;
            if(!($deployed&&($f->testCallRetry||in_array($f->code,self::DEPLOYMENT,true)))&&!$renewalCap){return ReleaseRefusal::OriginalRecovery;}
        }elseif(!$f->balanceRetry&&$f->testCallRetry&&(!$f->deployment||!in_array($f->environment,['test','live'],true))){
            return ReleaseRefusal::OriginalRecovery;
        }
        if($f->balanceRetry){return ReleaseProof::BalanceRetry;}
        return $f->testCallRetry||$f->contactRetry||$f->accountGuardRetry?ReleaseProof::Unsent:ReleaseProof::Unattempted;
    }

    /** A renewal held by a check that refused before anything was sent. */
    public static function renewalAdmission(string $action,?string $code): bool
    {
        return $action==='renew'&&in_array(RenewalCode::tryFrom($code??''),self::RENEWAL_ADMISSION,true);
    }
}
