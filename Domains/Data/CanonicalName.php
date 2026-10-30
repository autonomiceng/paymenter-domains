<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

final class CanonicalName
{
    /** Syntax only; premium/registry eligibility remains a separate admission gate. */
    public static function normalize(#[\SensitiveParameter] string $input): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $input)) { throw new \InvalidArgumentException('Invalid domain input.'); }
        $name = strtolower(trim($input));
        if (str_ends_with($name, '.')) { $name = substr($name, 0, -1); }
        self::requireCanonical($name);
        return $name;
    }

    public static function requireCanonical(string $name): void
    {
        if (strlen($name) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $name)
            || preg_match('/(?:^|\.)[a-z0-9]{2}--/', $name)) {
            throw new \InvalidArgumentException('Canonical ordinary ASCII name required.');
        }
    }

    /** The longest explicitly supported suffix wins; a registrable SLD is one label. */
    public static function parse(#[\SensitiveParameter] string $input, array $allowedTlds): array
    {
        $name = self::normalize($input);
        $suffixes = [];
        foreach ($allowedTlds as $tld) {
            if (!is_string($tld) || !preg_match('/^[a-z]{2,63}(?:\.[a-z]{2,63})*$/D', $tld)) {
                throw new \InvalidArgumentException('Invalid supported domain ending.');
            }
            $suffixes[] = $tld;
        }
        usort($suffixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($suffixes as $tld) {
            if (!str_ends_with($name, '.'.$tld)) { continue; }
            $sld = substr($name, 0, -strlen($tld) - 1);
            if (str_contains($sld, '.')) { throw new \InvalidArgumentException('One registrable label required.'); }
            return ['domain' => $name, 'sld' => $sld, 'tld' => $tld];
        }
        throw new \InvalidArgumentException('This TLD is unavailable.');
    }
}
