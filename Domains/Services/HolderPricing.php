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

    /** The renewal price the domain pages show, tax included: the holder quote that Renew now and the next renewal invoice
     * use, not the price saved when the domain was last billed. That saved price is the fallback when no quote can be read. */
    public static function shown(Domain $domain, Service $service, \DateTimeImmutable $now, array &$memo = []): string
    {
        if ($domain->price_mode === 'standard' && $service->currency_code === 'USD' && ($user = $service->user) !== null) {
            try {
                $key = $domain->server_id.'|'.$domain->account_fingerprint.'|'.$domain->tld.'|'.$user->id;
                return $memo[$key] ??= NativeDomainGuard::gross(app(self::class)->renewal($domain, 'USD', $now)->retail, HoldingNativeLocks::tax($user));
            } catch (\Throwable) { /* Show the saved price instead. */ }
        }
        return RetailLine::decimal($service->price);
    }

    /** Compatibility contract delegates to the sole locked native save authority. */
    public function assertSave(Service $service, Domain $domain, \DateTimeImmutable $now): void
    {
        NativeDomainGuard::assertSave($service, $domain, $now, $this);
    }
}
