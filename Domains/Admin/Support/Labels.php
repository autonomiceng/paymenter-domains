<?php
// Adapted from Enom 444f31c Admin/Support/Labels; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
final class Labels
{
    public static function statuses(): array
    {
        return ['adoption_pending'=>'Registration setup pending','active'=>'Active','registration_pending'=>'Registering','transfer_pending'=>'Transferring',
            'expired'=>'Expired','grace'=>'Expired, grace period','redemption'=>'Redemption','needs_review'=>'Needs review',
            'registration_rejected'=>'Registration rejected','transfer_rejected'=>'Transfer rejected','cancelled'=>'Cancelled'];
    }
    public static function status(?string $value): string { return self::statuses()[$value] ?? 'Unknown'; }
    public static function state(?string $value): string
    {
        return ['awaiting_payment'=>'Waiting for payment','awaiting_code'=>'Needs transfer code','queued'=>'Queued',
            'running'=>'Sending','pending'=>'In progress','uncertain'=>'Unconfirmed','held'=>'On hold',
            'succeeded'=>'Completed','rejected'=>'Rejected','disposed'=>'Closed by staff'][$value] ?? 'Unknown';
    }
    public static function color(?string $value): string
    {
        return match ($value) { 'active','succeeded'=>'success', 'adoption_pending','held','grace','awaiting_code'=>'warning',
            'uncertain','rejected','needs_review','transfer_rejected','registration_rejected','expired','redemption'=>'danger', default=>'gray' };
    }
    public static function action(?string $value): string
    {
        return ['register'=>'Registration','transfer'=>'Transfer','renew'=>'Renewal','nameservers'=>'Nameservers',
            'contacts'=>'Contacts','contact_profile'=>'Contact profile','lock'=>'Transfer lock change','auto_renew_off'=>'Registrar auto-renew off',
            'auth_code_email'=>'Transfer code email','privacy_purchase'=>'Privacy purchase','privacy_renew'=>'Privacy renewal',
            'privacy_enable'=>'Privacy on','privacy_disable'=>'Privacy off','dns'=>'DNS','srv'=>'SRV',
            'ds_add'=>'Add DS','ds_delete'=>'Remove DS','glue_add'=>'Add child nameserver',
            'glue_update'=>'Change child nameserver','glue_delete'=>'Remove child nameserver'][$value] ?? 'Other request';
    }
    public static function reason(?string $value): string
    {
        // Never echo provider messages, exception text or unrecognized stored codes.
        return ['staff_review_required'=>'Staff review required','contact_details_required'=>'Contact details required','transfer_code_required'=>'Transfer code required',
            'transfer_code_timeout'=>'Transfer code deadline passed','transfer_not_registered'=>'Registry found no domain',
            'transfer_needs_action'=>'Transfer needs attention','transfer_provider_rejected'=>'Registrar rejected transfer',
            'stale_claim'=>'Worker outcome unconfirmed','poll_limit_reached'=>'Read limit reached',
            'read_unavailable'=>'Registrar read failed','budget_exhausted'=>'Read budget exhausted','superseded'=>'Earlier generation',
            'healthy_pending'=>'Transfer pending at registrar','refreshed'=>'Saved facts refreshed','unresolved'=>'Check needs review',
            'renewal_definitely_unsent'=>'Renewal not sent','renewal_confirmation_required'=>'Renewal confirmation required',
            'awaiting_unique_charge'=>'Charge confirmation required','refund_approved'=>'Credit approved',
            'refund_completed'=>'Account credit recorded','authority'=>'Authority needs review',
            'reads'=>'Reads unavailable','cost'=>'Cost needs review','funding'=>'Funding needs review',
            'processing'=>'Processing is paused for this account. Enable processing, then use Release unsent hold.','state'=>'Domain state needs review','owner'=>'Ownership needs review',
            'renewal_admitted'=>'Renewal admitted','renewal_authority_invalid'=>'Renewal authority needs review',
            'renewal_anchor_changed'=>'Renewal expiry changed','renewal_prior_outcome_unresolved'=>'Prior outcome unresolved',
            'automatic_spend_paused'=>'Automatic spending paused','renewal_acceptance_required'=>'Renewal acceptance required',
            'renewal_rate_limited'=>'Renewal rate limited','renewal_binding_changed'=>'Registrar binding changed',
            'renewal_reads_unavailable'=>'Renewal reads unavailable','provider_membership_unconfirmed'=>'Registrar ownership unconfirmed',
            'provider_auto_renew_unconfirmed'=>'Registrar auto-renew unconfirmed','renewal_restore_refused'=>'Restoration refused',
            'renewal_state_unconfirmed'=>'Renewal state unconfirmed','renewal_term_refused'=>'Renewal term refused',
            'grace_capability_unaccepted'=>'Grace renewal not accepted','renewal_cost_unconfirmed'=>'Renewal cost unconfirmed',
            'registrar_balance_insufficient_or_unknown'=>'Registrar balance insufficient or unknown',
            'reconciliation_required'=>'Original outcome unresolved','prior_attempt'=>'Prior attempt unresolved',
            'pre_send_interrupted'=>'Preparation interrupted; review evidence','claim_authority_invalid'=>'Claim authority needs review',
            'seal_authority_invalid'=>'Send authority needs review','registration_authority_changed'=>'Registration authority changed',
            'registration_correction_required'=>'Registration corrections required','registration_evidence_required'=>'Registration evidence required',
            'transfer_setup_review_required'=>'Transfer setup needs review','contacts_closed_unsent'=>'Contact setup closed before send',
            'renewal_cancelled_unpaid'=>'Unpaid renewal cancelled','management_retired'=>'Management request retired',
            'management_unconfirmed'=>'Management outcome unconfirmed','management_provider_refused'=>'Registrar refused management request',
            'management_deferred'=>'Management checks deferred','readback_confirmed'=>'Readback confirmed; review retained requirements',
            'auth_email_accepted'=>'Transfer code email accepted','actual_charge_changed'=>'Actual registrar charge changed',
            'contact_profile_proof_required'=>'Contact profile evidence required','write_disabled'=>'Registrar writes disabled',
            'enom_test_call_cap'=>'TEST Purchase not sent: the Enom TEST daily call limit, its counter, or a UTC day change refused it before sending. Check Enom TEST daily calls on Registrar setup. To retry once calls are available, use Read balance (a successful USD read under an hour old is needed when the worker runs), then Release unsent hold. Otherwise use Close and credit account.',
            'enom_test_reconciliation_call_cap'=>'TEST Purchase sent or outcome unknown. Confirmation reads were paused by the Enom TEST daily call limit, its counter, or a UTC day change. They resume automatically once calls are available (after 00:00 UTC or after the deployment raises the limit). If the read limit is reached, use Resume reconciliation reads. The Purchase is never resent; do not credit.',
            'usd_balance_required'=>'The latest balance read must be a successful USD read under an hour old when the worker runs. Use Read balance, then Release unsent hold promptly.',
            'enom_test_setup_required'=>'TEST registration not sent: a setup check failed. Check the account binding, balance, and Enom TEST registration row on Registrar setup. If that row is missing or does not say “Deployment allows TEST registration”, contact the deployment administrator. Then use Release unsent hold. If it cannot be fixed, use Close and credit account.',
            'write_rate_limited'=>'Registrar writes rate limited','write_acceptance_required'=>'Registrar write acceptance required',
            'nameservers_required'=>'Nameservers required','registry_details_unsupported'=>'Registry details need review',
            'renewal_funding_or_authority'=>'Renewal funding or authority needs review','renewal_cost_overrun'=>'Renewal cost exceeds authorized limit',
            'target_changed'=>'Check target changed','term'=>'Term needs review','balance'=>'Registrar balance needs review',
            'acquisition_cancelled_unpaid'=>'Unpaid order retired','registration_adopted'=>'Registration adopted',
            'registration_managed'=>'Registration managed','ready'=>'Ready for check',
            'expiry_unknown'=>'Registry expiry unknown','no_coverage'=>'Public registry coverage unavailable',
            'not_registered'=>'No registry record found','deadline'=>'Public check deadline passed',
            'preference_off'=>'Public notices turned off','target_or_preference_changed'=>'Public notice target or preferences changed',
            'daily_mail_limit'=>'Public notice daily email limit reached','handoff_started'=>'Public notice handoff unconfirmed',
            'render_failed'=>'Public notice template could not render',
            'awaiting_acceptance'=>'Awaiting feature acceptance'][$value] ?? 'Review retained evidence';
    }
    public static function flag(mixed $value): string { return match ($value) { true=>'Yes', false=>'No', default=>'Unknown' }; }
    public static function money(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{1,18}(?:\.\d{1,8})?$/D', $value)) { return 'Unknown'; }
        [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
        return 'USD '.$whole.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
