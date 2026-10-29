<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Exact class/message pairs only. Never return exception text, arguments or previous causes. */
final class CartHandoffFailure
{
    private const MESSAGES = [
        \DomainException::class => [
            '' => 'invalid_action_or_term',
            'Offer unavailable.' => 'offer_unavailable',
            'Offer changed.' => 'offer_changed',
            'This acquisition is unavailable.' => 'acquisition_unavailable',
            'Seller server is disabled.' => 'seller_server_disabled',
            'Catalogue account unavailable.' => 'catalogue_account_unavailable',
            'Seller action or term is unavailable.' => 'seller_action_or_term_unavailable',
            'Registry details are not supported for this ending.' => 'registry_details_unsupported',
            'Acquisition acceptance unavailable.' => 'acquisition_acceptance_unavailable',
            'Catalogue publication evidence missing.' => 'publication_evidence_missing',
            'Catalogue publication evidence changed.' => 'publication_evidence_changed',
            'Catalogue acceptance is unavailable.' => 'catalogue_acceptance_unavailable',
            'Catalogue binding mismatch.' => 'catalogue_binding_mismatch',
            'No current eligible price.' => 'eligible_price_missing',
            'Price rule changed; republication required.' => 'price_rule_changed',
            'Bound Domains account required.' => 'bound_account_required',
            'Invalid default pricing rule.' => 'default_pricing_rule_invalid',
            'Reserved domain slug collision.' => 'product_slug_collision',
            'Duplicate domain product.' => 'duplicate_domain_product',
            'Domain product identity changed.' => 'product_identity_changed',
            'Invalid domain product mapping.' => 'product_mapping_invalid',
            'Invalid domain plan mapping.' => 'plan_mapping_invalid',
            'Domain line requires one name, no options and USD.' => 'domain_line_invalid',
            'Enter a domain name.' => 'domain_name_missing',
            'Domain already in cart.' => 'domain_already_in_cart',
            'Registrar changed. Search again.' => 'registrar_changed',
            'Acquisition unavailable.' => 'eligibility_action_unavailable',
            'Eligibility account changed.' => 'eligibility_account_changed',
            'Domain eligibility could not be confirmed.' => 'eligibility_unconfirmed',
            'Domains server is unavailable.' => 'domains_server_unavailable',
            'Registrar binding mismatch.' => 'registrar_binding_mismatch',
            'Registrar configuration could not be verified.' => 'registrar_configuration_unverified',
            'Encrypted registrar settings are required.' => 'encrypted_settings_required',
            'Read budget binding changed.' => 'read_budget_binding_changed',
            'Transfer eligibility is not configured.' => 'transfer_eligibility_unconfigured',
            'Transfer eligibility remains unverified.' => 'transfer_eligibility_unverified',
            'Transfer ending is not accepted.' => 'transfer_ending_unaccepted',
            'Invalid transfer lock policy.' => 'transfer_lock_policy_invalid',
            'Transferability could not be confirmed.' => 'transferability_unconfirmed',
            'Transfer check expired.' => 'transfer_check_expired',
            'transfer_locked' => 'transfer_locked',
            'transfer_redemption' => 'transfer_redemption',
            'transfer_pending_delete' => 'transfer_pending_delete',
            'transfer_pending' => 'transfer_pending',
            'transfer_creation_lock' => 'transfer_creation_lock',
            'transfer_previous_lock' => 'transfer_previous_lock',
            'transfer_maximum_term' => 'transfer_maximum_term',
        ],
        ExpiredCostEvidence::class => ['Saved cost evidence expired.' => 'saved_cost_expired'],
        RuntimeIneligible::class => ['Registrar processing is disabled.' => 'processing_disabled'],
        ReadDeferred::class => ['Account read budget unavailable.' => 'account_read_budget_unavailable'],
        EnomTestCallLimit::class => ['Our send check stopped this Enom request before sending: send limit, setting, counter or UTC day change.' => 'test_call_limit'],
        IdentityRefused::class => [
            'identity_credentials_refused' => 'identity_credentials_refused',
            'identity_unavailable' => 'identity_unavailable',
            'identity_environment_refused' => 'identity_environment_refused',
            'identity_account_changed' => 'identity_account_changed',
            'namesilo_identity_unavailable' => 'namesilo_identity_unavailable',
        ],
        WriteHeld::class => ['Registrar write is held.' => 'write_held'],
        \LogicException::class => ['Eligibility reads require an unlocked cart.' => 'eligibility_cart_locked'],
        \RuntimeException::class => [
            'Registrar reader could not be created.' => 'registrar_reader_unavailable',
            'Registrar request deadline reached.' => 'request_deadline_reached',
            'Registrar request budget exhausted.' => 'request_budget_exhausted',
        ],
    ];

    public static function key(\Throwable $error): string
    {
        return self::MESSAGES[$error::class][$error->getMessage()] ?? 'unclassified';
    }
}
