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
            'submitted', 'charged' => 'Auto Pay: charged',
            'declined' => 'Auto Pay: declined',
            'skipped' => 'Auto Pay skipped'.(($skip = ['manual_renewal'=>'manual renewal', 'currency'=>'not in USD',
                'service_inactive'=>'service not active', 'renewal_off'=>'auto-renew off', 'owner_auto_renew_off'=>'auto-renew off', 'authority_changed'=>'account changed',
                'invoice_settled'=>'already paid', 'no_agreement'=>'no saved card', 'foreign_agreement'=>'card belongs to another customer',
                'gateway_missing'=>'gateway missing', 'gateway_disabled'=>'gateway off', 'agreement_changed'=>'card changed'][$evidence['reason'] ?? ''] ?? null) ? ': '.$skip : ''),
            default => 'Auto Pay: result missing',
        };
    }
    public static function statuses(): array
    {
        return ['adoption_pending'=>'Registration setup pending','active'=>'Active','registration_pending'=>'Registering','transfer_pending'=>'Transferring',
            'expired'=>'Expired','grace'=>'Expired, grace period','redemption'=>'Redemption','needs_review'=>'Needs review',
            'registration_rejected'=>'Registration rejected','transfer_rejected'=>'Transfer rejected','cancelled'=>'Cancelled'];
    }
    public static function status(?string $value): string { return self::statuses()[$value] ?? 'Unknown'; }
    /** Request pill and its one reason line. A held request keeps its real cause; internal codes have no line. */
    public static function requestStatus(\Paymenter\Extensions\Servers\Domains\Models\Operation $op): array
    {
        $status=\Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::for($op->state);
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
        $reason=self::reason($op->safe_code);
        if ($reason===self::FEATURE_OFF) {
            return 'Not sent: '.(['renew'=>'Renewals','transfer'=>'Transfers','register'=>'Registrations'][$op->action]??'Changes').' off for this account';
        }
        $cache=app('request')->attributes;$key='domains.account.environment.'.$op->server_id;
        if($op->registrar_key==='enom'&&!$cache->has($key)){$cache->set($key,\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->value('environment'));}
        if($op->registrar_key==='enom'&&$cache->get($key)==='live'&&$reason===self::reason('enom_test_call_cap')){ return self::reason('write_rate_limited'); }
        return $reason;
    }
    private const FEATURE_OFF='Not sent: feature off for this account';
    /** One reason line, at most 8 words, cause first. Held requests start "Not sent:". Null means an internal code with no line.
     * Unknown codes read Missing (see unknownReason). */
    public static function reason(?string $value): ?string
    {
        $map=['staff_review_required'=>'Put on hold by staff','contact_details_required'=>'Contact details needed','transfer_code_required'=>'Auth code needed',
            'transfer_code_timeout'=>'Auth code deadline passed','transfer_not_registered'=>'Domain not found at registry',
            'transfer_needs_action'=>'Old registrar needs approval','transfer_provider_rejected'=>'Registrar rejected the transfer',
            'stale_claim'=>'Registrar has not confirmed the result','poll_limit_reached'=>'Status check failed',
            'read_unavailable'=>'Status check failed','budget_exhausted'=>'Status check failed','superseded'=>'Replaced by a newer request',
            'healthy_pending'=>'Waiting for old registrar','refreshed'=>null,'unresolved'=>'Status check failed',
            'renewal_definitely_unsent'=>'Renewal not sent','renewal_confirmation_required'=>'Registrar has not confirmed the result',
            'awaiting_unique_charge'=>'Registrar has not confirmed the charge','refund_approved'=>'Refund approved',
            'refund_completed'=>'Refunded to account credit','authority'=>'Not sent: registrar account changed',
            'reads'=>'Status check failed','cost'=>'Not sent: cost missing','funding'=>'Not sent: low registrar balance',
            'processing'=>'Not sent: orders paused','state'=>'Not sent: domain status changed','owner'=>'Not sent: domain not in registrar account',
            'renewal_admitted'=>null,'renewal_authority_invalid'=>'Not sent: registrar account changed',
            'renewal_anchor_changed'=>'Not sent: expiry date changed','renewal_prior_outcome_unresolved'=>'Registrar has not confirmed the result',
            'automatic_spend_paused'=>'Not sent: orders paused','renewal_acceptance_required'=>'Not sent: Renewals off for this account',
            'renewal_rate_limited'=>'Not sent: registrar API limit reached','renewal_binding_changed'=>'Not sent: registrar account changed',
            'renewal_reads_unavailable'=>'Status check failed','provider_membership_unconfirmed'=>'Not sent: domain not in registrar account',
            'provider_auto_renew_unconfirmed'=>'Not sent: registrar auto-renew may be on','renewal_restore_refused'=>'Not sent: domain in redemption',
            'renewal_state_unconfirmed'=>'Registrar has not confirmed the result','renewal_term_refused'=>'Not sent: term not supported',
            'grace_capability_unaccepted'=>'Not sent: Renewals off for this account','renewal_cost_unconfirmed'=>'Not sent: cost missing',
            'registrar_balance_insufficient_or_unknown'=>'Not sent: low registrar balance',
            'reconciliation_required'=>'Registrar has not confirmed the result','prior_attempt'=>'Registrar has not confirmed the result',
            'pre_send_interrupted'=>'Registrar has not confirmed the result','claim_authority_invalid'=>'Not sent: registrar account changed',
            'seal_authority_invalid'=>'Not sent: registrar account changed','registration_authority_changed'=>'Not sent: registrar account changed',
            'registration_correction_required'=>'Not sent: contact details need fixing','registration_evidence_required'=>'Not sent: contact details need fixing',
            'transfer_setup_review_required'=>'Not sent: registrar account not set up','contacts_closed_unsent'=>'Cancelled before sending',
            'renewal_cancelled_unpaid'=>'Invoice not paid','management_retired'=>'Cancelled by staff',
            'management_unconfirmed'=>'Registrar has not confirmed the result','management_provider_refused'=>'Registrar refused the change',
            'management_deferred'=>null,'readback_confirmed'=>null,
            'auth_email_accepted'=>'Auth code email sent','actual_charge_changed'=>'Registrar charged a different amount',
            'contact_profile_proof_required'=>'Not sent: contact details need fixing','write_disabled'=>self::FEATURE_OFF,
            'enom_test_transfer_call_cap'=>'Not sent: Enom test daily limit reached','enom_test_call_cap'=>'Not sent: Enom test daily limit reached','enom_test_management_call_cap'=>'Not sent: Enom test daily limit reached','enom_test_renewal_call_cap'=>'Not sent: Enom test daily limit reached',
            'enom_test_reconciliation_call_cap'=>'Registrar has not confirmed the result','enom_test_transfer_reconciliation_call_cap'=>'Registrar has not confirmed the result',
            'enom_test_transfer_setup_required'=>'Not sent: registrar account not set up','enom_test_setup_required'=>'Not sent: registrar account not set up',
            'enom_test_transfer_usd_balance_required'=>'Not sent: check the registrar balance first','usd_balance_required'=>'Not sent: check the registrar balance first',
            'configuration_unavailable'=>"Not sent: couldn't load the registrar account's settings",
            'write_rate_limited'=>'Not sent: registrar API limit reached','write_acceptance_required'=>self::FEATURE_OFF,
            'nameservers_required'=>'Not sent: nameservers missing','registry_details_unsupported'=>'Not sent: registry needs extra details',
            'renewal_funding_or_authority'=>'Not sent: low registrar balance','renewal_cost_overrun'=>'Not sent: cost above limit',
            'target_changed'=>'Not sent: domain status changed','term'=>'Not sent: term not supported','balance'=>'Not sent: low registrar balance',
            'acquisition_cancelled_unpaid'=>'Order cancelled: not paid','registration_adopted'=>'Imported',
            'registration_managed'=>null,'ready'=>null,
            'expiry_unknown'=>'Registry expiry unknown','no_coverage'=>'Public registry coverage unavailable',
            'not_registered'=>'No registry record found','deadline'=>'Public check deadline passed',
            'preference_off'=>'Public notices turned off','target_or_preference_changed'=>'Public notice target or preferences changed',
            'daily_mail_limit'=>'Public notice daily email limit reached','handoff_started'=>'Public notice handoff unconfirmed',
            'render_failed'=>'Public notice template could not render',
            'read_deferred'=>'Registrar read deferred','clock_reversal'=>'Check clock moved backwards',
            'profile_unavailable'=>'Check profile unavailable','rotation'=>'Check rotation in progress',
            'resolver_failed'=>'DNS resolver failed','invalid_answer'=>'DNS answer invalid','ambiguous_empty'=>'DNS answer incomplete',
            'actual_cost_overrun'=>'Actual registrar cost exceeds authorized limit','below_cost'=>'Registrar cost exceeds retail price',
            'confirmed'=>'Confirmed','correction_required'=>'Corrections required','original_attempt_unresolved'=>'Original attempt unresolved',
            'resumed'=>'Reads resumed','transfer_rejection_contradiction'=>'Transfer rejection contradicts retained evidence',
            'transfer_status_contradiction'=>'Transfer status contradicts retained evidence','transfer_rejection_unreconciled'=>'Transfer rejection unconfirmed',
            'late_retired_payment'=>'Payment received after retirement','terminal_unsent'=>'Request closed without sending',
            'processing_off'=>'Registrar processing disabled','billing_off'=>'Renewal billing disabled','missing_anchor'=>'Confirmed expiry required',
            'offline_provenance'=>'Offline origin needs review','no_invoice_authority'=>'Invoice authority unavailable',
            'outside_billing_calendar'=>'Outside renewal invoice calendar','lifecycle_not_ready'=>'Renewal lifecycle needs review',
            'database_failure'=>'Database operation failed','native_record_missing'=>'Paymenter record missing',
            'guard_refused'=>'Operation guard refused','invalid_input'=>'Invalid input','invariant_failed'=>'Retained evidence inconsistent',
            'producer_failed'=>'Work failed','acquisition_provider_evidence'=>'Registrar evidence requires review',
            'no_eligible_paid_line'=>'Eligible paid invoice line missing','original_invoice_missing'=>'Original invoice missing',
            'read_failed'=>'Registrar read failed','read_exception'=>'Registrar read raised an exception','read_deadline'=>'Registrar read deadline passed',
            'read_no_evidence'=>'Registrar read returned no evidence','read_identity_changed'=>'Read identity changed',
            'account_unavailable'=>'Registrar account unavailable','binding_mismatch'=>'Registrar binding mismatch',
            'configuration_unverified'=>'Registrar configuration unverified','encryption_unavailable'=>'Encryption unavailable','storage_unavailable'=>'Storage unavailable',
            'catalogue_reader_unavailable'=>'Catalogue reader unavailable','catalogue_acceptance_unavailable'=>'Catalogue acceptance unavailable',
            'catalogue_acceptance_changed'=>'Catalogue acceptance changed','catalogue_evidence_missing'=>'Catalogue evidence missing',
            'catalogue_evidence_invalid'=>'Catalogue evidence invalid','catalogue_import_failed'=>'Catalogue import failed','catalogue_interrupted'=>'Catalogue refresh interrupted',
            'awaiting_acceptance'=>self::FEATURE_OFF,
            'unverified'=>'Registrar has not confirmed the result','unsent'=>'Not sent to the registrar','acknowledged'=>'Registrar accepted the request',
            'fallback'=>'Registrar returned an unexpected result','proven_rejected'=>'Registrar rejected the request',
            'unsupported'=>'Registrar does not support this request','authority_changed'=>'Renewal authority changed'];
        if (is_string($value) && array_key_exists($value, $map)) { return $map[$value]; }
        return self::unknownReason($value);
    }
    /** Missing, plus the code when it is a safe snake_case code, so staff can report it. Other strings never echo. */
    private static function unknownReason(?string $value): string
    {
        return is_string($value) && strlen($value) <= 64 && preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $value) ? \Paymenter\Extensions\Servers\Domains\Presentation\Missing::LABEL.' ('.$value.')'
            : \Paymenter\Extensions\Servers\Domains\Presentation\Missing::LABEL;
    }
    public static function flag(mixed $value): string { return match ($value) { true=>'Yes', false=>'No', default=>'Missing' }; }
    public static function money(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{1,18}(?:\.\d{1,8})?$/D', $value)) { return 'Unknown'; }
        [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
        return 'USD '.$whole.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
