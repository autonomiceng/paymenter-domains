<?php
// Adapted from Enom Api/DomainName.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

final class DomainName
{
    /** Syntax only: callers must separately prove ordinary/non-premium eligibility and ownership. */
    public static function parse(#[\SensitiveParameter] string $input, array $allowedTlds = ['com', 'net', 'org', 'us']): array
    {
        return \Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse($input, $allowedTlds);
    }
}
