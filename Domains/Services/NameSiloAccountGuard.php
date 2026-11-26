<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestContext};
use Paymenter\Extensions\Servers\Domains\Registrars\Dispatch\Accounts;

/** Entry point retained for the renewal writer and existing account settings readers. */
final class NameSiloAccountGuard
{
    public const EXPECTED = Accounts::NAMESILO_EXPECTED_CONTACT;
    public const PIN = Accounts::NAMESILO_CONTACT_PIN;

    public static function check(AccountBinding $binding, RequestContext $ctx, #[\SensitiveParameter] \Closure $exchange): void
    {
        Accounts::checkNameSiloAccount($binding, $ctx, $exchange);
    }
}
