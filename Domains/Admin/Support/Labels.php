<?php
// Adapted from Enom 444f31c Admin/Support/Labels; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
final class Labels
{
    public static function autoPay(mixed $evidence, ?string $invoiceStatus = null): string
    {
        if (!is_array($evidence)) { return 'Auto Pay: outcome unconfirmed; no retry'; }
        $invoice = match ($invoiceStatus) { 'paid'=>'paid', 'pending'=>'pending', 'cancelled'=>'cancelled', default=>'unavailable' };
        return (match ($evidence['status'] ?? null) {
            'submitted', 'charged' => 'Auto Pay: submitted',
            'declined' => ($evidence['notification'] ?? null) === 'requested'
                ? 'Auto Pay: declined; customer notification requested' : 'Auto Pay: declined; notification unavailable',
            'skipped' => 'Auto Pay skipped: '.(['manual_renewal'=>'manual renewal', 'currency'=>'USD required',
                'service_inactive'=>'service inactive', 'renewal_off'=>'renewal off', 'owner_auto_renew_off'=>'auto-renew off by owner', 'authority_changed'=>'authority changed',
                'invoice_settled'=>'invoice settled', 'no_agreement'=>'no saved method', 'foreign_agreement'=>'saved method owner differs',
                'gateway_missing'=>'gateway missing', 'gateway_disabled'=>'gateway disabled', 'agreement_changed'=>'saved method changed'][$evidence['reason'] ?? ''] ?? 'review required'),
            default => 'Auto Pay: outcome unconfirmed; no retry',
        }).'; invoice '.$invoice;
    }
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
    public static function operationReason(\Paymenter\Extensions\Servers\Domains\Models\Operation $op): string
    {
        $cache=app('request')->attributes;$key='domains.account.environment.'.$op->server_id;
        if($op->registrar_key==='enom'&&!$cache->has($key)){$cache->set($key,\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->value('environment'));}
        if($op->registrar_key==='enom'&&$cache->get($key)==='live'){
            if(in_array($op->safe_code,['enom_test_call_cap','enom_test_renewal_call_cap','enom_test_management_call_cap'],true)){
                return 'Live Enom request not sent. Check the account call and USD spend limits, canonical site and request deadline in Registrar setup. For registration or renewal, restore capacity and release the unsent hold. For management, retire this request before submitting a new change.';
            }
            return str_replace('TEST','live',self::reason($op->safe_code));
        }
        return self::reason($op->safe_code);
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
            'enom_test_transfer_call_cap'=>'TEST transfer not sent: a local Enom TEST check (daily call limit, counter, UTC day change or request deadline) stopped it before sending. Confirm Enom TEST daily calls on Registrar setup shows calls remain, use Read balance, then Release unsent hold before the transfer code deadline. Otherwise use Close and credit account.',
            'enom_test_transfer_setup_required'=>'TEST transfer not sent: check the verified user ID, balance, accepted ending and Enom TEST inbound transfer row on Registrar setup. If that row is missing or does not say “Deployment allows TEST inbound transfer”, contact the deployment administrator. After fixing setup, use Release unsent hold before the transfer code deadline; otherwise use Close and credit account.',
            'enom_test_call_cap'=>'TEST Purchase not sent: a local Enom TEST check (daily call limit, call counter, UTC day change or request time limit) stopped a request before sending. To retry, confirm Enom TEST daily calls on Registrar setup shows calls remain, use Read balance, then Release unsent hold. Otherwise use Close and credit account.',
            'enom_test_management_call_cap'=>'TEST management change was not sent: the daily call limit, counter, UTC day or request time check refused it. This change will not be retried. When Enom TEST daily calls on Registrar setup shows calls remain and TEST management acceptance and processing are on, the owner can submit the change again.',
            'enom_test_renewal_call_cap'=>'TEST renewal not sent: a local daily call limit, call counter, UTC day or request time check stopped it before sending. Confirm Enom TEST daily calls on Registrar setup shows calls remain, then use Release unsent hold. If calls cannot be restored or TEST renewal is off, use Close and credit account, then Allow replacement renewal so a new invoice can be issued for this cycle.',
            'enom_test_reconciliation_call_cap'=>'Enom TEST confirmation reads retry automatically when calls are available; the Purchase may have been sent, so never resend it or credit the customer. If Read limit reached appears, use Resume reconciliation reads; Resume scheduled reads only restarts domain observation.',
            'enom_test_transfer_reconciliation_call_cap'=>'TEST transfer confirmation reads stopped at an Enom TEST call guard. The original transfer is not sent again and no automatic credit is made. Once calls remain and the guard clears, reads resume automatically on the next runtime tick through transfer status and accepted charge history. If the read limit is reached, use Resume reconciliation reads.',
            'enom_test_transfer_usd_balance_required'=>'TEST transfer not sent: use Read balance for a successful USD balance read under an hour old, then Release unsent hold before the transfer code deadline. If release is refused, use Close and credit account.',
            'usd_balance_required'=>'TEST registration not sent: the worker needs a successful USD balance read under an hour old. Use Read balance, then Release unsent hold promptly. If release is refused, use Close and credit account.',
            'enom_test_setup_required'=>'TEST registration not sent: a setup check failed. Check the verified user ID, balance and Enom TEST registration rows on Registrar setup. If that row is missing or does not say “Deployment allows TEST registration”, contact the deployment administrator. Then use Release unsent hold. If it cannot be fixed, use Close and credit account.',
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
