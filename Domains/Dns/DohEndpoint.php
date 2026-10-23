<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Operator-approved HTTPS identity plus public bootstrap; no unbounded endpoint DNS lookup. */
final readonly class DohEndpoint
{
    public const URL = 'https://cloudflare-dns.com/dns-query';
    public const IP = '1.1.1.1';
    public string $host;
    public function __construct(public string $url, public string $ip)
    {
        $p = parse_url($url);
        if (strlen($url) > 2048 || !is_array($p) || ($p['scheme'] ?? null) !== 'https' ||
            isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) ||
            (isset($p['port']) && $p['port'] !== 443) || preg_match('/[\x00-\x20\x7f\\\\]/', $url) ||
            !is_string($p['host'] ?? null) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            throw new \InvalidArgumentException('Use an HTTPS public host and a public bootstrap IP address.');
        }
        $this->host = DnsQuery::hostname($p['host']);
        if (preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]+)\z/iD', $this->host) || $this->host !== strtolower($p['host']) || preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test|example|onion)\z/iD', $this->host)) {
            throw new \InvalidArgumentException('Use a public resolver hostname.');
        }
    }
}
