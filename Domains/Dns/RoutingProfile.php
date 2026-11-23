<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Canonical nameservers for a new registration. */
final readonly class RoutingProfile
{
    public array $nameservers;
    public function __construct(array $nameservers)
    {
        $this->nameservers = self::hosts($nameservers);
        if (!$this->nameservers || count($this->nameservers) > 13) { throw new \InvalidArgumentException('Default nameservers are required.'); }
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
}
