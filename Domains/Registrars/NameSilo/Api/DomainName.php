<?php
// Adapted from Enom Api/DomainName.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;

final class DomainName
{
    /** Syntax only: callers must separately prove ordinary/non-premium eligibility and ownership. */
    public static function parse(#[\SensitiveParameter] string $input, array $allowedTlds = ['com', 'net', 'org', 'us']): array
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $input)) {
            throw new \InvalidArgumentException('Invalid domain input.');
        }
        $domain = strtolower(trim($input));
        if ($domain === '' || strlen($domain) > 253 || preg_match('/[^a-z0-9.-]/D', $domain)) {
            throw new \InvalidArgumentException('Enter a supported ASCII domain name.');
        }
        usort($allowedTlds, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($allowedTlds as $tld) {
            if (!preg_match('/^[a-z]{2,63}(?:\.[a-z]{2,63})*$/D', $tld) || !str_ends_with($domain, '.'.$tld)) {
                continue;
            }
            $label = substr($domain, 0, -strlen($tld) - 1);
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) || substr($label, 2, 2) === '--') {
                throw new \InvalidArgumentException('Invalid ordinary domain label.');
            }
            return ['domain' => $domain, 'sld' => $label, 'tld' => $tld];
        }
        throw new \InvalidArgumentException('This domain ending is unavailable.');
    }
}
