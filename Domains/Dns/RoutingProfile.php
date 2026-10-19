<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Reviewed platform defaults and independent routing allowlists; no registrar inference. */
final readonly class RoutingProfile
{
    public array $nameservers;
    public array $website;
    public array $email;
    public function __construct(array $nameservers, array $website, array $email)
    {
        $this->nameservers = self::hosts($nameservers);
        if (!$this->nameservers || count($this->nameservers) > 13) { throw new \InvalidArgumentException('Default nameservers are required.'); }
        $this->website = self::rules($website, ['ips', 'exact', 'suffix']);
        $this->email = self::rules($email, ['ips', 'exact', 'suffix']);
    }
    private static function rules(array $rules, array $keys): array
    {
        if (array_diff(array_keys($rules), $keys)) { throw new \InvalidArgumentException('Unknown routing rule.'); }
        $out = [];
        foreach ($keys as $key) {
            $rows = $rules[$key] ?? [];
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) { throw new \InvalidArgumentException('Invalid routing rule list.'); }
            if ($key !== 'ips') { $out[$key] = self::hosts($rows); continue; }
            $ips = [];
            foreach ($rows as $ip) {
                if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) { throw new \InvalidArgumentException('Invalid routing address.'); }
                $ips[] = inet_ntop(inet_pton($ip));
            }
            $ips = array_values(array_unique($ips)); sort($ips, SORT_STRING); $out[$key] = $ips;
        }
        return $out;
    }
    private static function hosts(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) > 100) { throw new \InvalidArgumentException('Invalid routing host list.'); }
        $out = [];
        foreach ($rows as $host) {
            if (!is_string($host)) { throw new \InvalidArgumentException('Invalid routing host.'); }
            $out[] = DnsQuery::hostname($host);
        }
        $out = array_values(array_unique($out)); sort($out, SORT_STRING); return $out;
    }
    public static function hostMatches(string $host, array $rules): bool
    {
        if (in_array($host, $rules['exact'], true)) { return true; }
        foreach ($rules['suffix'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) { return true; }
        }
        return false;
    }
}
