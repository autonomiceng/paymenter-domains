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
    /** Orders off stopped this before any attempt, so nothing needs a person once they are on. Held only for Orders off, it goes back to the normal first claim, which proves everything again. */
    public static function ordersOffHold(Operation $op): bool
    {
        return $op->state==='held'&&$op->safe_code==='processing'&&in_array($op->action,['register','transfer'],true)&&!array_key_exists('balance_retry',$op->policy??[])
            &&self::virgin($op)&&($op->action==='register'||TransferInputs::validCode($op->payload['epp']??null));
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
        $d=$attempt['capacity_deferrals']??[];
        return is_array($d)&&array_is_list($d)&&count($d)<=6&&!array_filter($d,static fn($at)=>!is_string($at)||\DateTimeImmutable::createFromFormat(DATE_ATOM,$at)===false);
    }
    public static function capacityRetry(Operation $op): bool
    {
        $a=$op->attempt??[];$history=$a['test_call_refusals']??[];
        return self::unsealed($op)&&isset($a['claim_key'],$a['capacity_deferrals'])&&is_array($a['capacity_deferrals'])
            &&count($a['capacity_deferrals'])<=5&&(!array_key_exists('test_call_refusals',$a)||self::testCallHistory($history))
            &&!array_diff(array_keys($a),['claim_key','seal','send_state','capacity_deferrals','test_call_refusals']);
    }
    /** A paid preflight shortfall retains negative proof through validated staff or system readmission. */
    public static function balanceRefusalRetryable(Operation $op): bool
    {
        if (!in_array($op->action,['register','transfer'],true) || !in_array($op->state,['held','queued'],true)
            || $op->completed_at!==null || !self::testCallRetryFunding($op)
            || isset($op->attempt['claim_key'])&&!is_array($op->funding['claim']??null)) { return false; }
        try{$released=ExecutionWindow::balanceReleased($op)||in_array($op->safe_code,BalanceRetries::REVIEW_CODES,true)&&BalanceRetry::from($op)!==null;
            if(!isset($op->attempt['claim_key'])&&(!self::virgin($op)||!in_array($op->safe_code,['usd_balance_required','enom_test_transfer_usd_balance_required'],true)&&BalanceRetry::from($op)===null)){return false;}
        }catch(\Throwable){return false;}
        $held=$op->state==='held'&&in_array($op->safe_code,ExecutionWindow::BALANCE_HOLDS,true)
            &&(in_array($op->safe_code,BalanceRetries::INITIAL_CODES,true)||$released||BalanceRetries::legacyHold($op));
        $queued=$op->state==='queued'&&in_array($op->safe_code,[null,'write_rate_limited'],true)&&$released;
        if (!$held&&!$queued) { return false; }
        $proof=clone $op;$proof->state='held';$proof->safe_code='registrar_balance_insufficient';
        try { RefundProof::requireUnsent($proof,$op->domain); return true; }
        catch (\Throwable) { return false; }
    }
    /** A staff release of an unsent contact step hold: the audited release is the latest window and the order's own
     * contact row still waits, so the same claim runs the contact step and the purchase once. */
    public static function contactRetry(Operation $op): bool
    {
        if($op->state!=='queued'||$op->safe_code!==null||!self::unsealed($op)){return false;}
        try{ExecutionWindow::start($op,$reason);}catch(\Throwable){return false;}
        return in_array($reason,ContactProfileHold::HOLDS,true)&&ContactProfileHold::unsent($op,retry:true);
    }
    public static function accountGuardRetryable(Operation $op): bool
    {
        return $op->state==='held'&&$op->safe_code==='namesilo_account_guard'&&$op->registrar_key==='namesilo'
            &&in_array($op->action,['register','transfer'],true)&&$op->lease_expires_at===null&&$op->completed_at===null&&self::capacityRetry($op);
    }
    public static function virgin(Operation $op): bool { return ($op->attempt ?? []) === [] && !array_key_exists('refund', $op->funding ?? []) && self::unsealed($op); }
    public static function testCallRetry(Operation $op): bool
    {
        if(!self::testCallRefusalUnsent($op)||!in_array($op->state,['queued','held'],true)) { return false; }
        $attempt=$op->attempt??[];
        return !isset($attempt['capacity_deferrals'])||count($attempt['capacity_deferrals'])<=5;
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
    /** Enom preflight holds that keep their unsent claim for a later release. */
    public const TEST_CALL_HOLDS=['enom_test_call_cap','enom_test_transfer_call_cap','pre_send_interrupted','seal_authority_invalid','claim_authority_invalid',
        'nameservers_required','write_acceptance_required','write_rate_limited','registry_details_unsupported','processing','write_disabled',
        'usd_balance_required','enom_test_transfer_usd_balance_required','enom_test_setup_required','enom_test_transfer_setup_required',...WriteHeld::UNSENT_REFUSALS];
    /** Staff may release only this retained, proven-unsent TEST preflight hold. */
    public static function testCallRefusalRetryable(Operation $op): bool
    {
        return $op->state==='held'&&in_array($op->safe_code,self::TEST_CALL_HOLDS,true)
            &&in_array($op->action,['register','transfer'],true)&&$op->registrar_key==='enom'
            &&$op->lease_expires_at===null&&$op->completed_at===null
            &&$op->provider_order_id===null&&$op->confirmed_expires_at===null
            &&self::testCallRefusalUnsent($op)
            &&self::testCallRetry($op)
            &&self::testCallRetryFunding($op);
    }

    /** A retained claim is allowed only because the TEST proof below establishes it was unsealed. */
    public static function testCallRetryFunding(Operation $op): bool
    {
        return FinancialAdjustments::registrarUnattemptedFunding($op, allowClaim: true);
    }

    /** Completed negative proof only; never used to claim, send or release a live worker. */
    public static function testCallRefusalTerminal(Operation $op): bool
    {
        if($op->state!=='rejected'||$op->completed_at===null||$op->safe_code!=='registrar_balance_insufficient'
            ||!self::testCallHistory($op->attempt['test_call_refusals']??null)||empty($op->attempt['test_call_refusals'])){return false;}
        foreach($op->attempt['test_call_refusals']as$event){if(new \DateTimeImmutable($event['refused_at'])>$op->completed_at){return false;}}
        $proof=clone $op;$proof->completed_at=null;
        return self::testCallRefusalUnsent($proof);
    }

    /** Prior cap refusals or a released price hold retain strict negative proof through unsealed readmission. */
    public static function testCallRefusalUnsent(Operation $op): bool
    {
        $a=$op->attempt??[];$f=FinancialAdjustments::registrarFunding($op);$history=array_key_exists('test_call_refusals',$a)?$a['test_call_refusals']:[];
        $priceHold=$op->state==='held'&&$op->safe_code==='registrar_price_increased';
        $priceRelease=$op->state==='queued'&&ExecutionWindow::priceReleased($op);
        $holdCodes=[...BalanceRetries::REVIEW_CODES,...self::TEST_CALL_HOLDS];
        if(!in_array($op->action,['register','transfer'],true)||$op->registrar_key!=='enom'||!self::testCallHistory($history)||(!$history&&!$priceHold&&!$priceRelease)||!self::unsealed($op)
            ||$op->lease_expires_at!==null||$op->provider_order_id!==null||$op->confirmed_expires_at!==null||$op->completed_at!==null
            ||array_key_exists('actual',$op->cost??[])||array_key_exists('execution',$op->cost??[])||array_key_exists('dispatch_attempted_at',$op->policy??[])
            ||array_diff(array_keys($f),['claim','seal','refund_authorization','refund','dispute_holds','financial_adjustments'])||array_key_exists('seal',$f)) { return false; }
        $held=$op->state==='held'&&in_array($op->safe_code,$holdCodes,true);
        $queued=$op->state==='queued'&&in_array($op->safe_code,[null,'write_rate_limited'],true);
        $approved=$op->state==='rejected'&&$op->safe_code==='refund_approved';
        $terminal=$op->state==='rejected'&&(in_array($op->safe_code,$holdCodes,true)||$approved)
            &&($a['disposition']??null)==='rejected'&&($a['rejection_basis']??null)==='definitely_unsent'
            &&($a['terminal']??null)===['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation];
        if(!$held&&!$queued&&!$approved&&!$terminal) { return false; }
        if($approved) {
            $auth=$f['refund_authorization']??null;
            if(!is_array($auth)||array_keys($auth)!==['actor_id','reason','at','operation_key','generation']||!is_int($auth['actor_id']??null)
                ||$auth['actor_id']<1||!is_string($auth['reason']??null)||!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$auth['reason'])
                ||!is_string($auth['at']??null)||$auth['operation_key']!==$op->operation_key||$auth['generation']!==$op->authority_generation) { return false; }
        }
        $unclaimedKeys=['test_call_refusals','capacity_deferrals'];
        if($terminal){$unclaimedKeys=[...$unclaimedKeys,'seal','send_state','disposition','rejection_basis','terminal'];}
        if(!isset($a['claim_key'])) {
            $deferrals=$a['capacity_deferrals']??[];
            return !array_diff(array_keys($a),$unclaimedKeys)&&$op->started_at===null&&$op->request_fingerprint===null
                &&($terminal?(($a['seal']??null)==='absent'&&($a['send_state']??null)==='definitely_unsent'):(!array_key_exists('seal',$a)&&!array_key_exists('send_state',$a)))&&!array_key_exists('claim',$f)&&!array_key_exists('seal',$f)
                &&(!array_key_exists('capacity_deferrals',$a)||(is_array($deferrals)&&array_is_list($deferrals)&&count($deferrals)<=5
                    &&!array_filter($deferrals,static fn($at)=>!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$at))));
        }
        $allowed=['test_call_refusals','claim_key','seal','send_state','capacity_deferrals'];
        if($terminal){$allowed[]='disposition';$allowed[]='rejection_basis';$allowed[]='terminal';}
        $deferrals=$a['capacity_deferrals']??[];
        return isset($a['claim_key'],$a['seal'],$a['send_state'])&&is_string($a['claim_key'])&&preg_match('/^[a-f0-9]{32}$/D',$a['claim_key'])
            &&$a['seal']==='absent'&&$a['send_state']==='definitely_unsent'&&$op->started_at!==null
            &&is_string($op->request_fingerprint)&&preg_match('/^[a-f0-9]{64}$/D',$op->request_fingerprint)
            &&is_array($f['claim']??null)&&!array_diff(array_keys($a),$allowed)
            &&(!array_key_exists('capacity_deferrals',$a)||(is_array($deferrals)&&array_is_list($deferrals)&&count($deferrals)<=6
                &&!array_filter($deferrals,static fn($at)=>!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$at))));
    }

    /** Same local proof for the advisory offer and locked readmission; never grants spending. */
    public static function balanceAdmissionReason(Operation $op,?array $facts=null): ?string
    {
        try {
            BalanceRetry::from($op);
            $proof=clone $op;$proof->state='held';$proof->safe_code='registrar_balance_insufficient';
            RefundProof::requireUnsent($proof,$op->domain);
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
