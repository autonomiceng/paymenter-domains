<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Dispatch;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestContext};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{AccountGuard, CredentialAuthenticator};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\DefaultContact;

final class Accounts
{
    public const NAMESILO_EXPECTED_CONTACT = AccountGuard::EXPECTED;
    public const NAMESILO_CONTACT_PIN = AccountGuard::PIN;

    public static function checkNameSiloAccount(AccountBinding $binding, RequestContext $context, #[\SensitiveParameter] \Closure $exchange): void
    {
        AccountGuard::check($binding, $context, $exchange);
    }

    public static function checkNameSiloSandboxKey(#[\SensitiveParameter] array $credentials): void
    {
        CredentialAuthenticator::checkSandboxKey($credentials);
    }

    public static function nameSiloDefaultContactEmail(#[\SensitiveParameter] mixed $value): string
    {
        return DefaultContact::email($value);
    }
}
