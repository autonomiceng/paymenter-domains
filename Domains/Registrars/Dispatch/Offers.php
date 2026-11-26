<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Dispatch;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Facts, PricedFeed, TermSupport};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\{Enom, NameSilo};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CatalogueShare, EnomLive, EnomTestRegistration, EnomTestRenewal,
    EnomTestSendLimit, EnomTestTransfer, LiveReservations, NameSiloFeatureSettings, TestCatalogue};

/** Catalogue reads by registrar: the offer feed, our send share and allowance, sales feature gates and TEST facts.
 * Callers keep the credential revision fence and their own refusal handling. */
final class Offers
{
    /** The provider feed bound to one credential revision. `$current` rejects a rotation before each read. */
    public static function feed(AccountBindings $bindings, AccountBinding $binding, string $revision,
        #[\SensitiveParameter] array $credentials, \Closure $current, Clock $clock): PricedFeed
    {
        if ($binding->registrarKey === 'enom') {
            if (!TestCatalogue::enabled($binding)) { throw new \DomainException('Offer reads are unavailable for this deployment.'); }
            if ($binding->environment === Environment::Live && !EnomLive::enabled($binding, 'catalogue')) {
                throw new \DomainException('Live offer reads unavailable.');
            }
            $transport = new Enom\OfferTransport($bindings, $binding, $revision, current: $current);
            return new Enom\OfferFeed(new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token'], $transport), []),
                expansion: $binding->environment === Environment::Test ? TermSupport::REVIEWED_EVIDENCE['enom'] : []);
        }
        // #386 is an explicit dependency; never silently fall back to the unadmitted cURL transport.
        if ($binding->registrarKey !== 'namesilo' || !is_file(dirname(__DIR__, 2).'/Registrars/NameSilo/AdmittedTransport.php')
            || !class_exists(NameSilo\AdmittedTransport::class)) {
            throw new \DomainException('Admitted NameSilo offer transport unavailable.');
        }
        $transport = new NameSilo\AdmittedTransport($binding, function (bool $write) use ($bindings, $binding, $revision, $current): array {
            if ($write) { throw new \DomainException('Offer transport is read only.'); }
            $current();
            return $bindings->offerCredentials($binding, $revision);
        });
        return new NameSilo\OfferFeed(new NameSilo\Driver(new NameSilo\Client($binding, $credentials['api_key'], $transport), clock: $clock),
            facts: $binding->environment === Environment::Test ? fn(string $tld) => NameSilo\TestCatalogueFacts::read($binding, $revision, $tld, $clock) : null);
    }

    /** The scope that books one catalogue read group against our send share for this account. */
    public static function share(AccountBinding $binding, string $identity): \Closure
    {
        return $binding->environment === Environment::Test && $binding->registrarKey === 'enom'
            ? static fn (\Closure $read) => EnomTestSendLimit::withCatalogueShare($identity, $read)
            : ($binding->environment === Environment::Live
                ? static fn (\Closure $read) => LiveReservations::withCatalogueShare($binding->registrarKey, $identity, $read, $binding->environment)
                : static fn (\Closure $read) => LiveReservations::withCheckoutHeadroom($binding->registrarKey, $identity, $read, CatalogueShare::PERCENT, $binding->environment));
    }

    /** Catalogue reads still open to this account now, at most 25. `$revision` fences the current credentials
     * and may throw; the caller turns any failure into zero. */
    public static function allowance(AccountBinding $binding, \Closure $revision): int
    {
        if ($binding->registrarKey === 'enom' && !TestCatalogue::enabled($binding)) { return 0; }
        $revision($binding);
        if ($binding->environment === Environment::Test && $binding->registrarKey === 'enom') {
            $account = Account::where('server_id', $binding->serverId)->sole();
            $usage = EnomTestSendLimit::usage($account->stable_identity);
            foreach (['day', 'day_limit', 'minute', 'minute_limit', 'catalogue', 'catalogue_limit'] as $key) { if (!is_int($usage[$key])) { return 0; } }
            return max(0, min(25, $usage['catalogue_limit'] - $usage['catalogue'], $usage['day_limit'] - $usage['day'], $usage['minute_limit'] - $usage['minute']));
        }
        $policy = self::reservations($binding);
        if ($binding->registrarKey === 'enom' && !EnomLive::enabled($binding, 'catalogue')) { return 0; }
        if ($binding->registrarKey === 'namesilo') { $policy->requireFeature($binding, 'catalogue'); }
        $reservations = new LiveReservations($policy, $binding->environment);
        if ($binding->environment === Environment::Test) {
            $cap = $policy->limits($binding)['daily_call_cap'] ?? null;
            if (!is_int($cap) || $cap < 1 || $cap > 100000) { return 0; }
            return max(0, min(25, CatalogueShare::limit($cap) - $reservations->usage($binding)['used']));
        }
        return min(25, $reservations->catalogueAllowance($binding));
    }

    /** Whether this deployment reads the catalogue for the account at all; capacity is separate. A refused
     * NameSilo feature throws. */
    public static function catalogueReadable(AccountBinding $binding): bool
    {
        if ($binding->registrarKey === 'enom') { return TestCatalogue::enabled($binding); }
        if ($binding->registrarKey !== 'namesilo' || !class_exists(NameSilo\AdmittedTransport::class)) { return false; }
        self::reservations($binding)->requireFeature($binding, 'catalogue');
        return true;
    }

    private static function reservations(AccountBinding $binding): Enom\EnomLiveReservationPolicy|NameSilo\TestReservationPolicy|NameSilo\NameSiloLiveReservationPolicy
    {
        return $binding->registrarKey === 'enom' ? new Enom\EnomLiveReservationPolicy
            : ($binding->environment === Environment::Test ? new NameSilo\TestReservationPolicy : new NameSilo\NameSiloLiveReservationPolicy);
    }

    /** Local deployment and protocol gates for selling one action, or any action when null. Setup asks whether the
     * action is switched on; a sale also needs its accepted evidence. Missing NameSilo runtime stays closed. */
    public static function salesFeature(AccountBinding $binding, ?string $action, bool $setup): bool
    {
        if ($binding->registrarKey === 'namesilo') {
            $feature = match ($action) { 'register'=>'registration','renew'=>'renewal','transfer'=>'transfer',null=>null,default=>'unsupported' };
            if (in_array($feature, ['renewal', 'transfer'], true)) {
                return $setup ? NameSiloFeatureSettings::enabled($binding, $feature) : NameSiloFeatureSettings::accepted($binding, $feature);
            }
            return NameSilo\TestRuntime::enabled($binding, $feature);
        }
        return match ($action) {
            'register' => $setup ? EnomTestRegistration::enabled($binding) : EnomTestRegistration::sellable($binding),
            'renew' => $setup ? EnomTestRenewal::enabled($binding) : EnomTestRenewal::accepted($binding),
            'transfer' => $setup ? EnomTestTransfer::enabled($binding) : EnomTestTransfer::accepted($binding),
            default => $action === null,
        };
    }

    private static function testFacts(AccountBinding $binding): bool
    { return $binding->registrarKey === 'namesilo' && $binding->environment === Environment::Test; }

    /** The evidence check an offer worker runs after reading NameSilo TEST facts; null elsewhere. */
    public static function testFactChecks(AccountBinding $binding, string $revision, Clock $clock): ?\Closure
    { return self::testFacts($binding) ? NameSilo\TestCatalogueFacts::checks($binding, $revision, $clock) : null; }

    /** TEST NameSilo evidence is an extra gate; callers still check ordinary freshness. */
    public static function testFactsAccepted(AccountBinding $binding, string $tld, ?Facts $facts, Clock $clock): bool
    { return !self::testFacts($binding) || NameSilo\TestCatalogueFacts::current($binding, $tld, $facts, $clock); }

    /** True only when NameSilo TEST facts for this ending still wait for accepted local evidence. */
    public static function testFactsWaiting(AccountBinding $binding, string $revision, string $tld, Clock $clock): bool
    { return self::testFacts($binding) && NameSilo\TestCatalogueFacts::hasEvidence($binding, $revision, $tld, $clock) === false; }
}
