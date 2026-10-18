<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

final class RegistryInputs
{
    /** No registry-specific schema has been accepted. Missing schema is not an empty schema. */
    public static function supported(string $registrar, string $tld, mixed $schema): bool
    {
        $parts = explode('.', $tld);
        return in_array($registrar, ['enom', 'namesilo'], true) && $schema === []
            && !in_array($parts[array_key_last($parts)], ['us', 'ca', 'eu'], true);
    }

    public static function validate(string $registrar, string $tld, mixed $schema, #[\SensitiveParameter] mixed $values): array
    {
        if (!self::supported($registrar, $tld, $schema) || $values !== []) {
            throw new \DomainException('Registry details are not supported for this ending.');
        }
        return [];
    }
}
