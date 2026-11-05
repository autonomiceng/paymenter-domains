<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\{Enom, NameSilo};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CatalogueShare, EnomLive, EnomTestSendLimit, LiveReservations, OperationClaims, TestCatalogue};

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
            $this->allowance(...), $this->revision(...), $this->generation, $this->current);
    }
    public function feed(AccountBinding $binding): PricedFeed
    {
        OperationClaims::outside();
        $revision = $this->revision($binding);
        $credentials = $this->bindings->offerCredentials($binding, $revision);
        $current = function () use ($binding, $revision): void {
            if (!hash_equals($revision, $this->revision($binding))) { throw new \DomainException('Offer credentials changed.'); }
        };
        $account = Account::where('server_id', $binding->serverId)->sole();
        $identity = $account->stable_identity;
        if ($binding->registrarKey === 'enom') {
            if (!TestCatalogue::enabled($binding)) { throw new \DomainException('Offer reads are unavailable for this deployment.'); }
            if ($binding->environment === Environment::Live && !EnomLive::enabled($binding, 'catalogue')) {
                throw new \DomainException('Live offer reads unavailable.');
            }
            $transport = new Enom\OfferTransport($this->bindings, $binding, $revision, current: $current);
            $feed = new Enom\OfferFeed(new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token'], $transport), []));
        } else {
            // #386 is an explicit dependency; never silently fall back to the unadmitted cURL transport.
            if ($binding->registrarKey !== 'namesilo' || !is_file(dirname(__DIR__).'/Registrars/NameSilo/AdmittedTransport.php')
                || !class_exists(NameSilo\AdmittedTransport::class)) {
                throw new \DomainException('Admitted NameSilo offer transport unavailable.');
            }
            $transport = new NameSilo\AdmittedTransport($binding, function (bool $write) use ($binding, $revision, $current): array {
                if ($write) { throw new \DomainException('Offer transport is read only.'); }
                $current();
                return $this->bindings->offerCredentials($binding, $revision);
            });
            $feed = new NameSilo\OfferFeed(new NameSilo\Driver(new NameSilo\Client($binding, $credentials['api_key'], $transport), clock: $this->clock),
                facts: $binding->environment === Environment::Test ? fn(string $tld) => NameSilo\TestCatalogueFacts::read($binding,$revision,$tld,$this->clock) : null);
        }
        $scope = $binding->environment === Environment::Test && $binding->registrarKey === 'enom'
            ? static fn (\Closure $read) => EnomTestSendLimit::withCatalogueShare($identity, $read)
            : ($binding->environment === Environment::Live
                ? static fn (\Closure $read) => LiveReservations::withCatalogueShare($binding->registrarKey, $identity, $read, $binding->environment)
                : static fn (\Closure $read) => LiveReservations::withCheckoutHeadroom($binding->registrarKey, $identity, $read, CatalogueShare::PERCENT, $binding->environment));
        return new CredentialFeed($feed, $current, $scope);
    }

    /** Our catalogue share is 30% of our daily cap, enforced again atomically by each transport send. Live and Enom TEST
     * count catalogue sends only, and every send still stops at our cap. NameSilo TEST keeps its existing headroom rule.
     * The minute lease reserves the whole group once. These are our runaway guards, not registrar limits. */
    public function allowance(AccountBinding $binding, RequestOrigin $origin): int
    {
        OperationClaims::outside();
        try {
            if ($binding->registrarKey === 'enom' && !TestCatalogue::enabled($binding)) { return 0; }
            $this->revision($binding);
            if ($binding->environment === Environment::Test && $binding->registrarKey === 'enom') {
                $account = Account::where('server_id', $binding->serverId)->sole();
                $usage = EnomTestSendLimit::usage($account->stable_identity);
                foreach (['day', 'day_limit', 'minute', 'minute_limit', 'catalogue', 'catalogue_limit'] as $key) { if (!is_int($usage[$key])) { return 0; } }
                return max(0, min(25, $usage['catalogue_limit'] - $usage['catalogue'], $usage['day_limit'] - $usage['day'], $usage['minute_limit'] - $usage['minute']));
            }
            $policy = $binding->registrarKey === 'enom' ? new Enom\EnomLiveReservationPolicy : ($binding->environment === Environment::Test ? new NameSilo\TestReservationPolicy : new NameSilo\NameSiloLiveReservationPolicy);
            if ($binding->registrarKey === 'enom' && !EnomLive::enabled($binding, 'catalogue')) { return 0; }
            if ($binding->registrarKey === 'namesilo') { $policy->requireFeature($binding, 'catalogue'); }
            $reservations = new LiveReservations($policy,$binding->environment);
            if ($binding->environment === Environment::Test) {
                $cap = $policy->limits($binding)['daily_call_cap'] ?? null;
                if (!is_int($cap) || $cap < 1 || $cap > 100000) { return 0; }
                return max(0, min(25, CatalogueShare::limit($cap) - $reservations->usage($binding)['used']));
            }
            return min(25, $reservations->catalogueAllowance($binding));
        } catch (\Throwable) { return 0; }
    }
}
