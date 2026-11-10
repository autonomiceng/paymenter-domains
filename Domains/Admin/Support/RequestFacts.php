<?php
// Adapted from Enom 444f31c staff activity; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{BalanceRetries, ComplimentaryCosts, EnomTestManagement, EnomTestRegistration, ManagementEvidence, ManagementProgress, OperationClaims, RegistrarCheck, RegistrationDns, RenewalCycles, RenewalReplacements, StaffOperationWrites, UnpaidAcquisitionProof, WriteHeld};
/** Request page facts and its one notice. A null fact value is Missing and its "_sub" key names the cause. */
final class RequestFacts
{
    public const NO_TIME='No time recorded. No action needed.';
    private const CHECK_STATUS='Use Check status to ask the registrar for the result.';
    private const ORDERS_OFF='Orders are off for this account.';
    /** The step after a queued request's reason while Orders are off. A reason that already says so needs only the step. */
    public static function ordersStep(string $reason): string
    { return str_contains($reason,self::ORDERS_OFF)?' Turn them on in Registrars.':' Turn Orders on in Registrars.'; }
    private const ORDERS_SWITCH='Go to Orders switch';
    private const DNS_SWITCH='Turn them on';
    /** Link words that promise a switch, and the switch they land on (RegistrarFacts::SWITCHES). */
    public const SWITCH_LINKS=[self::ORDERS_SWITCH=>'orders',self::DNS_SWITCH=>'dns'];
    /** Only these requests cost anything at the registrar. */
    private const PAID_ACTIONS=['register','transfer','renew','privacy_purchase','privacy_renew'];
    /** Why "Use updated account details" can't run, by service refusal. */
    private const CONTACT_RECOVERY=['Contact ownership changed.'=>'The customer on this order changed. Check the order.',
        'Contact recovery unavailable.'=>"The customer can't update details on this request. Contact them.",
        'Saved transfer inputs require review.'=>'Saved transfer details need a check. Open Details below.',
        'Contact recovery limit reached.'=>'The customer tried too many times. Contact them.',
        'Holding native authority changed.'=>'The order or product changed after purchase. Check the order.',
        'Holding checkout identity changed.'=>'The order or product changed after purchase. Check the order.',
        'Holding checkout route changed.'=>'The order or product changed after purchase. Check the order.',
        'Holding product markers changed.'=>'The order or product changed after purchase. Check the order.',
        'Domain invoice binding is not funded.'=>'Invoice not fully paid. Check the invoice.',
        'The full invoice must remain paid.'=>'Invoice is no longer fully paid. Check the invoice.',
        'Acquisition lifecycle changed.'=>'This order changed. Check its status.',
        'This request is not proven unsent.'=>'This may already have been sent. Check at the registrar.',
        'Registry details are not supported for this ending.'=>"This TLD needs details we can't collect yet."];
    public static function for(Operation $op, ?StaffGrants $grants = null, ?Domain $domain = null): array
    {
        $grants ??= Staff::requireView();
        $out=['reason'=>self::reason($op)];
        try {
            if($op->state==='held'&&$op->safe_code==='contact_details_required'){
                try{
                    $l=StaffOperationWrites::releaseFacts($op);
                    \Paymenter\Extensions\Servers\Domains\Services\ContactRecovery::requireReplacement($op,$l,
                        \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::collect($l['user']),app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now());
                    $out['contact_ready']=self::contactReady($op);
                }catch(\Paymenter\Extensions\Servers\Domains\Services\ContactValidation $error){$out['contact_missing']=$error->missingMessage();}
                catch(\DomainException $error){
                    if($error->getMessage()==='Adoption evidence unavailable; staff review required.'){throw $error;}
                    $out['contact_recovery']=self::CONTACT_RECOVERY[$error->getMessage()]??'Customer details need a check before this can go on.';
                }
            }
            $p=$op->policy??[]; $a=$op->attempt??[]; $f=$op->funding??[];
            if ($grants->metadata && ($failure=\Paymenter\Extensions\Servers\Domains\Data\SendFailure::words($a['send_failure']??null))) { $out['send_failure']=$failure; }
            if ($grants->metadata && ($reply=\Paymenter\Extensions\Servers\Domains\Data\RegistrarReply::fromArray($a['evidence']['registrarReply']??null))) { $out['registrar_reply']=$reply->words(); }
            $paid=$op->invoice_id||in_array($op->action,self::PAID_ACTIONS,true);
            if ($paid) {
                $out['invoice']=$op->invoice_id ? Facts::invoiceName((int)$op->invoice_id) : null;
                if ($op->invoice_id) { $out['payment_reversed']=self::paymentReversed($op); }
                $out['invoice_sub']=$op->invoice_id ? (array_key_exists('auto_pay',$f) ? Labels::autoPay($f['auto_pay_result']??$f['auto_pay']) : null) : 'No invoice linked. No action needed.';
            }
            if (array_key_exists('auto_pay',$f)) { $out['auto_pay']=Labels::autoPay($f['auto_pay_result']??$f['auto_pay']); }
            $codeMissing=$op->safe_code==='staff_review_required'&&OperationActions::customerOwes($op)==='code';
            if ($op->state==='held' && ($op->safe_code==='transfer_setup_review_required'||$codeMissing) && ($op->attempt??[])===[] && OperationActions::transferCutoff($op)) {
                try {
                    \Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireUnsent($op,$op->domain);
                    $out['cutoff']='Auth code deadline passed.';
                } catch (\DomainException) { /* Sent or unknown outcomes keep the unconfirmed notice. */ }
            }
            $admissionHold=$op->state==='held'&&RenewalCycles::registrarVirgin($op)&&StaffOperationWrites::renewalAdmissionHold($op);
            if ($admissionHold) { $out['admission']='Fix the cause, then release the hold.'; }
            if ($op->registrar_key==='namesilo' && $op->action==='renew' && ($admissionHold || $a!==[])
                && (($p['renewal_execution']['reason']??null)==='provider_auto_renew_unconfirmed'
                    || $op->safe_code==='provider_auto_renew_unconfirmed')) {
                $out['auto_renew']='Turn off auto-renew at NameSilo, then '.($admissionHold ? 'release the hold.' : 'check status.');
                unset($out['admission']);
            }
            // The refund row appears once money is in question: a refund was recorded, one is possible, or a paid request ended.
            $closed=in_array($op->state,['rejected','disposed'],true); $neverPaid=self::neverPaid($op);
            if ($neverPaid) { /* No money moved, so no refund row. */ }
            elseif ($returned=\Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns::summary($op)) {
                $out['refund']=$returned[0];
                $out['refund_sub']=match($returned[0]) {
                    'Refund needs a check'=>'Check the refund alert and gateway outcome. Do not return it again.',
                    'Refund processing'=>'Gateway return in progress. Automatic checks continue.',
                    default=>'Check the invoice payment return.',
                };
            }
            elseif (array_key_exists('refund',$f)||array_key_exists('late_credits',$f)) { [$out['refund'],$out['refund_sub']]=self::creditWords($f); }
            elseif (RefundCandidates::matches($op,$f) || ($p['renewal_execution']['refundable_later']??false)===true) {
                $out['refund']='No credit added'; $out['refund_fix']=$op->state==='held' ? 'refund' : 'credit';
            }
            elseif ($paid && $op->state==='rejected' && $op->funding_basis!=='paid_invoice') { [$out['refund'],$out['refund_sub']]=[null,'No paid invoice recorded. No action needed.']; }
            elseif ($closed && $op->funding_basis==='paid_invoice') { $out['refund']='No credit added'; }
            if($grants->costs&&(array_key_exists('refund',$f)||array_key_exists('late_credits',$f))){
                try{$context=\Paymenter\Extensions\Servers\Domains\Services\ReceiptReversals::summary($op);if($context!==null){$out['refund_sub']=trim(($out['refund_sub']??'').' '.$context);}}
                catch(\Throwable){$out['refund_sub']=trim(($out['refund_sub']??'').' Missing. Check the invoice payment reversal and credit history.');}
            }
            if ($op->registrar_key==='namesilo' && $op->action==='transfer' && $closed
                && ($op->safe_code==='transfer_provider_rejected' || ($p['reconciliation']['transfer_status']??null)==='rejected')) {
                $out['registrar_refund']='Ask NameSilo support for a refund';
            }
            // Completed, Failed and Cancelled requests are final, so a lost send response no longer leaves the result unconfirmed.
            $unsent=$op->state==='held'&&in_array($op->safe_code,['acquisition_definitely_unsent','management_definitely_unsent','renewal_definitely_unsent'],true);
            $unknown=!$unsent&&!in_array($op->state,RequestStatus::FINISHED,true) && ($op->state==='uncertain' || (array_key_exists('execution',$a) && $a['execution']===null) || ($a['seal']??null)==='sealed');
            $out['outcome']=$unknown ? PaidUncertainty::title($op) : null;
            if ($grants->costs) {
                $c=$op->cost??[];
                $usd=fn(mixed $amount, mixed $currency)=>$currency==='USD'&&($m=Labels::money($amount))!=='Unknown' ? $m : null;
                $actual=$usd($c['actual']['amount']??null,$c['actual']['currency']??null);
                // Renewals authorize a fresh registrar cost at the seal; checkout estimates stay a separate quote.
                $quote=$op->action==='renew'
                    ? (($m=$usd($a['authorized_cost']['amount']??null,$a['authorized_cost']['currency']??null)) ? 'Authorized '.$m : null)
                    : (($m=$usd($c['estimated']??null,$c['currency']??null)) ? 'Estimated '.$m : null);
                // Credit needs proof the request was never sent, so neither has a cost to miss.
                if ($actual===null&&$op->state==='rejected'&&(array_key_exists('refund',$f)||array_key_exists('late_credits',$f))) { [$actual,$quote]=['Not charged',null]; }
                // A request never sent has no cost to miss. A renewal's saved cap is a spending limit, not a cost.
                $unsent=$actual===null&&$a===[]&&$op->started_at===null&&$op->provider_order_id===null&&!array_key_exists('dispatch_attempted_at',$p)
                    &&in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true);
                if ($unsent) {
                    $actual='Not charged yet';
                    if ($op->action==='renew'&&($m=$usd($p['renewal']['cost_cap']['amount']??null,$p['renewal']['cost_cap']['currency']??null))) { $quote='Spending limit '.$m; }
                }
                // An unpaid request closed with its unsent proof never cost anything.
                $closedUnsent=$op->state==='rejected'&&in_array($op->safe_code,['renewal_cancelled_unpaid',UnpaidAcquisitionProof::CODE],true)&&($a['send_state']??null)==='definitely_unsent'&&!array_key_exists('dispatch_attempted_at',$p);
                if ($actual===null&&($closedUnsent||($op->state==='cancelled'&&$a===[]&&$op->started_at===null))) { [$actual,$quote]=['Not charged',null]; }
                if ($paid||isset($c['actual'])) {
                    $out['cost']=$actual??($unknown&&$quote!==null?'Not confirmed':null);
                    $out['cost_sub']=$out['registrar_refund'] ?? $quote ?? ($actual===null ? 'No cost recorded. Check at the registrar.' : null);
                    if(\Paymenter\Extensions\Servers\Domains\Services\TransferRejections::proved($op,$op->domain)){
                        $answer=$p['enom_answer'];$out['cost_sub']='Registrar charge '.Labels::money($answer['charge']).'; registrar refund '.Labels::money($answer['registrar_refund']).'. We cover the failed fee.';
                    }
                }
            }
            if($grants->costs&&\Paymenter\Extensions\Servers\Domains\Services\CapturedChargeReview::hasProof($op)){
                $observations=$p['captured_charge_observations']??[];$last=$observations?end($observations):null;
                if($last){$latest=$usd($last['proof']['charge']['amount']??null,$last['proof']['charge']['currency']??null);$out['cost_sub']=trim(($out['cost_sub']??'').' Latest reviewed read: '.($latest??'unavailable').'. Original captured expense retained.');}
            }
            if ($grants->metadata && $op->action==='transfer') {
                $payload=$op->payload??[];
                $out['auth_code']=is_string($payload['epp']??null)&&$payload['epp']!=='' ? 'Saved' : null;
                $out['auth_code_sub']=$out['auth_code'] ? null : 'Customer has not entered it';
            }
        } catch (\Throwable) { $out['unreadable']=true; }
        return $out;
    }
    /** Nothing retries while Orders are off, so that is the one line to show, on every page. A send in doubt still comes first. */
    private static function heldByOrders(?string $reason, Operation $op, ?Account $account, ?array $accounts=null): bool
    { return $account&&!self::ordersOn($op,$account,$accounts)&&$reason!=='pre_send_interrupted'; }
    /** Orders are on for the request's account. A list passes its OperationQueries::ordersOffAccounts() snapshot, so the note
     * and step it builds agree with the word and Status filter that read the same snapshot. */
    private static function ordersOn(Operation $op, ?Account $account, ?array $accounts): bool
    { return $account!==null&&(OperationQueries::snapshot($accounts)?!OperationQueries::ordersOff($op,$accounts):(bool)$account->processing_enabled); }
    /** The one reason for a request, on every page: for a request stopped here what is true now, else the saved reason.
     * Lists call this per row, so they pass $ordersOff: a queued row is only stopped while Orders are off, and held rows are rare. */
    public static function reason(Operation $op, bool $ordersOff=true, ?StaffGrants $grants=null, ?array $accounts=null, ?array $release=null): ?string
    {
        try { return ($op->state==='held'||$op->state==='queued'&&$ordersOff?self::readyMessage($op,$accounts,$release):null)??Labels::requestStatus($op,grants:$grants)['reason']; }
        catch (\Throwable) { return Labels::requestStatus($op,grants:$grants)['reason']; }
    }
    /** A request's status, in the customer's words. Orders off stop all queued work, so a queued request whose account has them off is held
     * for staff: it reads as held (RequestStatus::READS_AS), the same word as its domain and the Status filter. With its domain (passed, or loaded with the row) a change reads as
     * customer History does (ManagementProgress::statusKey), on the rail, the list and the request page. Lists pass $ordersOff as for reason(),
     * and $accounts, the OperationQueries::ordersOffAccounts() snapshot their Status filter reads. */
    /** Staff alone name a registration stopped here, or a transfer staff held, On hold: one word for one hold. Setup still waits on the customer. */
    public const STATUSES = [...RequestStatus::SET, 'on_hold'=>['On hold','warning']];
    /** A transfer reads On hold only when staff held it; a transfer waiting on its old registrar keeps its own word. */
    public const HELD_TRANSFER_CODE = 'staff_review_required';
    public static function heldAcquisition(Operation $op, bool $ordersOff=false): bool
    {
        return RequestStatus::readsAs($op->state,$op->safe_code,$ordersOff)==='held'
            && ($op->action==='register' || ($op->action==='transfer' && $op->state==='held' && $op->safe_code===self::HELD_TRANSFER_CODE));
    }
    public static function status(Operation $op, bool $ordersOff=true, ?Domain $d=null, ?StaffGrants $grants=null, ?array $accounts=null): array
    {
        $d??=$op->relationLoaded('domain')?$op->domain:null;
        $off=$ordersOff&&$op->state==='queued'&&OperationQueries::ordersOff($op,$accounts);
        $status=[...Labels::requestStatus($op,$off,$grants),'reason'=>self::reason($op,$ordersOff,$grants,$accounts)];
        try { $key=$d?ManagementProgress::statusKey($op,$d,$off):null; } catch (\Throwable) { $key=null; }
        if ($key!==null) { [$status['label'],$status['tone']]=RequestStatus::SET[$key]; }
        if ($paid=self::paidWaiting($op,$accounts)) { [$status['label'],$status['tone']]=RequestStatus::SET['in_progress'];$status['reason']=$paid; }
        return self::heldAcquisition($op,$off) ? [...$status,'label'=>'On hold','tone'=>'warning'] : $status;
    }
    /** The request still reads awaiting_payment until the paid invoice is picked up, as customers see it (OwnedDomains).
     * Staff read that it is paid, and whether it goes out on its own or waits on Orders. Null when the invoice is not paid. */
    public static function paidWaiting(Operation $op, ?array $accounts=null): ?string
    {
        if ($op->state!=='awaiting_payment' || !$op->invoice_id) { return null; }
        if (\App\Models\Invoice::whereKey($op->invoice_id)->value('status')!=='paid') { return null; }
        return OperationQueries::ordersOff($op,$accounts)?'Paid. Not sent. '.self::ORDERS_OFF:'Paid. It goes to the registrar automatically. Nothing to do yet.';
    }
    /** Local readiness shared by request pages and domain lists. It never releases a hold.
     * $accounts: a list's OperationQueries::ordersOffAccounts() snapshot, read for the Orders switch instead of the account now.
     * $release: the alert count's page inputs (StaffDomainInputs). It needs only whether staff must act, so it reads nothing per request:
     * accounts and invoice statuses come from the page, a held request (a balance hold included) always asks staff to act whatever its
     * words, and the release check only chooses the wording of an Orders-off request. The words it returns are never shown. */
    public static function readyMessage(Operation $op, ?array $accounts=null, ?array $release=null): ?string
    {
        if ($release!==null && $op->state==='held') { return 'Not sent.'; }
        $read=fn():?Account=>$release!==null&&array_key_exists($key=OperationQueries::accountKey($op->server_id,$op->registrar_key,$op->account_fingerprint),$release['accounts'])
            ?$release['accounts'][$key]:self::account($op)['account'];
        if (ManagementEvidence::recoverable($op)) {
            return self::ordersOn($op,$read(),$accounts)?'Not sent. It will retry automatically.':'Not sent. '.self::ORDERS_OFF;
        }
        if (BalanceRetries::pending($op)) {
            $reason=OperationClaims::balanceAdmissionReason($op);
            if(self::heldByOrders($reason,$op,$read(),$accounts)){return 'Not sent. '.self::ORDERS_OFF;}
            if($reason){return rtrim(Labels::reason($reason,$op->funding_basis==='complimentary'),'.').'.';}
            if(in_array($op->safe_code,BalanceRetries::READ_CODES,true)){return Labels::reason($op->safe_code).'. It will retry automatically.';}
            if(BalanceRetries::legacyHold($op)){return Labels::operationReason($op).'.';}
            if($op->safe_code==='write_rate_limited'){return 'Not sent: our request limit reached. It will retry automatically.';}
            return $op->safe_code==='registrar_balance_insufficient'
                ?BalanceRetries::MESSAGE:'Not sent. It will retry automatically.';
        }
        if ($op->state!=='queued' && !($op->state==='held' && in_array($op->safe_code,['processing','usd_balance_required','enom_test_transfer_usd_balance_required'],true))) { return null; }
        $account=$read();$on=self::ordersOn($op,$account,$accounts);
        // Orders off held this and they are on now, so that is no longer the reason.
        $ordersBack=$op->state==='held'&&$op->safe_code==='processing'&&$on;
        $owes=OperationActions::customerOwes($op,$release['invoices']??null);
        if (!$account || $owes!==null) { return $ordersBack?($owes==='payment'?'Not sent. Customer has not paid the invoice.':'Not sent. Customer has not added the auth code.'):null; }
        if ($ordersBack && OperationClaims::ordersOffReturns($op)) { return 'Not sent. It will retry automatically.'; }
        // Orders off stops all queued work (RuntimeWork), for any request and whether or not it could send once they are on.
        $stopped=$op->state==='queued'&&!$on?'Not sent. '.self::ORDERS_OFF:($ordersBack?'Not sent. This hold can’t be released. Cancel and refund it.':null);
        if(ManagementEvidence::retryable($op)){return $stopped??'This change has already started and cannot be put on hold. It will retry automatically.';}
        if ($release!==null) { return $stopped; }
        $review=clone $op;
        if ($review->state==='queued') { $review->state='held';$review->safe_code=OperationClaims::balanceRefusalRetryable($op)?'registrar_balance_insufficient':'staff_review_required'; }
        try {
            StaffOperationWrites::requireRelease($review,StaffOperationWrites::releaseFacts($op),app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now());
            if (($op->registrar_key==='enom'&&EnomTestRegistration::localHoldReason(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account),$op)!==null)
                || !\Paymenter\Extensions\Servers\Domains\Services\AcquisitionSetup::ready($op)) { return $stopped; }
        } catch (\Throwable) { return $stopped; }
        if (!$on) { return 'Ready to send. '.self::ORDERS_OFF; }
        return $op->state==='held' ? 'Ready to send. Release the hold.' : null;
    }
    /** When a timed retry runs next. Nothing once it is due: the next tick takes it. */
    private static function nextTry(Operation $op): ?string
    {
        if (!BalanceRetries::pending($op)) { return null; }
        $due=(int)\Illuminate\Support\Facades\DB::table('domains_runtime_operations')->where('operation_id',$op->id)->value('due_at');
        return $due>app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp()?'Next try '.gmdate('M j, Y H:i',$due).' UTC.':null;
    }
    /** Only a financially confirmed, nameserver-only registration fallback needs no staff task. */
    public static function quietRegistrationDns(Operation $op): bool
    {
        if($op->action!=='register' || $op->state!=='succeeded' || $op->safe_code!=='registration_correction_required'){return false;}
        try{
            $reconciliation=$op->policy['reconciliation']??[];
            return ($reconciliation['staff_alert']??null)===false && ($reconciliation['reason']??null)==='correction_required'
                && RegistrationDns::corrections($op,$reconciliation['observed_corrections']??[])===['nameservers'] && $op->domain!==null;
        }catch(\Throwable){return false;}
    }
    /** The one request notice: the most urgent cause, a short next step and the action that fixes it, or null. */
    public static function notice(Operation $op, ?array $facts = null): ?array
    {
        $facts ??= self::for($op);
        if ($facts['unreadable']??false) { return ['title'=>DomainFacts::UNREADABLE,'tone'=>'danger']; }
        $domain=$op->domain;
        if($op->state==='uncertain'&&\Paymenter\Extensions\Servers\Domains\Services\CapturedChargeReview::hasProof($op)){
            $approve=OperationActions::relevant('approve_charge',$op);
            // After an audited restart the one read is still to come, so Check status reads it now instead of restarting again.
            $queued=!$approve&&!OperationActions::readLimit($op);
            // A lost reply that order history no longer covers cannot be read again: offer history or ask Enom instead.
            $recover=!$approve&&HistoryRecovery::offered($op);$tooOld=!$approve&&!$recover&&HistoryRecovery::tooOld($op);
            return ['title'=>'The registrar already charged this order.','body'=>'The system cannot approve a charge above the original quote itself. '.($approve?'Review and approve the same captured charge to complete this renewal.'
                :($recover?HistoryRecovery::CAUSE:($tooOld?HistoryRecovery::TOO_OLD:($queued?'Check status reads the original order once now, or the system reads it on its next turn.':'Check status to read the original order once.').' A different or unavailable charge remains under review.'))).' Future spending stays paused.',
                'tone'=>'warning','action'=>$tooOld?null:($approve?'approve_charge':($recover?HistoryRecovery::NAME:($queued?'recheck':'resume_reads')))];
        }
        if(OperationActions::complimentaryCloseBlocked($op)){return ['title'=>'Close order is unavailable.','body'=>'Retained refusal evidence does not prove this order can be closed. Ask staff to review the original registrar outcome; nothing is charged or credited.','tone'=>'warning'];}
        if ($domain && in_array((int)$op->id,RenewalReplacements::activityIds($domain),true)) {
            return ['title'=>'Payment received after credit. Check the invoice.','tone'=>'warning','link'=>'invoice'];
        }
        if ($op->state==='cancelled' && in_array($domain?->status,['registration_pending','transfer_pending'],true)) {
            return ['title'=>'This order is still open.','body'=>'The request was cancelled but the order was not. Cancel the order on the domain page to release the name.','tone'=>'warning','link'=>'domain'];
        }
        if ($domain && $op->safe_code===UnpaidAcquisitionProof::CODE && AcquisitionPaymentReview::count($domain)>0) {
            return ['title'=>$facts['reason'].'.','body'=>'Open the domain and press Record payment review.','tone'=>'warning','link'=>'domain'];
        }
        if ($op->state==='succeeded' && $op->safe_code==='registration_correction_required') {
            if(self::quietRegistrationDns($op)){
                return RegistrationDns::pending($op,$domain)
                    ? ['title'=>$facts['reason'].'.','body'=>'The customer can set up DNS with their provider, then change nameservers.','tone'=>'info']:null;
            }
            return ['title'=>$facts['reason'].'.','body'=>'The registrar accepted the order. Check these on the domain page.','tone'=>'warning','link'=>'domain'];
        }
        if (isset($facts['cutoff'])) { return ['title'=>$facts['cutoff'],'tone'=>'danger','action'=>'refund']; }
        if($op->action==='transfer'&&($op->state==='rejected'&&$op->safe_code==='transfer_provider_rejected'||($op->policy['reconciliation']['reason']??null)==='transfer_rejection_unreconciled')){
            $registrar=$op->registrar_key==='enom'?'Enom':'NameSilo';
            return ['title'=>'Registrar rejected the transfer.','body'=>'Final result and fee need a check. Ask '.$registrar.' to confirm the final result and charge or refund.'
                .(\Paymenter\Extensions\Servers\Domains\Services\TransferRejections::eligible($op)?' Use '.EnomAnswerAction::label($op).'.':' Don’t start another transfer.'),'tone'=>'warning','link'=>'registrars'];
        }
        if(\Paymenter\Extensions\Servers\Domains\Services\TransferPolling::applies($op)&&$notice=\Paymenter\Extensions\Servers\Domains\Presentation\TransferNotice::for($op,app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp())){
            if(($op->policy['reconciliation']['staff_alert']??false)&&!\Paymenter\Extensions\Servers\Domains\Services\TransferPolling::expected($op)){
                $notice['action']=OperationActions::readLimit($op)?'resume_reads':'recheck';
            }
            return $notice;
        }
        if (isset($facts['outcome'])) {
            $recover=HistoryRecovery::offered($op);$tooOld=HistoryRecovery::tooOld($op);
            $step=RegistrarCheck::step($op);
            // History already says the result is unconfirmed, so then the notice leads with what to do. The answer step already says to ask Enom.
            $said=$op->started_at&&StaffReviewHistory::sendEvent($op)===RequestStatus::UNCONFIRMED;
            // Ask Enom comes after the first read, so a pending check doesn't say it yet.
            $cause=$recover?HistoryRecovery::CAUSE:($tooOld&&!in_array(RegistrarCheck::status($op)['outcome']??null,['awaiting_answer','not_checked'],true)?HistoryRecovery::TOO_OLD:null);
            $parts=array_values(array_filter([$said?null:$facts['outcome'],$step['title']??null,$step['body']??null,$cause,$facts['auto_renew']??PaidUncertainty::holdSummary($op)]));
            if($said&&$step===null&&$cause===null){array_unshift($parts,self::CHECK_STATUS);}
            return ['title'=>array_shift($parts),'body'=>$parts===[]?null:implode(' ',$parts),'tone'=>'warning',...(($step['answer']??false)?['primary'=>'enom_answer']:[]),
                // Check status stays on every unconfirmed request: a read past the history window still reports what the registrar shows.
                'action'=>$recover?HistoryRecovery::NAME:(OperationActions::readLimit($op)&&in_array($op->action,['register','transfer','renew'],true)?'resume_reads':'recheck')];
        }
        if ($op->state==='queued' && ($ready=self::readyMessage($op))!==null) {
            $ordersLink=!ManagementEvidence::retryable($op)||!self::account($op)['account']?->processing_enabled;
            return ['title'=>$ready,'tone'=>'warning',...($ordersLink?['link'=>'registrars','link_label'=>self::ORDERS_SWITCH]:[])];
        }
        if (($until=\Paymenter\Extensions\Servers\Domains\Services\RenewalRepeatWait::until($op))!==null) { return self::repeatWait($op,$until); }
        if ($op->state==='held'&&$op->safe_code==='management_unconfirmed'&&ManagementEvidence::idle($op)) { return self::unsent($op); }
        $review=BalanceRetries::pending($op)?OperationClaims::balanceAdmissionReason($op)
            :(in_array($op->safe_code,BalanceRetries::REVIEW_CODES,true)?$op->safe_code:null);
        if(BalanceRetries::pending($op)&&self::heldByOrders($review,$op,self::account($op)['account'])){$review=null;}
        if($op->state==='held'&&$review==='pre_send_interrupted'){return ['title'=>rtrim(Labels::reason($review),'.').'.','body'=>'Check status before retrying or cancelling this request.','tone'=>'warning','action'=>'recheck'];}
        if($op->state==='held'&&$review!==null){
            $payment=$review==='balance_retry_funding_changed';
            return ['title'=>rtrim(Labels::reason($review),'.').'.','tone'=>'warning',
                'body'=>$payment?'Review the invoice. The system will check it again. If this order cannot go ahead, cancel and refund it.':'Restore the original order details. The system will check them again. If this order cannot go ahead, review its cancellation.',
                'link'=>$payment?'invoice':'domain','action'=>$payment?'refund':null];
        }
        if ($op->state==='held') {
            $reason=$facts['reason']??null;
            $fix=match($op->safe_code){
                'processing'=>self::ORDERS_SWITCH,
                'funding','balance','registrar_balance_insufficient_or_unknown','registrar_balance_insufficient'=>'Go to balance',
                'awaiting_acceptance','write_disabled','write_acceptance_required','renewal_acceptance_required'=>'Go to System checks',
                'authority','renewal_authority_invalid','renewal_binding_changed','claim_authority_invalid','seal_authority_invalid','registration_authority_changed',
                'configuration_unavailable','transfer_setup_review_required','enom_test_transfer_setup_required','enom_test_setup_required'=>'Open account',
                default=>null};
            $body=$facts['contact_missing']??$facts['contact_recovery']??$facts['contact_ready']??$facts['auto_renew']??$facts['admission']??null;
            $owes=OperationActions::customerOwes($op); $account=self::account($op)['account'];
            // A contact hold has its own recovery, and only the customer can clear what they owe.
            $open=$owes===null&&$op->safe_code!=='contact_details_required';
            $sends=$open&&EnomTestRegistration::releaseSends($op,$account)&&OperationActions::relevant('release',$op);
            if ($owes!==null) { $body??=$owes==='payment' ? 'Customer has not paid the invoice. You can release it once it is paid.' : 'Customer has not added the auth code. You can release it once they add it.'; }
            if (($ready=self::readyMessage($op))!==null) { $reason=$ready;$fix=$account->processing_enabled?($op->safe_code==='registrar_balance_insufficient'?'Go to balance':null):self::ORDERS_SWITCH; }
            if(in_array($op->safe_code,BalanceRetries::READ_CODES,true)&&!BalanceRetries::pending($op)&&OperationActions::relevant('release',$op)){$body??=BalanceRetries::READ_STOPPED;}
            if(BalanceRetries::legacyHold($op)){$fix=null;if(OperationActions::relevant('release',$op)){$body??='Release to check this order again.';}}
            // Release hold puts paid work back in the queue, so the button says what it starts before staff press it.
            $queues=!$sends&&$open&&$account?->processing_enabled&&in_array($op->action,['register','transfer','renew'],true)&&OperationActions::relevant('release',$op);
            $next=$sends ? 'Release hold sends the '.self::sendWords($op,$account).' to Enom.' : ($queues ? 'Release hold queues the '.self::queueWords($op,$account).' to send to '.PaidUncertainty::registrar($op).'.' : null);
            // Release does not approve a higher complimentary cost, so it is only a retry and approval is the way forward.
            $approval=$open&&ComplimentaryCosts::awaitingApproval($op);
            if ($approval) { $next=(OperationActions::relevant('release',$op)?'Release hold only retries the price check. ':'').'The '.($account?self::queueWords($op,$account):'request').' holds again while the registrar cost is above the usual. It goes ahead at this cost once the registrar cost is approved on the domain page.'; }
            if ($next!==null) { $body=$body===null?$next:$next.' '.$body; }
            if (($try=self::nextTry($op))!==null) { $body=$body===null?$try:$body.' '.$try; }
            return ['title'=>rtrim($reason??Labels::reason(null),'.').'.','body'=>$body,'tone'=>'warning','action'=>in_array($op->safe_code,WriteHeld::FINAL_REFUSALS,true)&&OperationActions::relevant('refund',$op)?'refund':(OperationActions::relevant('release',$op)?'release':null),
                'link'=>$approval?'domain':match($op->safe_code){'renewal_invoice_unpaid'=>'invoice','renewal_funding_or_authority','automatic_spend_paused','renewal_spend_paused'=>'domain',default=>$fix?'registrars':null},'link_label'=>$fix];
        }
        return null;
    }
    /** A paid renewal waiting out Enom's 24-hour repeat refusal: why, the fixed time it goes and that nothing is needed. Blue: it is not a problem. */
    private static function repeatWait(Operation $op, \DateTimeImmutable $until): array
    {
        $wait=\Paymenter\Extensions\Servers\Domains\Services\RenewalRepeatWait::class;
        $paid=$op->funding_basis==='paid_invoice'?'Paid. ':'';
        return ['title'=>$wait::STAFF.'.','tone'=>'info','body'=>new \Illuminate\Support\HtmlString(e($paid.$wait::NOTHING.' '.$wait::GOES).' '
            .Sheet::moment($until->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM))->toHtml().e(', after fresh checks.'))];
    }
    /** What release sends, in the words staff see. */
    public static function sendWords(Operation $op, Account $account): string
    { return ($account->environment==='live'?'':'test ').($op->action==='transfer'?'transfer':'purchase'); }
    /** The Release hold dialog: heading, button and what release starts. $sends: sendWords() when release sends to Enom at once. */
    /** Refunds and disputes the gateway recorded on the request's own invoice, so staff see money we no longer hold.
     * Null when there are none; ['words'=>null] when they could not be read. */
    private static function paymentReversed(Operation $op): ?array
    {
        $invoice=\App\Models\Invoice::find($op->invoice_id); if (!$invoice) { return null; }
        try { $rows=\Paymenter\Extensions\Servers\Domains\Services\ReceiptReversals::forInvoice($invoice); }
        catch (\Throwable) { return ['words'=>null,'act'=>true]; }
        $lines=[];$act=false;
        foreach ($rows as $r) {
            $amount=\Paymenter\Extensions\Servers\Domains\Services\Storefront::money(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal((int)$r['amount_minor']));
            $line=match ([$r['kind']??null,$r['status']??null]) {
                ['dispute','lost']=>$amount.' lost to a dispute',
                ['dispute','open'],['dispute','inquiry']=>$amount.' under dispute. Submit evidence in Stripe before the deadline',
                ['refund','refunded']=>$amount.' refunded to the original payment',
                ['refund','open']=>'Refund of '.$amount.' in progress',
                ['refund','failed']=>'Refund of '.$amount.' failed',
                default=>null,
            };
            if ($line===null) { continue; }
            $lines[]=$line.'.';
            $act=$act||($r['needs_review']??false)||$r['kind']==='dispute'||$r['status']==='failed';
        }
        return $lines?['words'=>implode(' ',array_unique($lines)),'act'=>$act]:null;
    }
    /** Money returned as account credit: the amount, and why it is credit and not a refund to the payment. */
    private static function creditWords(array $f): array
    {
        $minor=0;
        foreach ([...(isset($f['refund'])?[$f['refund']]:[]),...($f['late_credits']??[])] as $p) { if (($p['to']??null)==='account_credit'&&is_numeric($p['amount']??null)) { $minor+=\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$p['amount']); } }
        $value=$minor>0?'Credit added: USD '.\Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal($minor):'Credit added';
        $why=($f['refund']['bridge_reason']??null)==='gateway_return_unavailable'?'The original payment could not be refunded. ':'';
        return [$value,$why.'Check the customer\'s account credit.'];
    }
    public static function releaseConfirmation(Operation $op, ?Account $account, ?string $sends, bool $contactRecovery): array
    {
        if ($sends!==null) { return ['Send to Enom now?','Release and send',"Sends the {$sends} to Enom within a few minutes. This can't be taken back."]; }
        $registrar=PaidUncertainty::registrar($op);
        $kind=$account&&in_array($op->action,['register','transfer','renew'],true)?self::queueWords($op,$account):'request';
        if ($contactRecovery) { return ['Use updated account details?','Use updated account details',"Copies the customer's current account details into this request and releases the hold. The {$kind} then goes to {$registrar}."]; }
        if ($account&&ComplimentaryCosts::awaitingApproval($op)) { return ['Release hold?','Release hold','Retries the price check. The '.$kind.' holds again while the registrar cost is above the usual.']; }
        if ($account?->processing_enabled) { return ['Send to '.$registrar.' now?','Release and send',"Queues the {$kind} to send to {$registrar}, usually within a few minutes. Once sent, it can't be taken back."]; }
        return ['Release hold?','Release hold','The '.$kind.' waits in the queue and goes to '.$registrar.' once Orders is on for this registrar account.'];
    }
    /** What release queues: the request type, marked test on a test account. */
    private static function queueWords(Operation $op, Account $account): string
    { return ($account->environment==='live'?'':'test ').['register'=>'registration','transfer'=>'transfer','renew'=>'renewal'][$op->action]; }
    /** A closed unpaid order: no payment arrived, before or after it closed. */
    public static function neverPaid(Operation $op): bool
    {
        return $op->state==='rejected'&&$op->safe_code===UnpaidAcquisitionProof::CODE&&!\App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->where('status',\App\Enums\InvoiceTransactionStatus::Succeeded)->exists()
            &&\App\Models\Invoice::whereKey($op->invoice_id)->value('status')!=='paid';
    }
    /** What the saved request lacks, now that the account has it, and what "Use updated account details" does. */
    private static function contactReady(Operation $op): string
    {
        $saved='';
        try { \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::validate($op->registrar_key,$op->payload['contacts']??null); }
        catch (\Paymenter\Extensions\Servers\Domains\Services\ContactValidation $error) {
            $roles=$error->roles(); $names=['registrant'=>'owner','admin'=>'admin','tech'=>'tech','auxbilling'=>'billing'];
            $who=count($roles)===count(\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::ROLES) ? 'all four contacts' : 'the '.implode(', ',array_map(fn($role)=>$names[$role]??$role,$roles)).' contact'.(count($roles)>1?'s':'');
            $saved='This request is missing: '.implode(', ',$error->labels()).($roles?', for '.$who:'').'. The customer account is complete now. ';
        }
        $registrar=['enom'=>'Enom','namesilo'=>'NameSilo'][$op->registrar_key]??'the registrar';
        return $saved.($op->action==='transfer'
            ? '"Use updated account details" copies the account details into this request. The transfer stays on hold for a separate release review. If the auth code deadline has passed, it cannot be released.'
            : '"Use updated account details" copies the account details into this request and puts it back in the queue. It may then be sent to '.$registrar.'.');
    }
    /** Account name, registrar and mode for the Registrar account fact. */
    /** A change that never reached the registrar. The system tries it again itself, so staff only have a step when the switch stops it or the tries run out. */
    private static function unsent(Operation $op): array
    {
        $again=ManagementEvidence::recoverable($op); $account=self::account($op)['account'];
        try { $off=$op->registrar_key==='enom'&&$account&&!EnomTestManagement::accepted(AccountBindings::binding($account)); } catch (\Throwable) { $off=false; }
        if ($off) {
            return ['title'=>RequestStatus::UNSENT,'body'=>'DNS, nameservers and contacts are Off for this registrar account. Turn them on'.($again?' and we try again by ourselves.':', then make the change again on the domain page.'),
                'tone'=>'warning','link'=>'registrars','link_label'=>self::DNS_SWITCH];
        }
        return $again?['title'=>RequestStatus::UNSENT,'body'=>'We try again by ourselves. There is nothing to do yet.','tone'=>'info']
            :['title'=>RequestStatus::UNSENT,'body'=>'We won\'t try again. If the change is still needed, make it again on the domain page.','tone'=>'warning','link'=>'domain'];
    }
    public static function account(Operation $op): array
    {
        $account=Account::where('server_id',$op->server_id)->where('registrar_key',$op->registrar_key)->where('account_fingerprint',$op->account_fingerprint)->first();
        $registrar=['enom'=>'Enom','namesilo'=>'NameSilo'][$op->registrar_key]??null;
        $name=\App\Models\Server::whereKey($op->server_id)->value('name');
        if (!$name) { return ['value'=>null,'sub'=>'Registrar account not found. Check Registrars.','account'=>null]; }
        return ['value'=>$name,'sub'=>implode(', ',array_filter([$registrar,['test'=>'Test','live'=>'Live'][$account?->environment]??null])) ?: null,'account'=>$account];
    }
}
