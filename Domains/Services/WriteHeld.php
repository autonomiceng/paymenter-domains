<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
final class WriteHeld extends \DomainException
{
    /** A retry cannot change these answers, so the way out is to cancel and credit. */
    public const FINAL_REFUSALS = ['name_unavailable', 'premium_name', 'order_not_supported'];
    /** Raised by preflight reads and order checks, before the seal, so nothing reached the registrar. */
    public const UNSENT_REFUSALS = [...self::FINAL_REFUSALS, 'registrar_price_missing', 'registrar_read_failed', 'registrar_balance_insufficient'];
    public function __construct(public readonly string $reason, public readonly ?\Paymenter\Extensions\Servers\Domains\Data\Money $cost = null)
    {
        if (!in_array($reason, ['namesilo_account_guard', 'transfer_not_registered', 'write_disabled', 'write_acceptance_required', 'write_rate_limited', 'contact_profile_proof_required', 'nameservers_required', 'registry_details_unsupported', 'usd_balance_required','enom_test_transfer_usd_balance_required', 'enom_test_setup_required', ...self::UNSENT_REFUSALS], true)) {
            throw new \InvalidArgumentException('Invalid write hold.');
        }
        parent::__construct('Registrar write is held.');
    }
}
