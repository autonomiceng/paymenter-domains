<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data\Adoption;

/** Safe staff projection; no provider ID, binding, request key/digest or private reply. */
final readonly class Review implements \JsonSerializable
{
    public const LABELS = [
        'ready' => 'Eligible preview. Adoption is not enabled yet.',
        'permission_denied' => 'You do not have permission to preview adoption.',
        'mapping_unconfirmed' => 'The customer, order or dedicated annual domain service could not be verified.',
        'native_expiry_present' => 'The native service still has an expiry date. Ask the site administrator to qualify it for Domains billing before preview.',
        'account_unconfirmed' => 'The account, environment or current site could not be verified.',
        'competing_holding' => 'Another registration service or managed holding uses this name, including retained cancelled services.',
        'unresolved_debt' => 'A retained registrar operation or unpaid or unverified invoice blocks adoption.',
        'identity_unconfirmed' => 'The exact name, account owner or provider domain ID could not be verified.',
        'preview_failed' => 'The preview could not be completed or retained. Try again later; if it repeats, contact the site administrator.',
        'read_failed' => 'Fresh registrar readback could not be completed. Try a new preview later.',
        'auto_renew_on' => 'Registrar auto-renew is on. Turn it off at the registrar, then read a new preview.',
        'auto_renew_unknown' => 'Registrar auto-renew could not be confirmed as off.',
        'expiry_unconfirmed' => 'An exact registrar expiry could not be confirmed.',
        'expiry_disagrees' => 'The registrar expiry disagrees with another read or the converted source expiry. Resolve the dates first.',
        'expired' => 'This name has expired. Initial adoption requires a future expiry.',
        'state_unconfirmed' => 'Ordinary active status without hold or redemption could not be confirmed.',
        'changed' => 'The service, permissions, account or price changed during readback. Start a new preview.',
        'price_unconfirmed' => 'The annual renewal terms, charge, tax or invoice timing could not be verified.',
    ];
    public static function priceNotice(?string $code): string
    { return match ($code) {
        null => 'Current published retail.',
        'below_cost' => 'Annual retail is below provider cost. Staff review is required; no price floor is applied.',
        'unknown_price', 'unknown_margin' => 'Provider cost is unknown. Published annual retail is retained for review.',
        default => 'Retained annual retail requires staff review; current cost could not be confirmed.',
    }; }
    public function __construct(public string $code, public array $details = [], public ?int $previewId = null)
    { if (!isset(self::LABELS[$code])) { throw new \InvalidArgumentException('Unknown adoption review.'); } }
    public function jsonSerialize(): array
    { return ['code' => $this->code, 'message' => ($this->code === 'ready' && ($this->details['test_only'] ?? false) ? 'Rehearsal checks passed. TEST evidence cannot establish a live holding.' : self::LABELS[$this->code]), 'details' => $this->details, 'preview_id' => $this->previewId, 'can_confirm' => false, 'live_anchor_eligible' => false]; }
}
