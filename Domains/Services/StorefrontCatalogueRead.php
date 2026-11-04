<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** One invocation's active public display. Checkout independently rechecks every selection. */
final class StorefrontCatalogueRead
{
    private ?array $offers = null;
    public function __construct(private readonly SellerPolicies $sellers, private readonly NativeCatalogue $native,
        private readonly \Paymenter\Extensions\Servers\Domains\Contracts\Clock $clock, private readonly ?array $wanted = null,
        private readonly ?AcquisitionOffers $catalogue = null) {}

    public function transfersOn(string $ending): bool
    {
        try { $this->offer($ending, 'transfer', 1); return true; }
        catch (\DomainException) { return false; }
    }

    public function offer(string $ending, string $action, int $term): array
    {
        $catalogue = $this->catalogue ?? \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(AcquisitionOffers::class)
            ?? throw new \DomainException(OfferUpgrade::MESSAGE);
        $this->offers ??= $catalogue->display($this->wanted ?? [$ending]);
        return $this->offers[$ending][$action.':'.$term] ?? throw new \DomainException('Offer unavailable.');
    }
}
