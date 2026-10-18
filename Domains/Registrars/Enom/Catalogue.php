<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, Capability, PrivacyMode, Support};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;

final class Catalogue
{
    public static function tld(string $tld): void
    {
        if (!preg_match('/^[a-z]{2,63}(?:\.[a-z]{2,63})*$/D', $tld) || strlen($tld) > 189) { throw new \InvalidArgumentException('Invalid canonical ending.'); }
    }
    public static function names(ProtocolResult $reply): ?array
    {
        if ($reply->status !== 'success' || !is_array($list = $reply->value('tldlist'))) { return null; }
        $names = [];
        $walk = function (array $node) use (&$walk, &$names): void {
            foreach ($node as $key => $value) {
                if ($key === 'tld' && is_string($value) && $value !== '') {
                    self::tld(strtolower($value)); $names[strtolower($value)] = true;
                } elseif ($key === 'tld' && is_array($value) && array_is_list($value)) {
                    foreach ($value as $item) {
                        if (is_string($item)) { self::tld(strtolower($item)); $names[strtolower($item)] = true; }
                        elseif (is_array($item)) { $walk($item); }
                        else { throw new \InvalidArgumentException('Invalid ending.'); }
                    }
                } elseif (is_array($value)) { $walk($value); }
                elseif ($key === 'tld' && $value !== '') { throw new \InvalidArgumentException('Invalid ending.'); }
            }
        };
        try { $walk($list); } catch (\InvalidArgumentException) { return null; }
        return $names ? array_keys($names) : null;
    }
    public static function details(ProtocolResult $reply, string $tld, AccountBinding $binding): ?array
    {
        $row = $reply->value('tlds.tld');
        if ($reply->status !== 'success' || !is_array($row) || ($row['tld'] ?? null) !== $tld) { return null; }
        $minimum = $row['registration']['minimum'] ?? null; $maximum = $row['registration']['maximum'] ?? null;
        $terms = is_string($minimum) && is_string($maximum) && preg_match('/^[1-9][0-9]?$/D', $minimum)
            && preg_match('/^[1-9][0-9]?$/D', $maximum) && (int) $minimum <= (int) $maximum && (int) $maximum <= 10
            && is_string($row['registration']['unit'] ?? null) && strtolower($row['registration']['unit']) === 'year' ? range((int) $minimum, (int) $maximum) : [];
        $capability = static fn (?bool $flag) => new Capability(match ($flag) { true => Support::Supported, false => Support::Unsupported, null => Support::Unknown });
        $privacy = Driver::flag($row['allowwpps'] ?? null);
        return ['tld' => $tld, 'capabilities' => new Capabilities($binding, [
            'register' => new Capability($terms ? Support::Supported : Support::Unknown, $terms),
            'lock' => $capability(Driver::flag($row['abletolock'] ?? null)),
            'privacy' => $capability($privacy), 'ds' => $capability(Driver::flag($row['supportsdnssec'] ?? null)),
        ], match ($privacy) { true => PrivacyMode::Paid, false => PrivacyMode::Unavailable, null => PrivacyMode::Unknown }),
            'requires_nameservers' => Driver::flag($row['registration']['dnsrequired'] ?? null),
            'registry_attributes' => Driver::flag($row['extattributes'] ?? null),
            'auto_renew_only' => Driver::flag($row['renewal']['autorenewonly'] ?? null),
            'restorable' => Driver::flag($row['renewal']['restorable'] ?? null)];
    }
}
