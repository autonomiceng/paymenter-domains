<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\{Enom, NameSilo};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, EnomLive, EnomTestSendLimit, LiveReservations, OperationClaims};

/** Production composition, deliberately unregistered until the complete caller cutover. No I/O at construction. */
final class OfferPorts
{
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock) {}
    public function revision(AccountBinding $binding): string { return $this->bindings->offerCredentialRevision($binding); }
    public function worker(Writer $writer): OfferWork
    {
        return new OfferWork($writer, $this->clock, new AccountReadBudget($this->clock, 25), $this->feed(...),
            $this->allowance(...), $this->revision(...));
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
            if ($binding->environment === Environment::Live && !EnomLive::enabled($binding, 'catalogue')) {
                throw new \DomainException('Live offer reads unavailable.');
            }
            $transport = new Enom\OfferTransport($this->bindings, $binding, $revision);
            $feed = new Enom\OfferFeed(new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token'], $transport), []));
        } else {
            // #386 is an explicit dependency; never silently fall back to the unadmitted cURL transport.
            if ($binding->environment !== Environment::Live || !is_file(dirname(__DIR__).'/Registrars/NameSilo/AdmittedTransport.php')
                || !class_exists(NameSilo\AdmittedTransport::class)) {
                throw new \DomainException('Admitted NameSilo offer transport unavailable.');
            }
            $transport = new NameSilo\AdmittedTransport($binding, function (bool $write) use ($binding, $revision): array {
                if ($write) { throw new \DomainException('Offer transport is read only.'); }
                return $this->bindings->offerCredentials($binding, $revision);
            });
            $feed = new NameSilo\OfferFeed(new NameSilo\Driver(new NameSilo\Client($binding, $credentials['api_key'], $transport), clock: $this->clock));
        }
        $scope = $binding->environment === Environment::Test
            ? static fn (\Closure $read) => EnomTestSendLimit::withCheckoutHeadroom($identity, $read)
            : static fn (\Closure $read) => LiveReservations::withCheckoutHeadroom($binding->registrarKey, $identity, $read);
        return new CredentialFeed($feed, $current, $scope);
    }

    /** Our catalogue share is 30% of our daily cap; the atomic transport scope also preserves half for checkout.
     * The minute lease reserves the whole group once. Neither number claims a registrar limit. */
    public function allowance(AccountBinding $binding, RequestOrigin $origin): int
    {
        OperationClaims::outside();
        try {
            $this->revision($binding);
            if ($binding->environment === Environment::Test) {
                if ($binding->registrarKey !== 'enom') { return 0; }
                $account = Account::where('server_id', $binding->serverId)->sole();
                $usage = EnomTestSendLimit::usage($account->stable_identity);
                foreach (['day', 'day_limit', 'minute', 'minute_limit'] as $key) { if (!is_int($usage[$key])) { return 0; } }
                return max(0, min(25, intdiv($usage['day_limit'] * 3, 10) - $usage['day'], $usage['minute_limit'] - $usage['minute']));
            }
            $policy = $binding->registrarKey === 'enom' ? new Enom\EnomLiveReservationPolicy : new NameSilo\NameSiloLiveReservationPolicy;
            if ($binding->registrarKey === 'enom' && !EnomLive::enabled($binding, 'catalogue')) { return 0; }
            if ($binding->registrarKey === 'namesilo') { $policy->requireFeature($binding, 'catalogue'); }
            $cap = $policy->limits($binding)['daily_call_cap'] ?? null;
            if (!is_int($cap) || $cap < 1 || $cap > 100000) { return 0; }
            return max(0, min(25, intdiv($cap * 3, 10) - (new LiveReservations($policy))->usage($binding)['used']));
        } catch (\Throwable) { return 0; }
    }
}
