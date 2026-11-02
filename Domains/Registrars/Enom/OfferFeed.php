<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Catalogue\{Feed, FactsObserved};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueFreshness, RequestContext};

/** Dormant feed adapter; no active catalogue caller. */
final class OfferFeed implements Feed
{
    public function __construct(private readonly Driver $reader, private readonly CatalogueFreshness $freshness = new CatalogueFreshness) {}
    public function prices(RequestContext $context): iterable { return []; }
    public function facts(string $tld, RequestContext $context): FactsObserved { throw new \DomainException('Offer facts unavailable.'); }
}
