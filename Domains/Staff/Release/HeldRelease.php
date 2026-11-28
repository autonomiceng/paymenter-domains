<?php
// Enom444f31c staff DomainOperations paths; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Staff\Release;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Staff\Requests\RequestReads;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,BalanceRetries,ComplimentaryInvoices,ContactDetails,ContactProfileHold,ContactRecovery,ContactSetup,EnomTestRegistration,EnomTestRenewal,EnomTestTransfer,HoldingLocks,OperationClaims,RefundProof,RenewalFunding,RenewalStop,StaffOperationReview,StaffOperationWrites,TransferInputs};
use Paymenter\Extensions\Servers\Domains\Money\Returns\RefundPayment;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAdmission,AcquisitionAdmissionOutcome,AcquisitionAttempt,AcquisitionLane,AcquisitionResult,AcquisitionRetryProof,OperationWriter,StepKey};
/** Releases a held request back to the queue. The offer and the locked writer run the same check over complete native facts. */
final class HeldRelease
{
    /** Releases the current hold and records its acquisition bounds before the next worker can claim. */
    public static function release(Operation $op, array $l, Clock|\DateTimeImmutable $clock): AcquisitionAdmissionOutcome
    {
        $now = $clock instanceof Clock ? $clock->now() : $clock;
        self::require($op,$l,$now);
        $policy=$op->policy??[];
        $reason = $op->safe_code;
        $queue = static fn () => $op->update(['state'=>'queued','safe_code'=>null,'policy'=>$policy]);
        if (AcquisitionAttempt::key($op->action)) {
            $admission = match (true) {
                in_array($reason, AcquisitionRetryProof::TEST_CAPACITY_CODES, true) => AcquisitionAdmission::TestCapacity,
                $reason === 'registrar_price_increased' => AcquisitionAdmission::Price,
                in_array($reason, ContactProfileHold::HOLDS, true) && ContactProfileHold::unsent($op, retry: true)
                    => AcquisitionAdmission::ContactRetry,
                default => AcquisitionAdmission::StaffRelease,
            };
            return AcquisitionResult::admit($op, $admission, AcquisitionResult::revision($op),
                $op->acquisitionAttemptFacts()?->token, $clock, $queue);
        }
        if($op->action==='renew'){unset($policy['renewal_execution']);OperationWriter::at($now)->releaseCurrent((int)$op->id,StepKey::renew());}
        $op->update(['state'=>'queued','safe_code'=>null,'policy'=>$policy]);
        return AcquisitionAdmissionOutcome::Applied;
    }
    /** Read-only facts only; no locks, mutation, registrar exchange or spending grant. A request page reads them once. */
    public static function readFacts(Operation $op): array
    {
        return RequestReads::releaseFacts($op,static fn():array=>HoldingLocks::take($op->domain, staffId:Auth::id(), advisory:true, completeInvoices:true));
    }
    /** The offer: the signed-in staff member may release this request now. The writer repeats every check under its locks. */
    public static function offered(Operation $op,\DateTimeImmutable $now): bool
    {
        if($op->state!=='held'||!in_array($op->action,ReleaseDecision::ACTIONS,true)||BalanceRetries::owns($op)){return false;}
        return RequestReads::offered($op,static fn():bool=>self::offer($op,$now));
    }
    private static function offer(Operation $op,\DateTimeImmutable $now): bool
    {
        try {
            $l=self::readFacts($op);$op=$l['operations']->firstWhere('id',$op->id)??throw new \DomainException('Missing request.');$op->setRelation('domain',$l['domain']);
            StaffOperationReview::requirePermission($l['staff'],'release',$op);StaffOperationReview::requireHistory($op,'release');
            if (!AcquisitionResult::admissionOpen($op, $now)) { return false; }
            if($op->safe_code==='contact_details_required'){
                ContactRecovery::requireReplacement($op,$l,ContactDetails::collect($l['user']),$now);
            }else{self::require($op,$l,$now);}
            return true;
        }catch(\Throwable){return false;}
    }
    /** Refuses unless the decision allows release and its unsent proof, the service, the money and the saved inputs still hold. */
    public static function require(Operation $op,array $l,\DateTimeImmutable $now): void
    {
        $facts=self::facts($op,$l);$proof=ReleaseDecision::decide($facts);
        if($proof instanceof ReleaseRefusal){throw new \DomainException($proof->message($facts));}
        // The balance retry check already proved its retained claim unsent under its original shortfall reason.
        if ($proof === ReleaseProof::Unsent) {
            if (AcquisitionAttempt::key($op->action)) {
                if (AcquisitionAttempt::purchaseRefusal($op, \Illuminate\Support\Facades\DB::transactionLevel() > 0)) {
                    RefundProof::requireUnsent($op, $l['domain']);
                } else {
                    RefundProof::requireUnsentForAdmission($op, $l['domain']);
                }
            }
            else { RefundProof::requireUnsent($op, $l['domain']); }
        }
        elseif($proof===ReleaseProof::Unattempted){StaffOperationWrites::requireUnattempted($op,$l);}
        if(($op->action==='renew'?RenewalStop::blocksRenewal($l):$l['cancel']->contains('service_id',$op->service_id))||!in_array($l['service']->status,['pending','active'],true)) { throw new \DomainException('Service lifecycle changed.'); }
        if($op->action==='renew'){RenewalFunding::requireCurrent($op,$l);}
        else { if($op->funding_basis==='complimentary'){ComplimentaryInvoices::require($op,$l);}else{RefundPayment::requireCurrent($op,$l);}ContactSetup::validate($op);
            if($op->action==='transfer'&&(!TransferInputs::validCode($op->payload['epp']??null)||!($deadline=TransferInputs::deadline($op,$now))||$now>=$deadline)) {
                throw new \DomainException('Verified transfer payment and saved code required.'); } }
    }
    /** Reads the decision's facts in the order the checks need them; a fact the decision cannot reach is left at its default. */
    public static function facts(Operation $op,array $l): ReleaseFacts
    {
        $code=$op->safe_code;$base=[(string)$op->state,(string)$op->action,$code];
        if($op->state!=='held'||!in_array($op->action,ReleaseDecision::ACTIONS,true)||$code==='contact_details_required'){return new ReleaseFacts(...$base);}
        if(BalanceRetries::owns($op)){return new ReleaseFacts(...$base,balancePending:true);}
        if ($code === 'processing') {
            $saved = AcquisitionResult::continuation($op);
            if ($saved?->lane === AcquisitionLane::Purchase && $saved->window?->origin === AcquisitionAdmission::StaffRelease->value) {
                return new ReleaseFacts(...$base,pausedRelease:true);
            }
        }
        $op->setRelation('domain',$l['domain']);$account=$l['account'];
        $acquisition=in_array($op->action,['register','transfer'],true);
        $context=[...$base,'registrar'=>$op->registrar_key,'environment'=>$account?->environment,
            'deployment'=>$acquisition&&($op->action==='transfer'?EnomTestTransfer::deploymentEnabled():EnomTestRegistration::deploymentEnabled()),
            'renewalCap'=>$op->action==='renew'&&$op->registrar_key==='enom'&&$code===ReleaseDecision::RENEWAL_CAP&&$account
                &&in_array($account->environment,['test','live'],true)&&EnomTestRenewal::enabled(AccountBindings::binding($account))];
        $review=in_array($code,BalanceRetries::REVIEW_CODES,true)?OperationClaims::balanceAdmissionReason($op,$l):null;
        if($review!==null){return new ReleaseFacts(...$context,balanceReview:$review);}
        $contactHold=in_array($code,ContactProfileHold::HOLDS,true);
        $context=[...$context,'balanceRetry'=>OperationClaims::balanceRefusalRetryable($op),'contactRetry'=>$contactHold&&ContactProfileHold::unsent($op,retry:true)];
        // The retained contact step cannot be reopened or send different details; cancellation still uses its unsent proof.
        if($contactHold&&($blocked=ContactProfileHold::releaseBlocked($op))!==null){return new ReleaseFacts(...$context,contactBlocked:$blocked);}
        $refused = $acquisition && AcquisitionAttempt::purchaseRefusal($op, \Illuminate\Support\Facades\DB::transactionLevel() > 0) !== null;
        return new ReleaseFacts(...$context,testCallRetry:OperationClaims::testCallRefusalRetryable($op),
            accountGuardRetry:OperationClaims::accountGuardRetryable($op), purchaseRefused: $refused);
    }
}
