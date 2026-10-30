<?php
// Adapted from Enom 444f31c staff activity; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestRegistration, RenewalCycles, RenewalReplacements, StaffOperationWrites, UnpaidAcquisitionProof};
/** Request page facts and its one notice. A null fact value is Missing and its "_sub" key names the cause. */
final class RequestFacts
{
    public const NO_TIME='No time recorded. No action needed.';
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
        $out=['reason'=>Labels::requestStatus($op)['reason']];
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
            $out['invoice']=$op->invoice_id ? 'Invoice #'.$op->invoice_id : null;
            $out['invoice_sub']=$op->invoice_id ? (array_key_exists('auto_pay',$f) ? Labels::autoPay($f['auto_pay_result']??$f['auto_pay']) : null) : 'No invoice linked. No action needed.';
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
            elseif (array_key_exists('refund',$f)) { [$out['refund'],$out['refund_sub']]=['Credit added','Check the account credit']; }
            elseif (RefundCandidates::matches($op,$f) || ($p['renewal_execution']['refundable_later']??false)===true) {
                $out['refund']='No credit added'; $out['refund_fix']=$op->state==='held' ? 'refund' : 'credit';
            }
            elseif ($op->state==='rejected' && $op->funding_basis!=='paid_invoice') { [$out['refund'],$out['refund_sub']]=[null,'No paid invoice recorded. No action needed.']; }
            elseif ($closed && $op->funding_basis==='paid_invoice') { $out['refund']='No credit added'; }
            if ($op->registrar_key==='namesilo' && $op->action==='transfer' && $closed
                && ($op->safe_code==='transfer_provider_rejected' || ($p['reconciliation']['transfer_status']??null)==='rejected')) {
                $out['registrar_refund']='Ask NameSilo support for a refund';
            }
            // Completed, Failed and Cancelled requests are final, so a lost send response no longer leaves the result unconfirmed.
            $unknown=!in_array($op->state,['succeeded','rejected','disposed'],true) && ($op->state==='uncertain' || (array_key_exists('execution',$a) && $a['execution']===null) || ($a['seal']??null)==='sealed');
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
                if ($actual===null&&(!in_array($op->action,self::PAID_ACTIONS,true)||($op->state==='rejected'&&array_key_exists('refund',$f)))) { [$actual,$quote]=['Not charged',null]; }
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
                $out['cost']=$actual;
                $out['cost_sub']=$out['registrar_refund'] ?? $quote ?? ($actual===null ? 'No cost recorded. Check at the registrar.' : null);
            }
            if ($grants->metadata && $op->action==='transfer') {
                $payload=$op->payload??[];
                $out['auth_code']=is_string($payload['epp']??null)&&$payload['epp']!=='' ? 'Saved' : null;
                $out['auth_code_sub']=$out['auth_code'] ? null : 'Customer has not entered it';
            }
        } catch (\Throwable) { $out['unreadable']=true; }
        return $out;
    }
    /** The one request notice: the most urgent cause, a short next step and the action that fixes it, or null. */
    public static function notice(Operation $op, ?array $facts = null): ?array
    {
        $facts ??= self::for($op);
        if ($facts['unreadable']??false) { return ['title'=>DomainFacts::UNREADABLE,'tone'=>'danger']; }
        $domain=$op->domain;
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
            return ['title'=>$facts['reason'].'.','body'=>'The registrar accepted the order. Check these on the domain page.','tone'=>'warning','link'=>'domain'];
        }
        if (isset($facts['cutoff'])) { return ['title'=>$facts['cutoff'],'tone'=>'danger','action'=>'refund']; }
        if (isset($facts['outcome'])) {
            return ['title'=>$facts['outcome'],'body'=>$facts['auto_renew']??PaidUncertainty::holdSummary($op),'tone'=>'warning',
                'action'=>OperationActions::readLimit($op)&&in_array($op->action,['register','transfer','renew'],true)?'resume_reads':'recheck'];
        }
        if ($op->state==='held') {
            $reason=$facts['reason']??null;
            $fix=match($op->safe_code){
                'processing','automatic_spend_paused'=>'Resume orders',
                'funding','balance','renewal_funding_or_authority','registrar_balance_insufficient_or_unknown','usd_balance_required','enom_test_transfer_usd_balance_required'=>'Check balance',
                'awaiting_acceptance','write_disabled','write_acceptance_required','renewal_acceptance_required','grace_capability_unaccepted'=>'Turn on',
                'authority','renewal_authority_invalid','renewal_binding_changed','claim_authority_invalid','seal_authority_invalid','registration_authority_changed',
                'configuration_unavailable','transfer_setup_review_required','enom_test_transfer_setup_required','enom_test_setup_required'=>'Open Registrars',
                default=>null};
            $body=$facts['contact_missing']??$facts['contact_recovery']??$facts['contact_ready']??$facts['auto_renew']??$facts['admission']??null;
            $owes=OperationActions::customerOwes($op); $account=self::account($op)['account'];
            // A contact hold has its own recovery, and only the customer can clear what they owe.
            $open=$owes===null&&$op->safe_code!=='contact_details_required';
            $sends=$open&&EnomTestRegistration::releaseSends($op,$account); $stale=$open&&EnomTestRegistration::releaseNeedsBalance($op,$account);
            if ($owes!==null) { $body??=$owes==='payment' ? 'Customer has not paid the invoice. You can release it once it is paid.' : 'Customer has not added the auth code. You can release it once they add it.'; }
            if ($stale) { $fix='Check balance'; }
            // A fresh read clears only the age hold. A low balance stays a low balance.
            if ($sends&&in_array($op->safe_code,['usd_balance_required','enom_test_transfer_usd_balance_required'],true)) { $fix=null; $reason='Balance is up to date'; }
            $next=$stale ? 'Needs a balance check under an hour old. Check balance, then release the hold.' : ($sends ? 'Release hold sends the '.self::sendWords($op,$account).' to Enom.' : null);
            if ($next!==null) { $body=$body===null?$next:$next.' '.$body; }
            return ['title'=>rtrim($reason??Labels::reason(null),'.').'.','body'=>$body,'tone'=>'warning','action'=>OperationActions::relevant('release',$op)?'release':null,
                'link'=>$fix?'registrars':null,'link_label'=>$fix];
        }
        return null;
    }
    /** What release sends, in the words staff see. */
    public static function sendWords(Operation $op, Account $account): string
    { return ($account->environment==='live'?'':'test ').($op->action==='transfer'?'transfer':'purchase'); }
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
    public static function account(Operation $op): array
    {
        $account=Account::where('server_id',$op->server_id)->where('registrar_key',$op->registrar_key)->where('account_fingerprint',$op->account_fingerprint)->first();
        $registrar=['enom'=>'Enom','namesilo'=>'NameSilo'][$op->registrar_key]??null;
        $name=\App\Models\Server::whereKey($op->server_id)->value('name');
        if (!$name) { return ['value'=>null,'sub'=>'Registrar account not found. Check Registrars.','account'=>null]; }
        return ['value'=>$name,'sub'=>implode(' · ',array_filter([$registrar,['test'=>'Test','live'=>'Live'][$account?->environment]??null])) ?: null,'account'=>$account];
    }
}
