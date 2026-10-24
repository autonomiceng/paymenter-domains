<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Registrars\{Enom, NameSilo};
/** Production reader construction, opt-in only after accepting a positive provider identity verifier.
 * An absent NameSilo stable-ID verifier leaves configuration blocked; no label/key fallback.
 * Factories construct read handles only. Registration wire mapping/installation belongs to21/39.
 */
final class RegistrarFactories
{
    /** Deployment opt-in. No default environment and no spending or scheduler port registration. */
    public static function bootstrap(RegistrarRegistry $registry): bool
    {
        $environment = \Paymenter\Extensions\Servers\Domains\Data\Environment::tryFrom((string)config('domains.enom_identity.environment', ''));
        if (!$environment) { return false; }
        $registry->register('enom', ['uid', 'token'], new Enom\AccountVerifier($environment),
            static function(AccountBinding $binding, #[\SensitiveParameter] array $credentials) use ($environment): Enom\CataloguePreview|Enom\EnomTestReconciliationReader {
                if ($binding->environment !== $environment) { throw new IdentityRefused('identity_environment_refused'); }
                if (!TestCatalogue::enabled($binding)) { throw new \DomainException('TEST catalogue reads are unavailable.'); }
                $accepted = CatalogueAcceptances::load($binding);
                $driver=new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token']), array_keys($accepted->endings));
                $preview=new Enom\CataloguePreview($driver);
                return EnomTestRegistration::enabled($binding)?new Enom\EnomTestReconciliationReader($preview,$driver):$preview;
            },
            static function(AccountBinding $binding, #[\SensitiveParameter] array $credentials) use ($environment): Enom\Driver {
                if ($binding->environment !== $environment) { throw new IdentityRefused('identity_environment_refused'); }
                return new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token']), []);
            }, true,
            static function(AccountBinding $binding, #[\SensitiveParameter] array $credentials) use ($environment): Enom\Driver {
                if ($binding->environment !== $environment || !EnomTestRegistration::enabled($binding)) {
                    throw new IdentityRefused('identity_environment_refused');
                }
                $accepted=CatalogueAcceptances::load($binding);
                return new Enom\Driver(new Enom\Api\Client($binding,$credentials['uid'],$credentials['token']),array_keys($accepted->endings));
            });
        return true;
    }
    public static function install(RegistrarRegistry $registry, ?AccountVerifier $enom = null, ?AccountVerifier $namesilo = null): void
    {
        if ($enom) {
            $registry->register('enom', ['uid', 'token'], $enom, static function (AccountBinding $binding, #[\SensitiveParameter] array $credentials): Enom\Driver {
                $accepted = CatalogueAcceptances::load($binding);
                return new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token']), array_keys($accepted->endings));
            }, static fn(AccountBinding $binding, #[\SensitiveParameter] array $credentials): Enom\Driver =>
                new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token']), []));
        }
        if ($namesilo) {
            $registry->register('namesilo', ['api_key'], $namesilo, static function (AccountBinding $binding, #[\SensitiveParameter] array $credentials): NameSilo\Driver {
                $accepted = CatalogueAcceptances::load($binding); $actions = [];
                foreach ($accepted->endings as $row) {
                    foreach (['register', 'renew', 'transfer'] as $action) { if ($row[$action] === [1]) { $actions[$action] = $action; } }
                }
                return new NameSilo\Driver(new NameSilo\Client($binding, $credentials['api_key']), array_values($actions));
            });
        }
    }
}
