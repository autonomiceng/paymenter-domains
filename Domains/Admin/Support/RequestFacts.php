<?php
// Adapted from Enom 444f31c staff activity; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{RenewalCycles, RenewalReplacements, StaffOperationWrites};
/** Request page facts and its one notice. A null fact value is Missing and its "_sub" key names the cause. */
final class RequestFacts
{
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
                }catch(\Paymenter\Extensions\Servers\Domains\Services\ContactValidation $error){$out['contact_missing']=$error->missingMessage();}
                catch(\DomainException $error){
                    $out['contact_recovery']=match($error->getMessage()){
                        'Adoption evidence unavailable; staff review required.'=>throw $error,
                        'Contact ownership changed.','Contact recovery unavailable.','Saved transfer inputs require review.',
                        'Contact recovery limit reached.','Holding native authority changed.','Holding checkout identity changed.',
                        'Holding checkout route changed.','Holding product markers changed.','Domain invoice binding is not funded.',
                        'The full invoice must remain paid.','Acquisition lifecycle changed.','This request is not proven unsent.',
                        'Registry details are not supported for this ending.'=>$error->getMessage(),
                        default=>'Contact recovery requires staff review.'};
                }
            }
            $p=$op->policy??[]; $a=$op->attempt??[]; $f=$op->funding??[];
            $out['invoice']=$op->invoice_id ? 'Invoice #'.$op->invoice_id : null;
            $out['invoice_sub']=$op->invoice_id ? (array_key_exists('auto_pay',$f) ? Labels::autoPay($f['auto_pay_result']??$f['auto_pay']) : null) : 'No invoice linked';
            if (array_key_exists('auto_pay',$f)) { $out['auto_pay']=Labels::autoPay($f['auto_pay_result']??$f['auto_pay']); }
            if ($op->state==='held' && $op->safe_code==='transfer_setup_review_required' && ($op->attempt??[])===[] && OperationActions::transferCutoff($op)) {
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
            $closed=in_array($op->state,['rejected','disposed'],true);
            if (array_key_exists('refund',$f)) { [$out['refund'],$out['refund_sub']]=['Refunded','Check the account credit']; }
            elseif (RefundCandidates::matches($op,$f) || ($p['renewal_execution']['refundable_later']??false)===true) {
                $out['refund']='Not refunded'; $out['refund_fix']=$op->state==='held' ? 'refund' : 'credit';
            }
            elseif ($op->state==='rejected' && $op->funding_basis!=='paid_invoice') { [$out['refund'],$out['refund_sub']]=[null,'No paid invoice recorded']; }
            elseif ($closed && $op->funding_basis==='paid_invoice') { $out['refund']='Not refunded'; }
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
                $out['cost']=$actual;
                $out['cost_sub']=$out['registrar_refund'] ?? $quote ?? ($actual===null ? 'No cost recorded' : null);
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
            return ['title'=>'Payment received after refund. Check the invoice.','tone'=>'warning','link'=>'invoice'];
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
            return ['title'=>($reason??Labels::reason(null)).'.','body'=>$facts['contact_missing']??$facts['contact_recovery']??$facts['auto_renew']??$facts['admission']??null,'tone'=>'warning','action'=>OperationActions::relevant('release',$op)?'release':null,
                'link'=>$fix?'registrars':null,'link_label'=>$fix];
        }
        return null;
    }
    /** Account name, registrar and mode for the Registrar account fact. */
    public static function account(Operation $op): array
    {
        $account=Account::where('server_id',$op->server_id)->where('registrar_key',$op->registrar_key)->where('account_fingerprint',$op->account_fingerprint)->first();
        $registrar=['enom'=>'Enom','namesilo'=>'NameSilo'][$op->registrar_key]??null;
        $name=\App\Models\Server::whereKey($op->server_id)->value('name');
        if (!$name) { return ['value'=>null,'sub'=>'Registrar account not found','account'=>null]; }
        return ['value'=>$name,'sub'=>implode(' · ',array_filter([$registrar,['test'=>'Test','live'=>'Live'][$account?->environment]??null])) ?: null,'account'=>$account];
    }
}
