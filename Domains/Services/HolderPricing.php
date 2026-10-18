<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Service;
use Paymenter\Extensions\Servers\Domains\Contracts\NativeRepriceGuard;
use Paymenter\Extensions\Servers\Domains\Data\RenewalPrice;
use Paymenter\Extensions\Servers\Domains\Models\Domain;

final class HolderPricing implements NativeRepriceGuard
{
    public function __construct(private readonly PriceCatalogue $catalogue) {}

    public function renewal(Domain $domain, string $currency, \DateTimeImmutable $now): RenewalPrice
    {
        return $this->catalogue->renewal((int) $domain->server_id, $domain->account_fingerprint, $domain->tld, $currency, $now);
    }

    /** Contract implementation only; no native observer is installed by this slice. */
    public function assertSave(Service $service, Domain $domain, \DateTimeImmutable $now): void
    {
        // Status/cancellation/suspension saves never depend on catalogue or pricing mode.
        if ($service->isDirty(['product_id', 'plan_id', 'user_id', 'quantity', 'currency_code', 'coupon_id', 'expires_at'])) {
            throw new \DomainException('Managed binding changes require the locked renewal integration.');
        }
        if (!$service->isDirty('price')) { return; }
        if ((int) $service->id !== (int) $domain->service_id || (int) $service->user_id !== (int) $domain->user_id
            || (int) $service->product?->server_id !== (int) $domain->server_id || $service->quantity != 1
            || $service->currency_code !== 'USD' || $service->expires_at !== null || $domain->price_mode !== 'standard') {
            throw new \DomainException('Managed service binding or renewal display changed.');
        }
        try {
            $renewal = $this->renewal($domain, $service->currency_code, $now);
            if ($renewal->retained) { throw new \DomainException('Fresh price required for native repricing.'); }
            $expected = $renewal->retail;
        } catch (\Throwable) { throw new \DomainException('Unknown renewal price cannot authorize native repricing.'); }
        if (PricingRule::minor($service->price) !== PricingRule::minor($expected)) {
            throw new \DomainException('Native price must equal the holding account annual renewal.');
        }
    }
}
