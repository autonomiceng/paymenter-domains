<?php
// Claim and seal semantics adapted from Enom 444f31c Services/DomainOperations.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\BalanceRetry;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, BalanceRetries, ContactProfileHold,
    ContactSetup, ExecutionWindow, FinancialAdjustments, HoldingLocks, InvoiceFunding, RefundProof, TransferInputs, WriteHeld};

/** Evidence needed for local readmission or return, without any authority to spend. */
final class AcquisitionRetryProof
{
    public const INTERRUPTED_HOLDS = ['processing', 'write_disabled', 'namesilo_account_guard', 'write_acceptance_required'];
    public const LOCAL_REFUSAL_HISTORY = ['capacity_deferrals', 'test_call_refusals'];

    /** An expired worker without a purchase seal belongs to admission. A retired seal still has its own proof. */
    public static function expiredClaim(Operation $op, \DateTimeImmutable $now): bool
    {
        return $op->completed_at === null && ($op->state === 'running' || ($op->state === 'uncertain' && $op->safe_code === 'stale_claim'))
            && self::recoverableClaim($op, $now);
    }

    /** These feature and account holds can be checked again after the worker was fenced. */
    public static function interruptedHold(Operation $op): bool
    {
        return $op->state === 'held' && in_array($op->safe_code, self::INTERRUPTED_HOLDS, true)
            && $op->completed_at === null && $op->lease_expires_at === null
            && ($prior = self::unsealedClaim($op)) !== null && OperationAttempts::releasedUnsent($prior);
    }

    public static function localRecovery(Operation $op, \DateTimeImmutable $now): bool
    {
        return self::expiredClaim($op, $now) || self::interruptedHold($op) || self::catalogueRetry($op);
    }

    public static function recoverableClaim(Operation $op, \DateTimeImmutable $now): bool
    {
        $prior = self::unsealedClaim($op);
        return $prior !== null && OperationAttempts::replaceable($prior, $now);
    }

    private static function unsealedClaim(Operation $op): ?AttemptFacts
    {
        $prior = $op->acquisitionAttemptFacts();
        return $prior && !$prior->sealed() && !AcquisitionAttempt::conflicts($op) && self::unsealed($op) ? $prior : null;
    }

    /** The latest purchase attempt must prove its own release before another worker can claim. */
    public static function releasedForReadmission(Operation $op, ?AttemptFacts $prior = null, bool $lock = false,
        ?\DateTimeImmutable $now = null, ?AcquisitionLifecycle $proof = null): bool
    {
        if (!AcquisitionAttempt::key($op->action)) { return false; }
        $proof ??= OperationAttempts::purchaseProof($op, $now ?? app(Clock::class)->now(), $lock);
        return ($prior === null || $proof->facts?->token == $prior->token)
            && ($proof->phase === AcquisitionPhase::Virgin || ($proof->canCloseUnsent() && $proof->facts?->released));
    }

    /** Orders paused before a first claim or suspended a continuing purchase admission. */
    public static function ordersOffHold(Operation $op): bool
    {
        if ($op->state !== 'held' || $op->safe_code !== 'processing' || AcquisitionAttempt::key($op->action) === null
            || ($op->action === 'transfer' && !TransferInputs::validCode($op->payload['epp'] ?? null))) { return false; }
        $lock = \Illuminate\Support\Facades\DB::transactionLevel() > 0;
        $continuation = AcquisitionResult::continuation($op, $lock);
        if ($continuation?->lane !== null
            && !in_array($continuation->lane, [AcquisitionLane::Purchase, AcquisitionLane::BalancePurchase], true)) { return false; }
        if (self::virgin($op)) { return true; }
        $prior = $op->acquisitionAttemptFacts();
        return $prior !== null && $continuation?->window !== null
            && AcquisitionResult::continues($op, $continuation->window, $prior, $lock);
    }
    /** Will the tick take this back? The writer passes its locked read, a page reads without locks; the check is the same. */
    public static function ordersOffReturns(Operation $op,?\Closure $facts=null): bool
    {
        if(!self::ordersOffHold($op)){return false;}
        try{InvoiceFunding::requireInitialFacts($op,$facts?$facts():HoldingLocks::take($op->domain,advisory:true,completeInvoices:true));}catch(\Throwable){return false;}
        return true;
    }
    /** Bounded local timestamps. They prove nothing was sent and nothing about the registrar. */
    public static function localDeferrals(array $attempt): bool
    {
        if (!array_key_exists('capacity_deferrals', $attempt)) { return true; }
        $dates = $attempt['capacity_deferrals'];
        return is_array($dates) && array_is_list($dates) && count($dates) <= 6
            && !array_filter($dates, static fn ($at) => !is_string($at)
                || !($date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $at)) || $date->format(DATE_ATOM) !== $at);
    }

    public static function settledRefusalHistory(array $attempt): bool
    {
        return self::localDeferrals($attempt) && (!array_key_exists('test_call_refusals', $attempt)
            || self::testCallHistory($attempt['test_call_refusals']));
    }

    public static function deferred(AttemptFacts $attempt, bool $lock = false): bool
    {
        $token = $attempt->token;
        $event = OperationAttempts::evidence($token->operationId, $token->attemptId, 'capacity', $lock);
        return !$attempt->sealed() && ($event['source'] ?? null) === 'local' && ($event['outcome'] ?? null) === 'deferred';
    }

    public static function capacityRetry(Operation $op): bool
    {
        $prior = $op->acquisitionAttemptFacts();
        return $prior !== null && self::releasedForReadmission($op, $prior) && self::deferred($prior);
    }

    /** A paid preflight shortfall retains negative proof through validated staff or system readmission. */
    public static function balanceRefusalRetryable(Operation $op): bool
    {
        if (!in_array($op->action,['register','transfer'],true) || !in_array($op->state,['held','queued'],true)
            || $op->completed_at!==null || !self::testCallRetryFunding($op) || !self::releasedForReadmission($op)
            || isset($op->attempt['claim_key'])&&!is_array($op->funding['claim']??null)) { return false; }
        try {
            $window = AcquisitionResult::window($op);
            $retry = BalanceRetry::from($op);
            // The old admission is negative-send proof for staff even after automatic ownership ends.
            $released = BalanceRetries::owns($op) || $window?->origin === AcquisitionAdmission::Balance->value
                || ($retry !== null && $window?->origin === AcquisitionAdmission::StaffRelease->value && $window->reason === $op->safe_code);
            if (!isset($op->attempt['claim_key']) && (!self::virgin($op)
                || !in_array($op->safe_code, ['usd_balance_required', 'enom_test_transfer_usd_balance_required'], true) && $retry === null)) {
                return false;
            }
        } catch (\Throwable) { return false; }
        $rateHold = $op->safe_code === 'write_rate_limited' && ($released || ($window !== null
            && !in_array($window->origin, [AcquisitionAdmission::TestCapacity->value, AcquisitionAdmission::StaffRelease->value], true)
            && self::capacityRetry($op))) && $retry !== null;
        $review = in_array($op->safe_code, BalanceRetries::REVIEW_CODES, true) && $retry !== null;
        $held = $op->state === 'held' && in_array($op->safe_code, ExecutionWindow::BALANCE_HOLDS, true)
            && (in_array($op->safe_code, BalanceRetries::INITIAL_CODES, true) || $released || $rateHold || $review || BalanceRetries::legacyHold($op));
        $queued = $op->state === 'queued' && in_array($op->safe_code, [null, 'write_rate_limited'], true) && $released;
        if (!$held && !$queued) { return false; }
        try { RefundProof::requirePurchase($op, $op->domain, app(Clock::class)->now(), admission: true); return true; }
        catch (\Throwable) { return false; }
    }
    /** A held NameSilo purchase can open a new window once its old worker is fenced. */
    public static function catalogueRetry(Operation $op): bool
    {
        if ($op->registrar_key !== 'namesilo' || $op->state !== 'held' || $op->safe_code !== 'write_acceptance_required'
            || $op->completed_at !== null || $op->lease_expires_at !== null) { return false; }
        $prior = $op->acquisitionAttemptFacts();
        return $prior !== null && $prior->grantConsumedAt === null && self::releasedForReadmission($op) && self::unsealed($op);
    }

    public static function accountGuardRetryable(Operation $op): bool
    {
        if ($op->state !== 'held' || $op->safe_code !== 'namesilo_account_guard' || $op->registrar_key !== 'namesilo'
            || AcquisitionAttempt::key($op->action) === null || $op->lease_expires_at !== null || $op->completed_at !== null) {
            return false;
        }
        if (self::capacityRetry($op)) { return true; }
        // A worker can exhaust the account guard's Release window before it reaches purchase.
        $lock = \Illuminate\Support\Facades\DB::transactionLevel() > 0;
        $prior = $op->acquisitionAttemptFacts();
        return $prior !== null && !$prior->sealed() && self::releasedForReadmission($op, $prior, $lock)
            && AcquisitionResult::window($op, $lock)?->reason === $op->safe_code;
    }
    public static function virgin(Operation $op, ?\DateTimeImmutable $now = null): bool
    {
        return OperationAttempts::purchaseProof($op, $now ?? app(Clock::class)->now(),
            \Illuminate\Support\Facades\DB::transactionLevel() > 0)->phase === AcquisitionPhase::Virgin
            && !array_key_exists('refund', $op->receiptFunding());
    }

    public static function testCallHistory(mixed $history): bool
    {
        if(!is_array($history)||!array_is_list($history)||count($history)>100) { return false; }
        foreach($history as $event) {
            if(!is_array($event)||array_keys($event)!==['phase','refused_at','dispatch_attempted_at','authorized_cost']
                ||!in_array($event['phase']??null,['preflight','purchase'],true)||!is_string($event['refused_at']??null)) { return false; }
            try { if((new \DateTimeImmutable($event['refused_at']))->format(DATE_ATOM)!==$event['refused_at']) { return false; } }
            catch(\Throwable) { return false; }
            if($event['phase']==='preflight') {
                if($event['dispatch_attempted_at']!==null||$event['authorized_cost']!==null) { return false; }
            } elseif(!is_string($event['dispatch_attempted_at'])||!is_array($event['authorized_cost'])
                ||array_keys($event['authorized_cost'])!==['amount','currency']||!is_string($event['authorized_cost']['amount']??null)
                ||$event['authorized_cost']['currency']!=='USD') { return false; }
        }
        return true;
    }
    public static function unsealed(Operation $op): bool
    {
        $a = $op->attempt ?? [];
        return (!array_key_exists('seal', $a) || $a['seal'] === 'absent')
            && !array_key_exists('dispatch_attempted_at', $a) && !array_key_exists('execution', $a)
            && !array_key_exists('provider_reference', $a) && $op->provider_order_id === null
            && !array_key_exists('execution', $op->cost ?? []) && !array_key_exists('actual', $op->cost ?? [])
            && !array_key_exists('dispatch_attempted_at', $op->policy ?? []);
    }
    public const TEST_CAPACITY_CODES = ['enom_test_call_cap', 'enom_test_transfer_call_cap'];

    /** Enom preflight holds that keep their unsent claim for a later release. */
    public const TEST_CALL_HOLDS=[...self::TEST_CAPACITY_CODES,'pre_send_interrupted','seal_authority_invalid','claim_authority_invalid',
        'nameservers_required','write_acceptance_required','write_rate_limited','registry_details_unsupported','processing','write_disabled',
        'usd_balance_required','enom_test_transfer_usd_balance_required','enom_test_setup_required','enom_test_transfer_setup_required',...WriteHeld::UNSENT_REFUSALS];

    public static function currentTestRefusal(Operation $op, bool $lock = false): ?int
    {
        $key = AcquisitionAttempt::key($op->action);
        if (!$key || $op->registrar_key !== 'enom' || $op->state !== 'held' || $op->lease_expires_at !== null
            || $op->completed_at !== null || $op->provider_order_id !== null || $op->confirmed_expires_at !== null
            || $op->safe_code !== ($op->action === 'transfer' ? 'enom_test_transfer_call_cap' : 'enom_test_call_cap')
            || AcquisitionAttempt::conflicts($op) || !self::testCallRetryFunding($op)) { return null; }
        $latest = OperationAttempts::latest((int) $op->id, $key, $lock);
        return $latest === null ? null : OperationAttempts::testCapacityRefusal($latest, $lock);
    }

    /** Staff may release the present cap refusal or a present, definitely-unsent preflight hold. */
    public static function testCallRefusalRetryable(Operation $op): bool
    {
        $lock = \Illuminate\Support\Facades\DB::transactionLevel() > 0;
        if (in_array($op->safe_code, self::TEST_CAPACITY_CODES, true)) { return self::currentTestRefusal($op, $lock) !== null; }
        if ($op->state !== 'held' || $op->registrar_key !== 'enom' || !in_array($op->safe_code, self::TEST_CALL_HOLDS, true)
            || $op->lease_expires_at !== null || $op->completed_at !== null
            || $op->provider_order_id !== null || $op->confirmed_expires_at !== null || !self::testCallRetryFunding($op) || !self::unsealed($op)) {
            return false;
        }
        $prior = $op->acquisitionAttemptFacts();
        if ($op->safe_code === 'write_rate_limited' && $prior !== null) {
            $window = AcquisitionResult::window($op, $lock);
            if ($window?->origin === AcquisitionAdmission::TestCapacity->value && $window->expiryReason === $op->safe_code) {
                // The released attempt may still be the cap refusal that opened this window.
                return self::releasedForReadmission($op, $prior, $lock) && AcquisitionResult::ownsAttempt($op, $window, $prior, $lock);
            }
        }
        if (in_array($op->safe_code, WriteHeld::FINAL_REFUSALS, true)) {
            // Staff can review fresh availability after a capacity release whose retry never reached purchase.
            if ($prior === null || $op->action !== 'register' || $op->safe_code !== 'name_unavailable'
                || AcquisitionResult::window($op, $lock)?->origin !== AcquisitionAdmission::TestCapacity->value) {
                return false;
            }
        }
        return ($prior === null ? $op->safe_code === 'registrar_price_increased' && self::virgin($op) : !$prior->sealed())
            && self::releasedForReadmission($op, $prior, $lock);
    }

    /** A retained claim is allowed only because the TEST proof below establishes it was unsealed. */
    public static function testCallRetryFunding(Operation $op): bool
    {
        return FinancialAdjustments::registrarUnattemptedFunding($op, allowClaim: true);
    }

    /** Same local proof for the advisory offer and locked readmission; never grants spending. */
    public static function balanceAdmissionReason(Operation $op,?array $facts=null): ?string
    {
        if (AcquisitionAttempt::key($op->action) === null) { return 'balance_retry_authority_changed'; }
        try {
            BalanceRetry::from($op);
            RefundProof::requirePurchase($op, $op->domain, app(Clock::class)->now(), admission: true);
        }catch(\PDOException $e){throw $e;}catch(\Throwable){return 'pre_send_interrupted';}
        try {
            if(!app(AccountBindings::class)->configurationAvailable((int)$op->server_id)){return 'balance_retry_authority_changed';}
            $facts??=HoldingLocks::take($op->domain,advisory:true,completeInvoices:true);
            $domain=$facts['domain'];AcquisitionAuthority::requireAcquisitionAuthority($op,$domain,$facts['operations'],$facts['account'],app(Clock::class)->now());
        }catch(\PDOException $e){throw $e;}catch(\Throwable){return 'balance_retry_authority_changed';}
        try {
            $paid=InvoiceFunding::requireInitialFacts($op,$facts);
            if(isset($op->attempt['claim_key'])&&($op->funding['claim']??null)!==$paid){return 'balance_retry_funding_changed';}
        }catch(\PDOException $e){throw $e;}catch(\Throwable){return 'balance_retry_funding_changed';}
        $op->setRelation('domain',$domain);
        try{ContactSetup::requireReady($op);}catch(\PDOException $e){throw $e;}catch(\Throwable){return 'balance_retry_setup_changed';}
        if(isset($op->attempt['claim_key'])&&$op->request_fingerprint!==AcquisitionAuthority::digest($op,$domain)){return 'balance_retry_authority_changed';}
        return self::balanceRefusalRetryable($op)?null:'pre_send_interrupted';
    }
}
