<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, ReviewedFacts};

/** Deployment-only reviewed facts. No customer allowlist or default registry grants. */
final class DsRegistryPolicy
{
    public function __construct(private readonly array $map = [], private readonly ?\DateTimeImmutable $now = null) {}

    public function snapshot(string $domain): ?array
    {
        try {
            $entries = ReviewedFacts::entries($this->map);
            $domain = CanonicalName::normalize($domain);
            // Select BEFORE filtering facts: an invalid/expired longer suffix must shadow broader facts.
            if (array_key_exists($domain, $entries)) { return null; }
            $name = CanonicalName::parse($domain, array_keys($entries));
            $fact = DsFacts::read($this->map, $this->now)[$name['tld']] ?? null;
            return $fact && $fact['input'] === 'ds' ? ['format'=>1, ...$fact] : null;
        } catch (\Throwable) { return null; }
    }

    public function allows(string $domain, #[\SensitiveParameter] array $record): bool
    {
        try {
            $fact = $this->snapshot($domain);
            $tuple = Ds::tuple($record);
            if ($fact === null) { return false; }
            foreach ($fact['combinations'] as $pair) {
                if ($pair['algorithm'] === $tuple['algorithm'] && $pair['digest_type'] === $tuple['digest_type']) { return true; }
            }
        } catch (\Throwable) {}
        return false;
    }
}
