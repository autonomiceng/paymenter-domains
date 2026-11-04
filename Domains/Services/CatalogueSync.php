<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferScheduler,UpgradeGate};

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\CatalogueReader;
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueEntry, CatalogueFailure, CatalogueFreshness, CatalogueUnavailable, RequestContext};

final class CatalogueSync
{
    public function __construct(private readonly AccountBindings $bindings, private readonly PriceCatalogue $prices) {}
    public function refresh(int $serverId, RequestContext $context, CatalogueFreshness $freshness = new CatalogueFreshness, ?array $suffixes = null, ?StaffSettingsReview $review = null, bool $scheduled = false, ?int $generation = null): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Catalogue reads must finish outside locks.'); }
        if ($serverId !== $context->binding->serverId) { throw new \DomainException('Catalogue account mismatch.'); }
        $scheduler=OfferScheduler::routed() ?? throw new \DomainException(OfferUpgrade::MESSAGE);
        $scheduler->sync($context->binding,$context->origin,$generation);
        return ['synced'=>0,'failed'=>[],'queued'=>true];
    }
}
