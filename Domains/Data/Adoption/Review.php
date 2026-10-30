<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data\Adoption;

/** Safe staff projection; no provider ID, binding, request key/digest or private reply. */
final readonly class Review implements \JsonSerializable
{
    public const LABELS = [
        'ready' => 'Checks passed. Import is off on this site.',
        'permission_denied' => "You don't have permission to preview imports.",
        'mapping_unconfirmed' => "Couldn't confirm the customer, order or annual domain service.",
        'native_expiry_present' => 'The service still has an expiry date. Ask the site administrator to set it up for Domains billing first.',
        'account_unconfirmed' => "Couldn't confirm the account, its mode or this site.",
        'competing_holding' => 'Another service or domain uses this name, including cancelled services.',
        'unresolved_debt' => 'An open registrar request or an unpaid or unconfirmed invoice blocks import.',
        'identity_unconfirmed' => "Couldn't confirm the exact name, account owner or registrar domain ID.",
        'preview_failed' => "Couldn't finish the check. Try again later. If it repeats, contact the site administrator.",
        'read_failed' => "Couldn't read from the registrar. Try again later.",
        'auto_renew_on' => 'Registrar auto-renew is on. Turn it off at the registrar, then check again.',
        'auto_renew_unknown' => "Couldn't confirm that registrar auto-renew is off.",
        'expiry_unconfirmed' => "Couldn't confirm the exact expiry at the registrar.",
        'expiry_disagrees' => 'The registrar expiry differs from another read or the old record. Fix the dates first.',
        'expired' => 'This domain has expired. Import needs a future expiry.',
        'state_unconfirmed' => "Couldn't confirm the domain is active with no hold or redemption.",
        'changed' => 'The service, permissions, account or price changed during the check. Check again.',
        'price_unconfirmed' => "Couldn't confirm the annual price, charge, tax or invoice timing.",
    ];
    public static function priceNotice(?string $code): string
    { return match ($code) {
        null => 'Current published price.',
        'below_cost' => 'Annual price is below registrar cost. Check the margin. No price floor is applied.',
        'unknown_price', 'unknown_margin' => 'Registrar cost missing. Check the published annual price.',
        default => "Saved annual price needs a check. Couldn't confirm the current cost.",
    }; }
    public function __construct(public string $code, public array $details = [], public ?int $previewId = null)
    { if (!isset(self::LABELS[$code])) { throw new \InvalidArgumentException('Unknown adoption review.'); } }
    public function jsonSerialize(): array
    { return ['code' => $this->code, 'message' => ($this->code === 'ready' && ($this->details['test_only'] ?? false) ? 'Test only. No real domain is imported.' : self::LABELS[$this->code]), 'details' => $this->details, 'preview_id' => $this->previewId, 'can_confirm' => false, 'live_anchor_eligible' => false]; }
}
