<?php
// Enom444f31c staff DomainOperations paths; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;
/** Explicit local writers. Never clears attempt evidence, sends a request or releases paid uncertainty. */
final class StaffOperationWrites
{
    public static function hold(Operation $op,array $l): void
    {
        if(!in_array($op->state,['queued','awaiting_payment','awaiting_code'],true)||!in_array($op->action,['register','transfer','renew','privacy_purchase','privacy_renew'],true)) {
            throw new \DomainException('Only an unattempted queued request can be held.'); }
        self::virgin($op,$l); $op->update(['state'=>'held','safe_code'=>'staff_review_required']);
    }
    public static function release(Operation $op,array $l,\DateTimeImmutable $now): void
    {
        self::requireRelease($op,$l,$now);
        $policy=$op->policy??[];
        if($op->action==='renew'){unset($policy['renewal_admission'],$policy['renewal_execution']);}
        $op->update(['state'=>'queued','safe_code'=>null,'policy'=>$policy]); // The normal engine must freshly admit; this grants no dispatch.
    }
    /** Read-only facts only; no locks, mutation, registrar exchange or spending grant. */
    public static function releaseFacts(Operation $op): array
    {
        return HoldingLocks::take($op->domain, staffId:\Illuminate\Support\Facades\Auth::id(), advisory:true, completeInvoices:true);
    }
    public static function canRelease(Operation $op,\DateTimeImmutable $now): bool
    {
        if($op->state!=='held'||!in_array($op->action,['register','transfer','renew'],true)||BalanceRetries::pending($op)){return false;}
        try {
            $l=self::releaseFacts($op);$op=$l['operations']->firstWhere('id',$op->id)??throw new \DomainException('Missing request.');$op->setRelation('domain',$l['domain']);
            StaffOperationReview::requirePermission($l['staff'],'release',$op);StaffOperationReview::requireHistory($op,'release');
            if($op->safe_code==='contact_details_required'){
                ContactRecovery::requireReplacement($op,$l,ContactDetails::collect($l['user']),$now);
            }else{self::requireRelease($op,$l,$now);}
            return true;
        }catch(\Throwable){return false;}
    }
    /** The offer and locked writer execute this same preflight over complete native facts. */
    public static function requireRelease(Operation $op,array $l,\DateTimeImmutable $now): void
    {
        if($op->state!=='held'||!in_array($op->action,['register','transfer','renew'],true)||$op->safe_code==='contact_details_required'||BalanceRetries::pending($op)){
            throw new \DomainException('This hold requires its original recovery or evidence path.');
        }
        $op->setRelation('domain',$l['domain']);$account=$l['account'];
        if(in_array($op->safe_code,BalanceRetries::REVIEW_CODES,true)&&OperationClaims::balanceAdmissionReason($op,$l)!==null){throw new \DomainException('Original purchase proof still needs review.');}
        $ordinary=['namesilo_account_guard','staff_review_required','funding','processing','write_disabled','write_acceptance_required',
            'configuration_unavailable','balance','renewal_funding_or_authority','renewal_invoice_unpaid','renewal_spend_paused','renewal_authority_invalid','transfer_setup_review_required',
            'registrar_price_missing','registrar_read_failed','registrar_balance_insufficient'];
        $retry=false;$balanceRetry=OperationClaims::balanceRefusalRetryable($op);
        if(!$balanceRetry&&!in_array($op->safe_code,$ordinary,true)&&!self::renewalAdmissionHold($op)){
            $testRegistration=in_array($op->action,['register','transfer'],true)&&$op->registrar_key==='enom'&&in_array($account?->environment,['test','live'],true)
                &&($op->action==='transfer'?EnomTestTransfer::deploymentEnabled():EnomTestRegistration::deploymentEnabled());
            $testOnly=['pre_send_interrupted','seal_authority_invalid','claim_authority_invalid','nameservers_required','write_rate_limited',
                'registry_details_unsupported','usd_balance_required','enom_test_transfer_usd_balance_required','enom_test_setup_required','enom_test_transfer_setup_required'];
            $retry=$testRegistration&&OperationClaims::testCallRefusalRetryable($op);
            $renewalCap=$op->action==='renew'&&$op->registrar_key==='enom'&&$op->safe_code==='enom_test_renewal_call_cap'
                &&$account&&in_array($account->environment,['test','live'],true)&&EnomTestRenewal::enabled(CatalogueAcceptances::binding($account));
            if(!$retry&&!($testRegistration&&in_array($op->safe_code,$testOnly,true))&&!$renewalCap){throw new \DomainException('This hold requires its original recovery or evidence path.');}
        } elseif(!$balanceRetry&&OperationClaims::testCallRefusalRetryable($op)) {
            $retry=$op->action==='transfer'?EnomTestTransfer::deploymentEnabled():EnomTestRegistration::deploymentEnabled();
            if(!$retry||!in_array($account?->environment,['test','live'],true)){throw new \DomainException('This hold requires its original recovery or evidence path.');}
        }
        $testRetry=OperationClaims::testCallRefusalRetryable($op);
        // The balance predicate already validates strict unsent proof under its original shortfall reason.
        if(!$balanceRetry){
            if($testRetry||OperationClaims::accountGuardRetryable($op)) { RefundProof::requireUnsent($op,$l['domain']); }
            else { self::virgin($op,$l); }
        }
        if(($op->action==='renew'?RenewalStop::blocksRenewal($l):$l['cancel']->contains('service_id',$op->service_id))||!in_array($l['service']->status,['pending','active'],true)) { throw new \DomainException('Service lifecycle changed.'); }
        if($op->action==='renew'){RenewalFunding::requireCurrent($op,$l);}
        else { RefundPayment::requireCurrent($op,$l);ContactSetup::validate($op);
            if($op->action==='transfer'&&(!TransferInputs::validCode($op->payload['epp']??null)||!($deadline=TransferInputs::deadline($op,$now))||$now>=$deadline)) {
                throw new \DomainException('Verified transfer payment and saved code required.'); } }
    }
    /** Selected pre-dispatch refusals; identity, spend-pause and prior-outcome codes remain excluded. */
    public static function renewalAdmissionHold(Operation $op): bool
    {
        return $op->action==='renew'&&in_array(RenewalCode::tryFrom($op->safe_code??''),
            [RenewalCode::State,RenewalCode::Cost,RenewalCode::Owner,RenewalCode::AutoRenew,RenewalCode::Term],true);
    }
    public static function disposition(Operation $op,array $l): void
    {
        if(!in_array($op->state,['succeeded','rejected'],true)||$op->lease_expires_at!==null) { throw new \DomainException('Terminal request required.'); }
        $refunded=$op->state==='rejected'&&$op->safe_code==='refund_approved'&&isset($op->funding['refund_authorization'],$op->funding['refund']);
        if($refunded){RefundProof::requireUnsent($op,$l['domain']);RefundPayment::requireCurrent($op,$l);}
        $completed=$op->state==='succeeded'&&in_array($op->action,['register','transfer','renew'],true)
            &&RenewalAuthority::resolved($op,$l['domain'],CatalogueAcceptances::binding($l['account']));
        if(!$completed&&!$refunded&&!OperationTerminalProof::safe($op,$l['domain'])&&!ManagementEvidence::resolved($op,$l['domain'])) {
            throw new \DomainException('A proven terminal outcome is required. Re-check the original attempt.'); }
        // Acknowledges existing engine proof. It changes no outcome, financial marker or reservation.
    }
    private static function virgin(Operation $op,array $l): void
    {
        if(($op->attempt??[])!==[]||$op->started_at!==null||$op->request_fingerprint!==null||$op->completed_at!==null
            ||$op->lease_expires_at!==null||!FinancialAdjustments::registrarUnattemptedFunding($op)
            ||($op->action==='renew'&&!RenewalCycles::registrarVirgin($op))) { throw new \DomainException('An original attempt cannot be readmitted.'); }
        RefundProof::requireUnsent($op,$l['domain']);
    }
}
