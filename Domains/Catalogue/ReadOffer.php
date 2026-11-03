<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, PriceSnapshot, RenewalPrice};

/** Server-only mapping evidence. Never accepts posted prices or synthesizes cost/row identity. */
final readonly class ReadOffer implements \JsonSerializable
{
    public function __construct(public Decision $decision, private AccountBinding $binding, private ?object $row, private Context $context) {}

    /** Original observation, including source unit/age, is not a spending grant. */
    public function observation(): ?Cost
    { return $this->row?->catalogue_observation === null ? null : OfferState::cost(OfferState::decode($this->row->catalogue_observation)); }

    /** Staff display only: saved retail remains visible when the offer cannot be purchased. */
    public function staffRetail(): ?string
    {
        if ($this->context !== Context::Staff) { throw new \LogicException('Staff snapshot required.'); }
        return UpgradeEvidence::money($this->row?->retail, 2);
    }

    public function price(): PriceSnapshot
    {
        $d = $this->decision; $p = $this->row;
        if ($this->context === Context::Holder || !$d->available || !$p || $p->catalogue_observation === null) { throw new \DomainException('No current offer price.'); }
        $cost = $this->observation();
        if (!$cost->enabled || $cost->action !== $d->action || $cost->term !== $d->term) { throw new \DomainException('Offer observation changed.'); }
        return new PriceSnapshot((int) $p->id, (int) $p->revision, $this->binding->serverId,
            $this->binding->registrarKey, $this->binding->fingerprint, $d->tld, $d->action, $d->term,
            $cost->currency, $cost->total, $d->retail, $cost->source, $cost->observedAt, $cost->freshUntil, $d->warning);
    }

    public function renewal(): RenewalPrice
    {
        $d = $this->decision;
        if ($this->context !== Context::Holder || !$d->available || $d->action !== 'renew' || $d->term !== 1) { throw new \DomainException('No retained annual retail.'); }
        return new RenewalPrice($this->row === null ? null : (int) $this->row->id,
            $this->row === null ? null : (int) $this->row->revision, $d->retail, 'USD', $d->warning !== null, $d->warning);
    }

    public function jsonSerialize(): array { return $this->decision->jsonSerialize(); }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('Persist evidence through an explicit encrypted adapter.'); }
}
