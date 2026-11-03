<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Registrars\{Enom, NameSilo};
/** Explicit reader composition; local enrollment authenticates a key without claiming provider identity.
 * Enom continues to require its positive provider identity verifier.
 * Factories construct read handles only. Registration wire mapping/installation belongs to21/39.
 */
final class RegistrarFactories
{
    /** Deployment opt-in. No default environment and no spending or scheduler port registration. */
    public static function bootstrap(RegistrarRegistry $registry): bool
    {
        $sandbox=LocalAccountEnrollment::bootstrap($registry) || NameSiloSandbox::bootstrap($registry);
        $environment = \Paymenter\Extensions\Servers\Domains\Data\Environment::tryFrom((string)config('domains.enom_identity.environment', ''));
        if (!$environment) { return $sandbox; }
        $registry->register('enom', ['uid', 'token'], new Enom\AccountVerifier($environment),
            static function(AccountBinding $binding, #[\SensitiveParameter] array $credentials) use ($environment): Enom\CataloguePreview|Enom\EnomTestReconciliationReader {
                if ($binding->environment !== $environment) { throw new IdentityRefused('identity_environment_refused'); }
                if (!TestCatalogue::enabled($binding)) { throw new \DomainException('TEST catalogue reads are unavailable.'); }
                $endings = self::endings($binding);
                $driver=new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token']), $endings);
                $preview=new Enom\CataloguePreview($driver);
                return (EnomTestRegistration::enabled($binding)||(EnomTestRenewal::enabled($binding)||EnomTestManagement::enabled($binding)||EnomTestTransfer::enabled($binding))||EnomTestRegistration::recoveryEnabled($binding))?new Enom\EnomTestReconciliationReader($preview,$driver):$preview;
            },
            static function(AccountBinding $binding, #[\SensitiveParameter] array $credentials) use ($environment): Enom\Driver {
                if ($binding->environment !== $environment) { throw new IdentityRefused('identity_environment_refused'); }
                return new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token']), []);
            }, true,
            static function(AccountBinding $binding, #[\SensitiveParameter] array $credentials) use ($environment): Enom\Driver {
                if ($binding->environment !== $environment || !(EnomTestRegistration::enabled($binding)||EnomTestTransfer::enabled($binding))) {
                    throw new IdentityRefused('identity_environment_refused');
                }
                $endings=self::endings($binding);
                return new Enom\Driver(new Enom\Api\Client($binding,$credentials['uid'],$credentials['token']),$endings);
            });
        return true;
    }
    /** Enom uses these suffixes for read/reconciliation parsing. Sending has separate fresh-fact gates. */
    private static function endings(AccountBinding $b): array
    { return ($caps=\Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::routed()) ? $caps->identities($b) : array_keys(CatalogueAcceptances::load($b)->endings); }
    public static function install(RegistrarRegistry $registry, ?AccountVerifier $enom = null, ?AccountVerifier $namesilo = null): void
    {
        if ($enom) {
            $registry->register('enom', ['uid', 'token'], $enom, static function (AccountBinding $binding, #[\SensitiveParameter] array $credentials): Enom\Driver {
                $endings = self::endings($binding);
                return new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token']), $endings);
            }, static fn(AccountBinding $binding, #[\SensitiveParameter] array $credentials): Enom\Driver =>
                new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token']), []),
                adoptionReaderFactory: static fn(AccountBinding $binding, #[\SensitiveParameter] array $credentials, \Paymenter\Extensions\Servers\Domains\Contracts\Clock $clock): Enom\EnomAdoptionReader =>
                    new Enom\EnomAdoptionReader($binding, $credentials['uid'], $credentials['token'], new Enom\Api\CurlTransport, $clock, Runtime::limiter()));
        }
        if ($namesilo) {
            $registry->register('namesilo', ['api_key'], $namesilo, static function (AccountBinding $binding, #[\SensitiveParameter] array $credentials): NameSilo\Driver {
                if ($caps=\Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::routed()) {
                    $actions=array_values(array_filter(['register','renew','transfer'],static fn($action)=>$caps->endings($binding,$action)!==[]));
                    return new NameSilo\Driver(new NameSilo\Client($binding,$credentials['api_key']),$actions);
                }
                $accepted = CatalogueAcceptances::load($binding); $actions = [];
                foreach ($accepted->endings as $row) {
                    foreach (['register', 'renew', 'transfer'] as $action) { if ($row[$action] === [1]) { $actions[$action] = $action; } }
                }
                return new NameSilo\Driver(new NameSilo\Client($binding, $credentials['api_key']), array_values($actions));
            });
        }
    }
}
