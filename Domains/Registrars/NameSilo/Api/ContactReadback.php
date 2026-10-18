<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

/** contactAdd uses ten digits; contactList documents a 3-3-4 hyphenated US/CA phone. */
final class ContactReadback
{
    public static function phoneMatches(#[\SensitiveParameter] mixed $actual, #[\SensitiveParameter] string $expected, string $country): bool
    {
        if (!is_string($actual)) { return false; }
        if ($actual === $expected) { return true; }
        return in_array($country, ['US', 'CA'], true) && preg_match('/^[0-9]{10}$/D', $expected) === 1
            && preg_match('/^[0-9]{3}-[0-9]{3}-[0-9]{4}$/D', $actual) === 1
            && str_replace('-', '', $actual) === $expected;
    }
}
