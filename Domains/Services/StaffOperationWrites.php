<?php
// Enom444f31c staff DomainOperations paths; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
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
        if($op->state!=='held'||!in_array($op->action,['register','transfer','renew'],true)
            ||!in_array($op->safe_code,['staff_review_required','funding','processing','write_disabled','write_acceptance_required',
                'renewal_funding_or_authority','renewal_authority_invalid','transfer_setup_review_required'],true)) {
            throw new \DomainException('This hold requires its original recovery or evidence path.'); }
        self::virgin($op,$l);
        if($l['cancel']->contains('service_id',$op->service_id)||!in_array($l['service']->status,['pending','active'],true)) { throw new \DomainException('Service lifecycle changed.'); }
        if($op->action==='renew'){RenewalFunding::requireCurrent($op,$l);}
        else { RefundPayment::requireCurrent($op,$l);ContactSetup::validate($op);
            if($op->action==='transfer'&&(!TransferInputs::validCode($op->payload['epp']??null)||!($deadline=TransferInputs::deadline($op,$now))||$now>=$deadline)) {
                throw new \DomainException('Verified transfer payment and saved code required.'); } }
        $op->update(['state'=>'queued','safe_code'=>null]); // The normal engine must freshly admit; this grants no dispatch.
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
            ||$op->lease_expires_at!==null||!FinancialAdjustments::unattemptedFunding($op)) { throw new \DomainException('An original attempt cannot be readmitted.'); }
        RefundProof::requireUnsent($op,$l['domain']);
    }
}
