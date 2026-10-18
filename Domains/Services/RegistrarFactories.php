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
    public static function install(RegistrarRegistry $registry, ?AccountVerifier $enom = null, ?AccountVerifier $namesilo = null): void
    {
        if ($enom) {
            $registry->register('enom', ['uid', 'token'], $enom, static function (AccountBinding $binding, array $credentials): Enom\Driver {
                $accepted = CatalogueAcceptances::load($binding);
                return new Enom\Driver(new Enom\Api\Client($binding, $credentials['uid'], $credentials['token']), array_keys($accepted->endings));
            });
        }
        if ($namesilo) {
            $registry->register('namesilo', ['api_key'], $namesilo, static function (AccountBinding $binding, array $credentials): NameSilo\Driver {
                $accepted = CatalogueAcceptances::load($binding); $actions = [];
                foreach ($accepted->endings as $row) {
                    foreach (['register', 'renew', 'transfer'] as $action) { if ($row[$action] === [1]) { $actions[$action] = $action; } }
                }
                return new NameSilo\Driver(new NameSilo\Client($binding, $credentials['api_key']), array_values($actions));
            });
        }
    }
}
