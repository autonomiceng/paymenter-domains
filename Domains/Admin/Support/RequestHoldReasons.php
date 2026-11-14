<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryCosts,ContactProfileHold,ContactStepRetries};
/** Local recovery guidance shared by the notice and its release button. No registrar reads or write authority. */
final class RequestHoldReasons
{
    public const ORDERS_FIRST='Turn Orders on for this registrar first.';
    private const REASONS=[
        'staff'=>['staff_review_required'],
        'retry'=>['pre_send_interrupted','renewal_term_refused','registry_details_unsupported'],
        'orders'=>['processing','processing_off'],
        'balance'=>['funding','balance','registrar_balance_insufficient_or_unknown','registrar_balance_insufficient'],
        'feature'=>['awaiting_acceptance','write_disabled','write_acceptance_required','renewal_acceptance_required','late_renewal_paused','grace_capability_unaccepted'],
        'account'=>['authority','renewal_authority_invalid','renewal_binding_changed','claim_authority_invalid','seal_authority_invalid','registration_authority_changed',
            'configuration_unavailable','configuration_unverified','transfer_setup_review_required','enom_test_transfer_setup_required','enom_test_setup_required','namesilo_account_guard','binding_mismatch','account_unavailable'],
        'contact'=>['contact_details_required','registration_correction_required','registration_evidence_required'],
        'profile'=>[ContactProfileHold::CODE],
        'invoice'=>['renewal_invoice_unpaid','balance_retry_funding_changed'],
        'domain'=>['renewal_funding_or_authority','automatic_spend_paused','renewal_spend_paused','renewal_anchor_changed','balance_retry_authority_changed','balance_retry_setup_changed','nameservers_required'],
        'price'=>['cost','renewal_cost_unconfirmed','registrar_price_missing'],
        'increase'=>['registrar_price_increased','renewal_cost_overrun'],
        'read'=>['reads','registrar_read_failed','renewal_reads_unavailable','read_failed','read_exception','read_deadline','read_no_evidence','usd_balance_required','enom_test_transfer_usd_balance_required'],
        'limit'=>['write_rate_limited','renewal_rate_limited','enom_test_call_cap','enom_test_transfer_call_cap','enom_test_management_call_cap','enom_test_renewal_call_cap'],
        'membership'=>['owner','provider_membership_unconfirmed','domain_not_in_account'],
        'renewal'=>['provider_auto_renew_unconfirmed','renewal_state_unconfirmed','state','target_changed'],
        'availability'=>['name_unavailable','premium_name'],
        'unsupported'=>['order_not_supported','term','renewal_restore_refused'],
        'outcome'=>['prior_attempt','reconciliation_required','renewal_prior_outcome_unresolved','management_unconfirmed','renewal_confirmation_required','acquisition_definitely_unsent','renewal_definitely_unsent','management_definitely_unsent'],
    ];
    // Owner, next step, destination, link label. Causes remain the closed vocabulary in Labels::reason.
    private const FIXES=[
        'staff'=>['Staff','release the hold after reviewing the saved order',null,null],
        'orders'=>['Staff','turn Orders on for this registrar account','registrars','Go to Orders switch'],
        'balance'=>['Staff','add funds to the registrar account','registrars','Go to balance'],
        'feature'=>['The site administrator','enable this request type in the registrar account settings','registrars','Go to System checks'],
        'account'=>['Staff','check this registrar account and restore its connection','registrars','Open account'],
        'contact'=>['The customer','complete the contact details on their order page','domain',null],
        'profile'=>['The site administrator','check contact creation for this NameSilo account','registrars','Open account'],
        'invoice'=>['Staff','check the invoice and its payments','invoice',null],
        'domain'=>['Staff','review the saved order and domain settings','domain',null],
        'price'=>['Staff','sync prices for this registrar account before retrying the cost check','registrars','Sync prices'],
        'increase'=>['Staff','review the spending limit on the domain before retrying','domain',null],
        'read'=>['Staff','retry the registrar check after its automatic retries finish',null,null],
        'limit'=>['The site administrator','check the request limit for this registrar account before retrying','registrars','Open account'],
        'membership'=>['Staff','confirm the domain is in the assigned registrar account','registrars','Open account'],
        'renewal'=>['Staff','check the domain at the registrar before retrying','domain',null],
        'availability'=>['Staff','cancel this order on the domain page if the name or price is still unsuitable','domain',null],
        'unsupported'=>['Staff','cancel this unsupported order on the domain page','domain',null],
        'outcome'=>['Staff','check the original registrar result before retrying','domain',null],
        'unknown'=>['Staff','review the request history on the domain page before retrying','domain',null],
    ];
    public static function for(Operation $op): array
    {
        $kind='unknown';foreach(self::REASONS as $group=>$codes){if(in_array($op->safe_code,$codes,true)){$kind=$group;break;}}
        if($kind==='retry'){$kind=OperationActions::relevant('release',$op)?'staff':'outcome';}
        [$owner,$step,$link,$label]=self::FIXES[$kind];$blocked=null;
        $account=RequestFacts::account($op)['account'];
        if(in_array($kind,['unsupported','outcome','unknown'],true)){$blocked=$owner.' must '.$step.'; releasing this hold cannot resolve its cause.';}
        if(ComplimentaryCosts::awaitingApproval($op)){
            [$owner,$step,$link,$label]=['Staff with domain cost and funding access','approve the registrar cost on the domain page','domain',null];
            $blocked='The registrar cost needs approval; '.$owner.' must '.$step.'.';
        }
        if($kind==='profile'){
            $blocked=ContactProfileHold::releaseBlocked($op);
            if($blocked===null&&ContactProfileHold::unsent($op,retry:true)){
                [$owner,$step,$link,$label]=['Staff','release the hold to retry this order with its saved contact details',null,null];
            }
        }
        // Release would only hold again while Orders are off; the order goes out by itself once they are on (ContactStepRetries).
        if($blocked===null&&$account!==null&&$op->state==='held'&&ContactStepRetries::waitsForOrders($op,(bool)$account->processing_enabled)){
            $kind='orders';[$owner,$step,$link,$label]=self::FIXES['orders'];$blocked=self::ORDERS_FIRST;
        }
        if($kind==='orders'&&$account?->processing_enabled){
            [$owner,$step,$link,$label]=['Staff','release the hold to retry the saved order',null,null];
        }
        if($kind==='feature'&&$account){
            $feature=match($op->action){'register'=>'registration','transfer'=>'transfer','renew'=>'renewal',default=>'management'};
            $test=$account->environment==='test';
            $key=$op->registrar_key==='namesilo'?'domains.namesilo_'.($test?'test':'live').'.'.$feature:'domains.'.($test?'enom_writes':'enom_live').'.'.$feature;
            if(config($key,$op->registrar_key==='namesilo'&&$test)!==true){
                $blocked='This request type is off on this site; the site administrator must enable it before this order can retry.';
            }
        }
        return ['kind'=>$kind,'owner'=>$owner,'step'=>$step,'body'=>$owner.' must '.$step.'.','link'=>$link,'link_label'=>$label,'blocked'=>$blocked];
    }
    public static function releaseBlocked(Operation $op): ?string
    {return $op->state==='held'?self::for($op)['blocked']:null;}
}
