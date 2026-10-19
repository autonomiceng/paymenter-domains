<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, DomainSnapshot};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;

/** Shared exact name, UID and provider-ID proof. No transport or persistence. */
final class Identity
{
    public static function read(AccountBinding $binding, string $name, array $parts, ProtocolResult $reply): ?DomainSnapshot
    {
        if ($reply->status !== 'success') { return null; }
        $info = $reply->value('getdomaininfo');
        $domain = is_array($info) ? ($info['domainname'] ?? null) : null;
        $label = is_array($domain) ? ($domain['#text'] ?? null) : $domain;
        $attributes = is_array($domain) ? ($domain['@attributes'] ?? []) : [];
        $status = is_array($info) ? ($info['status'] ?? null) : null;
        if (!is_array($status) || !is_string($label) || strtolower($label) !== $name || !is_array($attributes)
            || (isset($attributes['sld']) && (!is_string($attributes['sld']) || strtolower($attributes['sld']) !== $parts['SLD']))
            || (isset($attributes['tld']) && (!is_string($attributes['tld']) || strtolower($attributes['tld']) !== $parts['TLD']))) { return null; }
        $owner = $status['belongs-to'] ?? null;
        if (is_array($owner)) { $owner = $owner['#text'] ?? null; }
        $id = $attributes['domainnameid'] ?? $info['domainnameid'] ?? null;
        if ((isset($attributes['domainnameid'], $info['domainnameid']) && $attributes['domainnameid'] !== $info['domainnameid'])
            || !is_string($owner) || !is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) { return null; }
        try { $owned = $binding->matches(new AccountBinding('enom', $binding->serverId, $binding->environment, $owner)); }
        catch (\InvalidArgumentException) { return null; }
        if (!$owned) { return new DomainSnapshot($binding, $name, false); }
        return new DomainSnapshot($binding, $name, true, Expiry::read($reply), providerReference: $id);
    }
}
