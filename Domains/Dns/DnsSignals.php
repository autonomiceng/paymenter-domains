<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Pure classification. Missing evidence stays unknown; TTL/order never defines a routing change. */
final class DnsSignals
{
    public const CNAME_HOPS = 3;
    public static function delegation(string $name, RoutingProfile $profile, array $answers): DnsReading
    {
        $answer = self::answer($answers, $name, 'NS');
        if ($answer->state !== 'rrset') { return new DnsReading('delegation', queries: array_values($answers)); }
        $ns = $answer->values(); sort($ns, SORT_STRING);
        return new DnsReading('delegation', $ns === $profile->nameservers ? 'platform' : 'external', queries: array_values($answers));
    }
    public static function points(array $hosts, RoutingProfile $profile, array $answers): DnsReading
    {
        if (!$hosts) { throw new \InvalidArgumentException('Primary hostnames are required.'); }
        $website = self::combine(array_map(fn($host) => self::website($host, $profile->website, $answers), $hosts));
        // The registry/apex name is explicitly first; mapped host order never changes the MX identity.
        $email = self::email($hosts[0], $profile->email, $answers);
        return new DnsReading('points', website: $website[0], email: $email[0], mixed: $website[1] || $email[1],
            partial: $website[2] || $email[2], queries: array_values($answers));
    }
    /** @return array{0:?bool,1:bool,2:bool,3?:bool} value, mixed, incomplete, known foreign */
    private static function website(string $host, array $rules, array $answers): array
    {
        $seen = []; $hops = 0;
        while (true) {
            if (isset($seen[$host])) { return [null, false, true]; }
            $seen[$host] = true; $cname = self::answer($answers, $host, 'CNAME');
            if ($cname->state === 'rrset') {
                if (++$hops > self::CNAME_HOPS) { return [null, false, true]; }
                $host = $cname->values()[0];
                if (isset($seen[$host])) { return [null, false, true]; }
                if (RoutingProfile::hostMatches($host, $rules)) { return [true, false, false]; }
                continue;
            }
            $ip = self::addresses($host, $rules['ips'], $answers);
            // An A/AAAA positive can survive an unavailable CNAME read, but cannot claim completeness.
            if (!$cname->known()) { return [$ip[0] === true ? true : null, $ip[1], true, $ip[3]]; }
            return $ip;
        }
    }
    private static function addresses(string $host, array $ips, array $answers): array
    {
        $a = self::answer($answers, $host, 'A'); $aaaa = self::answer($answers, $host, 'AAAA');
        $values = array_merge($a->values(), $aaaa->values()); $positive = (bool) array_intersect($values, $ips);
        $foreign = (bool) array_diff($values, $ips); $partial = !$a->known() || !$aaaa->known();
        return [$positive ? true : ($partial ? null : false), $positive && $foreign, $partial, $foreign];
    }
    private static function email(string $name, array $rules, array $answers): array
    {
        $mx = self::answer($answers, $name, 'MX');
        if (!$mx->known()) { return [null, false, true]; }
        if ($mx->state === 'negative' || $mx->values() === ['.']) { return [false, false, false]; }
        $targets = [];
        foreach ($mx->values() as $host) {
            $targets[] = RoutingProfile::hostMatches($host, $rules) ? [true, false, false] :
                ($rules['ips'] ? self::addresses($host, $rules['ips'], $answers) : [false, false, false, true]);
        }
        return self::combine($targets);
    }
    private static function combine(array $rows): array
    {
        $positive = in_array(true, array_column($rows, 0), true); $negative = in_array(true, array_column($rows, 3), true);
        $partial = in_array(null, array_column($rows, 0), true) || in_array(true, array_column($rows, 2), true);
        return [$positive ? true : ($partial ? null : false), ($positive && $negative) || in_array(true, array_column($rows, 1), true), $partial, $negative];
    }
    public static function answer(array $answers, string $name, string $type): DnsAnswer
    {
        $query = new DnsQuery($name, $type); $answer = $answers[$query->key()] ?? null;
        return $answer instanceof DnsAnswer && $answer->query->key() === $query->key() ? $answer : DnsAnswer::unknown($query);
    }
}
