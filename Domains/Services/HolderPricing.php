<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\Service;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, Capabilities};
use Paymenter\Extensions\Servers\Domains\Contracts\NativeRepriceGuard;
use Paymenter\Extensions\Servers\Domains\Data\RenewalPrice;
use Paymenter\Extensions\Servers\Domains\Models\Domain;

final class HolderPricing implements NativeRepriceGuard
{
    public function __construct(private readonly PriceCatalogue $catalogue, private readonly ?Capabilities $offers = null) {}

    public function renewal(Domain $domain, string $currency, \DateTimeImmutable $now, bool $lock = false, ?\Paymenter\Extensions\Servers\Domains\Models\Tld $locked = null): RenewalPrice
    {
        if ($offers = $this->offers ?? Capabilities::routed()) {
            if ($currency !== 'USD') { throw new \DomainException('USD holder retail required.'); }
            $b = AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $domain->server_id)->sole());
            if ($domain->account_fingerprint !== $b->fingerprint || $domain->registrar_key !== $b->registrarKey) { throw new \DomainException('Holder binding changed.'); }
            return $offers->holder($b, $domain->tld, $lock ? ($locked ?? throw new \LogicException('Locked holding TLD required.')) : null);
        }
        throw new \DomainException(OfferUpgrade::MESSAGE);
    }

    /** Compatibility contract delegates to the sole locked native save authority. */
    public function assertSave(Service $service, Domain $domain, \DateTimeImmutable $now): void
    {
        NativeDomainGuard::assertSave($service, $domain, $now, $this);
    }
}
