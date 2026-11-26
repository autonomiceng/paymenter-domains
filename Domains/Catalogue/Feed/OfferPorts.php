<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Feed;
use Paymenter\Extensions\Servers\Domains\Catalogue\{PricedFeed, Writer};

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\Dispatch\Offers;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, OperationClaims};

/** Production composition, deliberately unregistered until the complete caller cutover. No I/O at construction. */
final class OfferPorts
{
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock,
        private readonly ?int $generation = null, private readonly ?\Closure $current = null) {}
    public function revision(AccountBinding $binding): string
    { if ($this->current) { ($this->current)($binding->serverId); } return $this->bindings->offerCredentialRevision($binding); }
    public function worker(Writer $writer): OfferWork
    {
        return new OfferWork($writer, $this->clock, new AccountReadBudget($this->clock, 25, 25), $this->feed(...),
            $this->allowance(...), $this->revision(...), $this->generation, $this->current,
            fn (AccountBinding $binding, string $revision): ?\Closure => Offers::testFactChecks($binding, $revision, $this->clock));
    }
    public function feed(AccountBinding $binding): PricedFeed
    {
        OperationClaims::outside();
        $revision = $this->revision($binding);
        $credentials = $this->bindings->offerCredentials($binding, $revision);
        $current = function () use ($binding, $revision): void {
            if (!hash_equals($revision, $this->revision($binding))) { throw new \DomainException('Offer credentials changed.'); }
        };
        $identity = Account::where('server_id', $binding->serverId)->sole()->stable_identity;
        return new CredentialFeed(Offers::feed($this->bindings, $binding, $revision, $credentials, $current, $this->clock),
            $current, Offers::share($binding, $identity));
    }

    /** Our catalogue share is 30% of our daily cap, enforced again atomically by each transport send. Live and Enom TEST
     * count catalogue sends only, and every send still stops at our cap. NameSilo TEST keeps its existing headroom rule.
     * The minute lease reserves the whole group once. These are our runaway guards, not registrar limits. */
    public function allowance(AccountBinding $binding, RequestOrigin $origin): int
    {
        OperationClaims::outside();
        try {
            return Offers::allowance($binding, $this->revision(...));
        } catch (\Throwable) { return 0; }
    }
}
