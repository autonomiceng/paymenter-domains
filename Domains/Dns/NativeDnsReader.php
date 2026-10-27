<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
/** System recursive resolver; no constructor I/O, retries, connection to answer IPs or hard per-call timeout. */
final class NativeDnsReader implements DnsReader
{
    private \Closure $exchange;
    public function __construct(?callable $exchange = null)
    {
        $this->exchange = $exchange === null
            ? static fn(string $name, int $type): array|false => dns_get_record($name, $type)
            : \Closure::fromCallable($exchange);
    }
    public function read(DnsQuery $query, DnsContext $context): DnsAnswer
    {
        try {
            if (!$context->reserve()) { return DnsAnswer::unknown($query, $context->live() ? 'budget_exhausted' : 'deadline'); }
            $type = match ($query->type) { 'NS' => DNS_NS, 'A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX };
            $rows = @($this->exchange)($query->name.'.', $type);
            if (!$context->live()) { return DnsAnswer::unknown($query, 'deadline'); }
            if (!is_array($rows)) { return DnsAnswer::unknown($query, 'resolver_failed'); }
            // PHP ext/standard/dns.c filters by type: [] can hide a CNAME owner/target gap.
            // No RCODE/NODATA proof is exposed, so empty A/AAAA/MX (and other types) stay unknown.
            if (!$rows) { return DnsAnswer::unknown($query, 'ambiguous_empty'); }
            return DnsAnswer::rrset($query, $rows);
        } catch (\Throwable) { return DnsAnswer::unknown($query, 'resolver_failed'); }
    }
}
