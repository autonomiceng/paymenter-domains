<?php
// Adapted from Enom 444f31c Admin/Support/Labels; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
final class Labels
{
    /** The Invoice sub-line on the request page. */
    public static function autoPay(mixed $evidence): string
    {
        return match (is_array($evidence) ? ($evidence['status'] ?? null) : null) {
            'submitted' => 'Auto Pay: submitted',
            'charged' => 'Auto Pay: charged',
            'declined' => 'Auto Pay: declined',
            'skipped' => 'Auto Pay skipped'.(($skip = ['manual_renewal'=>'manual renewal', 'currency'=>'not in USD',
                'service_inactive'=>'service not active', 'renewal_off'=>'auto-renew off', 'owner_auto_renew_off'=>'auto-renew off', 'authority_changed'=>'account changed',
                'invoice_settled'=>'already paid', 'no_agreement'=>'no saved payment method', 'foreign_agreement'=>'payment method belongs to another customer',
                'gateway_missing'=>'gateway missing', 'gateway_disabled'=>'gateway off', 'agreement_changed'=>'payment method changed'][$evidence['reason'] ?? ''] ?? null) ? ': '.$skip : ''),
            default => 'Auto Pay: result missing',
        };
    }
    /** Request pill and its one reason line. A held request keeps its real cause; internal codes have no line. */
    public static function requestStatus(\Paymenter\Extensions\Servers\Domains\Models\Operation $op): array
    {
        $status=\Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::for(\Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::closed($op->state,$op->safe_code)?'disposed':$op->state);
        if ($op->safe_code!==null && isset(\Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::STATES[$op->state])) { $status['reason']=self::operationReason($op); }
        return $status;
    }
    public static function action(?string $value): string
    {
        return ['register'=>'Registration','transfer'=>'Transfer','renew'=>'Renewal','nameservers'=>'Nameservers',
            'contacts'=>'Contacts','contact_profile'=>'Contacts','lock'=>'Transfer lock','auto_renew_off'=>'Turn off registrar auto-renew',
            'auth_code_email'=>'Auth code email','privacy_purchase'=>'WHOIS privacy purchase','privacy_renew'=>'WHOIS privacy renewal',
            'privacy_enable'=>'WHOIS privacy on','privacy_disable'=>'WHOIS privacy off','dns'=>'DNS records','srv'=>'SRV record',
            'ds_add'=>'Add DNSSEC record','ds_delete'=>'Remove DNSSEC record','glue_add'=>'Add glue record',
            'glue_update'=>'Change glue record','glue_delete'=>'Remove glue record'][$value] ?? 'Other';
    }
    public static function operationReason(\Paymenter\Extensions\Servers\Domains\Models\Operation $op): ?string
    {
        if ($op->safe_code==='management_retired') {
            $actor=$op->attempt['management_terminal']['actor_id']??null;
            return $actor===null ? 'Cancelled' : 'Cancelled by '.((int)$actor===(int)$op->domain->user_id?'customer':'staff');
        }
        // These codes outlive the send, so "Not sent" would be false once the registrar has the order.
        if ($op->state!=='held'&&in_array($op->safe_code,['registration_correction_required','registration_evidence_required'],true)) {
            return $op->state==='succeeded'||$op->safe_code==='registration_correction_required' ? 'Still to fix: '.self::corrections($op) : 'Sent. Waiting for the registrar charge.';
        }
        // Money can still arrive after the order closes, so "not paid" may no longer be true.
        if ($op->safe_code===\Paymenter\Extensions\Servers\Domains\Services\UnpaidAcquisitionProof::CODE) {
            if (!RequestFacts::neverPaid($op)) { return 'Order cancelled: payment recorded later'; }
            if (\App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->exists()) { return 'Order cancelled: payment attempted later'; }
        }
        $reason=self::reason($op->safe_code);
        if ($reason===self::FEATURE_OFF) {
            return 'Not sent: '.(['renew'=>'Renewals','transfer'=>'Transfers','register'=>'Registrations'][$op->action]??'Changes').' off for this account';
        }
        if($op->registrar_key==='enom'&&$reason===self::reason('enom_test_call_cap')){
            $cache=app('request')->attributes;$key='domains.account.environment.'.$op->server_id;
            if(!$cache->has($key)){$cache->set($key,\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->value('environment'));}
            if($cache->get($key)==='live'){return 'Not sent: our daily call cap reached';}
        }
        return $reason;
    }
    /** Details the registrar did not take as ordered, from the attempt and later reads. */
    private static function corrections(\Paymenter\Extensions\Servers\Domains\Models\Operation $op): string
    {
        try { $a=$op->attempt??[]; $kinds=[...(array_keys($a['corrections']??[])?:($a['evidence']['registration']['corrections']??[])),...($op->policy['reconciliation']['observed_corrections']??[])]; }
        catch (\Throwable) { $kinds=[]; }
        $names=['nameservers'=>'nameservers','contacts'=>'contact details','auto_renew'=>'auto-renew','privacy'=>'WHOIS privacy'];
        return implode(', ',array_values(array_unique(array_filter(array_map(fn($kind)=>is_string($kind)?($names[$kind]??null):null,$kinds))))) ?: 'registrar details';
    }
    private const FEATURE_OFF='Not sent: feature off for this account';
    /** One short reason line: the cause, then the next step when staff have one. Held requests start "Not sent:". Null means an internal code with no line.
     * Unknown codes read Missing (see unknownReason). */
    public static function reason(?string $value): ?string
    {
        $map=['staff_review_required'=>'Put on hold by staff','contact_details_required'=>'Contact details needed','transfer_code_required'=>'Auth code needed',
            'transfer_code_timeout'=>'Auth code deadline passed','transfer_not_registered'=>'Domain not found at registry',
            'transfer_needs_action'=>'Old registrar needs approval','transfer_provider_rejected'=>'Registrar rejected the transfer',
            'stale_claim'=>'Registrar has not confirmed the result','poll_limit_reached'=>'Status checks stopped. Use Check status.',
            'read_unavailable'=>'Status check failed','budget_exhausted'=>'Status check failed','superseded'=>'Replaced by a newer request',
            'healthy_pending'=>'Waiting for old registrar','refreshed'=>null,'unresolved'=>'Status check failed',
            'renewal_definitely_unsent'=>'Renewal not sent','renewal_confirmation_required'=>'Registrar has not confirmed the result',
            'awaiting_unique_charge'=>'Registrar has not confirmed the charge','refund_approved'=>'Credit approved',
            'authority'=>'Not sent: registrar account changed',
            'reads'=>'Status check failed','cost'=>'Not sent: cost missing','funding'=>'Not sent: low registrar balance',
            'processing'=>'Not sent: orders paused','state'=>'Not sent: domain status changed','owner'=>'Not sent: domain not in registrar account',
            'renewal_admitted'=>null,'renewal_authority_invalid'=>'Not sent: registrar account changed',
            'renewal_anchor_changed'=>'Not sent: expiry date changed','renewal_prior_outcome_unresolved'=>'Registrar has not confirmed the result',
            'automatic_spend_paused'=>'Not sent: orders paused','renewal_acceptance_required'=>'Not sent: Renewals off for this account',
            'renewal_rate_limited'=>'Not sent: our request limit reached. Try later.','renewal_binding_changed'=>'Not sent: registrar account changed',
            'renewal_reads_unavailable'=>'Status check failed','provider_membership_unconfirmed'=>'Not sent: domain not in registrar account',
            'provider_auto_renew_unconfirmed'=>'Not sent: registrar auto-renew may be on','renewal_restore_refused'=>'Not sent: domain in redemption',
            'renewal_state_unconfirmed'=>'Registrar has not confirmed the result','renewal_term_refused'=>'Not sent: term not supported',
            'grace_capability_unaccepted'=>'Not sent: Renewals off for this account','renewal_cost_unconfirmed'=>'Not sent: cost missing',
            'registrar_balance_insufficient_or_unknown'=>'Not sent: low registrar balance',
            'reconciliation_required'=>'Registrar has not confirmed the result','prior_attempt'=>'Registrar has not confirmed the result',
            'namesilo_account_guard'=>"Paused: couldn't confirm the NameSilo default contact. Check the API key and contact email.",
            'pre_send_interrupted'=>'Registrar has not confirmed the result','claim_authority_invalid'=>'Not sent: registrar account changed',
            'seal_authority_invalid'=>'Not sent: registrar account changed','registration_authority_changed'=>'Sent. Registrar account changed before it finished',
            'registration_correction_required'=>'Not sent: contact details need fixing','registration_evidence_required'=>'Not sent: contact details need fixing',
            'transfer_setup_review_required'=>'Not sent: registrar account not set up','contacts_closed_unsent'=>'Cancelled before sending',
            'renewal_cancelled_unpaid'=>'Renewal cancelled: not paid','management_retired'=>'Cancelled',
            'management_unconfirmed'=>'Registrar has not confirmed the result','management_provider_refused'=>\Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::REFUSED.' The customer can try again.',
            'management_deferred'=>null,'readback_confirmed'=>null,
            'auth_email_accepted'=>'Auth code email sent','actual_charge_changed'=>'Registrar charged a different amount',
            'contact_profile_proof_required'=>'Not sent: contact details need fixing','write_disabled'=>self::FEATURE_OFF,
            'enom_test_transfer_call_cap'=>'Not sent: stopped by our test send check','enom_test_call_cap'=>'Not sent: stopped by our test send check','enom_test_management_call_cap'=>'Not sent: stopped by our test send check','enom_test_renewal_call_cap'=>'Not sent: stopped by our test send check',
            'enom_test_reconciliation_call_cap'=>'Registrar has not confirmed the result','enom_test_transfer_reconciliation_call_cap'=>'Registrar has not confirmed the result',
            'enom_test_transfer_setup_required'=>'Not sent: registrar account not set up','enom_test_setup_required'=>'Not sent: registrar account not set up',
            'enom_test_transfer_usd_balance_required'=>'Not sent: check the registrar balance first','usd_balance_required'=>'Not sent: check the registrar balance first',
            'configuration_unavailable'=>"Not sent: couldn't load the registrar account's settings",
            'write_rate_limited'=>'Not sent: our request limit reached. Try later.','write_acceptance_required'=>self::FEATURE_OFF,
            'nameservers_required'=>'Not sent: nameservers missing','registry_details_unsupported'=>'Not sent: registry needs extra details',
            'renewal_funding_or_authority'=>'Not sent: low registrar balance','renewal_cost_overrun'=>'Not sent: cost above limit',
            'target_changed'=>'Not sent: domain status changed','term'=>'Not sent: term not supported','balance'=>'Not sent: low registrar balance',
            'acquisition_cancelled_unpaid'=>'Order cancelled: not paid','registration_adopted'=>'Imported',
            'registration_managed'=>null,'ready'=>null,
            'expiry_unknown'=>'Expiry missing at the registry','no_coverage'=>'No public registry for this TLD',
            'not_registered'=>'No registry record found','deadline'=>'Public check timed out',
            'preference_off'=>'Monitoring alerts turned off','target_or_preference_changed'=>'Domain or alert settings changed',
            'daily_mail_limit'=>'Daily alert email limit reached','handoff_started'=>"Can't confirm the alert was sent.",
            'render_failed'=>'Alert template failed',
            'read_deferred'=>'Check delayed. It runs again later.','clock_reversal'=>'Server clock problem. Try again.',
            'profile_unavailable'=>"Couldn't load the check settings",'rotation'=>'Check not finished. It continues on the next run.',
            'resolver_failed'=>'DNS resolver failed','invalid_answer'=>'DNS answer invalid','ambiguous_empty'=>'DNS answer incomplete',
            'actual_cost_overrun'=>'Registrar cost is above the spending limit.','below_cost'=>'Registrar cost exceeds retail price',
            'confirmed'=>'Confirmed','correction_required'=>'Corrections needed','original_attempt_unresolved'=>'First try has no result yet.',
            'resumed'=>'Status checks resumed','transfer_rejection_contradiction'=>'Registrar says rejected, our records disagree.',
            'transfer_status_contradiction'=>'Registrar status differs from our records.','transfer_rejection_unreconciled'=>'Transfer rejection not confirmed',
            'late_retired_payment'=>'Paid after the order closed.','terminal_unsent'=>'Request closed without sending',
            'processing_off'=>'Orders paused for this account','billing_off'=>'Renewal invoices off for this account','missing_anchor'=>'Expiry missing. Check the domain.',
            'offline_provenance'=>'Demo order, no real invoice.','no_invoice_authority'=>'No invoice linked to this request.',
            'outside_billing_calendar'=>'Outside the renewal invoice dates','lifecycle_not_ready'=>'Domain not ready for renewal. Check the domain.',
            'database_failure'=>'Database error. Try again.','native_record_missing'=>'Paymenter record missing. Check the order.',
            'guard_refused'=>'Blocked by a safety check.','invalid_input'=>'Invalid input','invariant_failed'=>'Saved records disagree. Check at the registrar.',
            'producer_failed'=>'Background job failed','acquisition_provider_evidence'=>'Registrar result unclear. Check at the registrar.',
            'no_eligible_paid_line'=>'No paid invoice line for this order.','original_invoice_missing'=>'Original invoice missing. Check the order.',
            'read_failed'=>"Couldn't read from the registrar.",'read_exception'=>"Couldn't read from the registrar.",'read_deadline'=>"Registrar didn't answer in time",
            'read_no_evidence'=>'Registrar sent no answer.','read_identity_changed'=>'Domain or account changed during the check.',
            'account_unavailable'=>'Registrar account not found','binding_mismatch'=>'Request does not match this registrar account.',
            'configuration_unverified'=>'Registrar account not checked yet.','encryption_unavailable'=>"Can't read saved credentials. Ask your site admin to check the encryption key.",'storage_unavailable'=>'Database error. Try again.',
            'catalogue_reader_unavailable'=>self::priceSync('catalogue_reader_unavailable'),'catalogue_acceptance_unavailable'=>self::priceSync('catalogue_acceptance_unavailable'),
            'catalogue_acceptance_changed'=>self::priceSync('catalogue_acceptance_changed'),'catalogue_evidence_missing'=>self::priceSync('catalogue_evidence_missing'),
            'catalogue_evidence_invalid'=>self::priceSync('catalogue_evidence_invalid'),'catalogue_import_failed'=>self::priceSync('catalogue_import_failed'),'catalogue_interrupted'=>self::priceSync('catalogue_interrupted'),
            'awaiting_acceptance'=>self::FEATURE_OFF,
            'unverified'=>'Registrar has not confirmed the result','unsent'=>'Not sent to the registrar','acknowledged'=>'Registrar accepted the request',
            'fallback'=>'Registrar returned an unexpected result','proven_rejected'=>'Registrar rejected the request',
            'unsupported'=>'Registrar does not support this request','authority_changed'=>'Renewal settings changed. Check the domain.'];
        if (is_string($value) && array_key_exists($value, $map)) { return $map[$value]; }
        return self::unknownReason($value);
    }
    /** A failed price sync and its one cause, in the words the TLD pages use. */
    private static function priceSync(string $code): string { return 'Price sync failed: '.lcfirst(TldFacts::CAUSES[$code]); }
    /** Missing, plus the code when it is a safe snake_case code, so staff can report it. Other strings never echo. */
    private static function unknownReason(?string $value): string
    {
        $code=is_string($value) && strlen($value) <= 64 && preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $value) ? ' ('.$value.')' : '';
        return \Paymenter\Extensions\Servers\Domains\Presentation\Missing::LABEL.$code.'. Check the request history.';
    }
    public static function flag(mixed $value): string { return match ($value) { true=>'Yes', false=>'No', default=>'Missing' }; }
    public static function money(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{1,18}(?:\.\d{1,8})?$/D', $value)) { return 'Unknown'; }
        [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
        return 'USD '.$whole.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
