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

    public function renewal(Domain $domain, string $currency, \DateTimeImmutable $now, bool $lock = false, ?\Paymenter\Extensions\Servers\Domains\Models\Tld $locked = null): RenewalPrice
    {
        return $this->catalogue->renewal((int) $domain->server_id, $domain->account_fingerprint, $domain->tld, $currency, $now, $lock, $locked);
    }

    /** Compatibility contract delegates to the sole locked native save authority. */
    public function assertSave(Service $service, Domain $domain, \DateTimeImmutable $now): void
    {
        NativeDomainGuard::assertSave($service, $domain, $now, $this);
    }
}
