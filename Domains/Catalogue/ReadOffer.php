<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, PriceSnapshot, RenewalPrice};

/** Server-only mapping evidence. Never accepts posted prices or synthesizes cost/row identity. */
final readonly class ReadOffer implements \JsonSerializable
{
    public function __construct(public Decision $decision, private AccountBinding $binding, private ?object $row, private Context $context, private ?\Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness $freshness = null,
        private ?Snapshot $snapshot = null, private ?Query $query = null, private ?Cost $orderCost = null) {}

    /** Original observation, including source unit/age, is not a spending grant. */
    public function observation(): ?Cost
    { return $this->orderCost ?? ($this->row?->catalogue_observation === null ? null : ($this->freshness ?? \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness::forAccount($this->binding->serverId))->cost(OfferState::cost(OfferState::decode($this->row->catalogue_observation)))); }

    /** Local row fence for a server-held order quote; never a price or spending grant. */
    public function identity(): array { return [$this->row?->id, $this->row?->revision]; }

    /** Re-evaluate every ordinary sale guard using server-held, exact-name order costs.
     * This transient snapshot never reaches Writer or the daily price table. */
    public function forOrder(array $costs, array $retail, \DateTimeImmutable $now): self
    {
        $s = $this->snapshot ?? throw new \LogicException('Order snapshot missing.');
        $q = $this->query ?? throw new \LogicException('Order query missing.');
        $feed = new FeedObserved($this->binding, $s->feed->tld(), array_values(array_replace($s->costs, $costs)));
        $snapshot = new Snapshot($feed, $s->facts, $s->intent, $s->revision, $s->publishedRevision,
            array_replace($s->retail, $retail), $s->seller, $s->sellerActions, $s->accountReady, $s->hidden, $s->retainedAnnualRetail);
        return new self((new Evaluator)->evaluate($snapshot, $q, $this->context, $now), $this->binding,
            $this->row, $this->context, $this->freshness, orderCost: $costs[$q->action.':'.$q->term] ?? null);
    }

    /** Staff stopped this sale or deselected this seller; unknown or stale data never counts. */
    public function closed(): bool
    { return $this->snapshot !== null && $this->query !== null && (new Evaluator)->closed($this->snapshot, $this->query); }

    /** Server-only native list fence; saved retail alone never grants a purchase. */
    public function listedRetail(): ?string
    { return OfferState::money($this->row?->retail, 2); }

    /** Staff display only: saved retail remains visible when the offer cannot be purchased. */
    public function staffRetail(): ?string
    {
        if ($this->context !== Context::Staff) { throw new \LogicException('Staff snapshot required.'); }
        return $this->listedRetail();
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

    /** Normalized prospective cost only; holder processing does not inherit public sale intent. */
    public function costPrice(\DateTimeImmutable $now): PriceSnapshot
    {
        $p=$this->row;$c=$this->observation();$retail=OfferState::money($p?->retail,2);
        if($this->context!==Context::Holder||!$p||!$c?->enabled||!$c->fresh($now)||$retail===null||$c->action!==$this->decision->action||$c->term!==$this->decision->term||$c->currency!==$p->currency){throw new \DomainException('No current normalized cost.');}
        return new PriceSnapshot((int)$p->id,(int)$p->revision,$this->binding->serverId,$this->binding->registrarKey,$this->binding->fingerprint,
            $this->decision->tld,$c->action,$c->term,$c->currency,$c->total,$retail,$c->source,$c->observedAt,$c->freshUntil,$this->decision->warning);
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
