<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\UsNexus;

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
        // Preparation only: a trusted frozen setup can carry nexus inputs, but publication
        // stays closed in supported() until collection and provider semantics are accepted.
        if ($registrar === 'namesilo' && $tld === 'us' && $schema === []) {
            self::nameSiloNexus($tld, $values);
            return $values;
        }
        if (!self::supported($registrar, $tld, $schema) || $values !== []) {
            throw new \DomainException('Registry details are not supported for this ending.');
        }
        return [];
    }

    /** Explicit frozen category/purpose only; no defaults or arbitrary registry attributes. */
    public static function nameSiloNexus(string $tld, #[\SensitiveParameter] mixed $values): ?UsNexus
    {
        if ($tld !== 'us') {
            if ($values !== []) { throw new \DomainException('Registry details are not supported for this ending.'); }
            return null;
        }
        if (!is_array($values) || count($values) !== 2 || !is_string($values['usnc'] ?? null) || !is_string($values['usap'] ?? null)) {
            throw new \DomainException('Registry details are not supported for this ending.');
        }
        try { return new UsNexus($values['usnc'], $values['usap']); }
        catch (\InvalidArgumentException) { throw new \DomainException('Registry details are not supported for this ending.'); }
    }
}
