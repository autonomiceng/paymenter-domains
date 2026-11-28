<?php
// Enom444f31c staff DomainOperations paths; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementAuthority;
use Paymenter\Extensions\Servers\Domains\Money\Returns\RefundPayment;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AcquisitionPhase, AcquisitionResult, OperationAttempts};
/** Explicit local writers. Never clears attempt evidence, sends a request or releases paid uncertainty. */
final class StaffOperationWrites
{
    /** Shared action/state eligibility for the offer and writer; locked attempt proof is still required below. */
    public static function holdable(Operation $op): bool
    {
        return in_array($op->state,['queued','awaiting_payment','awaiting_code'],true)
            &&in_array($op->action,['register','transfer','renew','privacy_purchase','privacy_renew'],true);
    }
    /** Read-only offer; the writer repeats the same attempt proof under its locks. */
    public static function canHold(Operation $op): bool
    {
        if(!self::holdable($op)){return false;}
        try { self::requireUnattempted($op,['domain'=>$op->domain]);return true; }
        catch(\Throwable){return false;}
    }
    public static function hold(Operation $op,array $l): void
    {
        if(!self::holdable($op)) {
            throw new \DomainException('Only an unattempted queued request can be held.'); }
        self::requireUnattempted($op,$l); $op->update(['state'=>'held','safe_code'=>'staff_review_required']);
        if (AcquisitionAttempt::key($op->action) !== null) { AcquisitionResult::schedule($op, null, null); }
    }
    public static function disposition(Operation $op,array $l): void
    {
        if(!in_array($op->state,['succeeded','rejected'],true)||$op->lease_expires_at!==null) { throw new \DomainException('Terminal request required.'); }
        $refunded=$op->state==='rejected'&&$op->safe_code==='refund_approved'&&isset($op->funding['refund_authorization'],$op->funding['refund']);
        if ($refunded) {
            RefundProof::requireUnsent($op, $l['domain']);
            RefundPayment::requireCurrent($op, $l);
        }
        $completed=$op->state==='succeeded'&&in_array($op->action,['register','transfer','renew'],true)
            &&RenewalAuthority::resolved($op,$l['domain'],AccountBindings::binding($l['account']));
        if(!$completed&&!$refunded&&!OperationTerminalProof::safe($op,$l['domain'])&&!ManagementEvidence::resolved($op,$l['domain'])) {
            throw new \DomainException('A proven terminal outcome is required. Re-check the original attempt.'); }
        // Acknowledges existing engine proof. It changes no outcome, financial marker or reservation.
    }
    /** A free change whose send is unknown. Reads alone never settle it, so only staff can. The offer and the writer use this one test. */
    public static function failable(Operation $op): bool
    {
        try { $p=$op->policy['management']??null; $seal=$op->attempt['seal']??null; } catch (\Throwable) { return false; }
        return is_array($p)&&!array_intersect_key($p,array_flip(['sequence','sequence_parent','prerequisite']))
            &&!($op->managementAttempt()?->facts?->reading(app(Clock::class)->now()) ?? false)
            &&$op->funding_basis==='non_billable_management'&&$op->invoice_id===null&&$op->state==='uncertain'&&$seal==='sealed'&&$op->lease_expires_at===null;
    }
    /** Records the final failed result. Sends nothing; the domain's real settings still come from the normal reads. */
    public static function markFailed(Operation $op,array $l,\DateTimeImmutable $now): void
    {
        if(!self::failable($op)){throw new \DomainException('Only an unconfirmed free change can be marked as failed.');}
        (new ManagementAuthority(app(Clock::class)))->fail($op, ['actor_id'=>(int) $l['staff']->id, 'at'=>$now->format(DATE_ATOM)]);
        $op->update(['state'=>'rejected', 'safe_code'=>ManagementEvidence::STAFF_FAILED, 'completed_at'=>$now]);
    }
    /** The request was never attempted and nothing was sent. */
    public static function requireUnattempted(Operation $op,array $l): void
    {
        if (AcquisitionAttempt::key($op->action)) {
            $now = app(Clock::class)->now();
            $proof = OperationAttempts::purchaseProof($op, $now, DB::transactionLevel() > 0);
            if ($proof->phase !== AcquisitionPhase::Virgin || !FinancialAdjustments::registrarUnattemptedFunding($op)) {
                throw new \DomainException('An original attempt cannot be readmitted.');
            }
            RefundProof::requirePurchase($op, $l['domain'], $now, proof: $proof);
            return;
        }
        // A renewal reads its attempt through the renewal attempt reader in registrarVirgin.
        if(($op->action==='renew'?!RenewalCycles::registrarVirgin($op):($op->attempt??[])!==[])||$op->started_at!==null||$op->request_fingerprint!==null||$op->completed_at!==null
            ||$op->lease_expires_at!==null||!FinancialAdjustments::registrarUnattemptedFunding($op)) { throw new \DomainException('An original attempt cannot be readmitted.'); }
        RefundProof::requireUnsent($op,$l['domain']);
    }
}
