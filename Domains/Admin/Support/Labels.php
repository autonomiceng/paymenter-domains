<?php
// Adapted from Enom 444f31c Admin/Support/Labels; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
final class Labels
{
    public static function statuses(): array
    {
        return ['active'=>'Active','registration_pending'=>'Registering','transfer_pending'=>'Transferring',
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
        return match ($value) { 'active','succeeded'=>'success', 'held','grace','awaiting_code'=>'warning',
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
            'processing'=>'Processing paused','state'=>'Domain state needs review','owner'=>'Ownership needs review',
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
            'write_rate_limited'=>'Registrar writes rate limited','write_acceptance_required'=>'Registrar write acceptance required',
            'nameservers_required'=>'Nameservers required','registry_details_unsupported'=>'Registry details need review',
            'renewal_funding_or_authority'=>'Renewal funding or authority needs review','renewal_cost_overrun'=>'Renewal cost exceeds authorized limit',
            'term'=>'Term needs review','balance'=>'Registrar balance needs review'][$value] ?? 'Review retained evidence';
    }
    public static function flag(mixed $value): string { return match ($value) { true=>'Yes', false=>'No', default=>'Unknown' }; }
    public static function money(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{1,18}(?:\.\d{1,8})?$/D', $value)) { return 'Unknown'; }
        [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
        return 'USD '.$whole.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
