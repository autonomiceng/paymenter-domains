<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Complete RRsets, positively proven absence, or unknown. TTL is diagnostic, never an identity. */
final readonly class DnsAnswer
{
    private function __construct(public DnsQuery $query, public string $state, public array $records, public string $code, public ?int $negativeTtl = null) {}
    public static function unknown(DnsQuery $query): self { return new self($query, 'unknown', [], 'read_unavailable'); }
    /** Only adapters with explicit complete negative evidence may use this. Native empty arrays cannot. */
    public static function negative(DnsQuery $query, int $ttl = 0, string $reason = 'nodata'): self
    {
        if ($ttl < 0 || $ttl > 2147483647 || !in_array($reason, ['nodata', 'nxdomain'], true)) { return self::unknown($query); }
        return new self($query, 'negative', [], $reason, min(300, $ttl));
    }
    /** Normalize PHP-style rows; malformed, unrelated or partial rows invalidate the entire answer. */
    public static function rrset(DnsQuery $query, array $rows): self
    {
        try {
            if (!$rows || !array_is_list($rows) || count($rows) > 100 || strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > 65536) { return self::unknown($query); }
            $records = [];
            foreach ($rows as $row) {
                if (!is_array($row) || ($row['type'] ?? null) !== $query->type || ($row['class'] ?? null) !== 'IN' ||
                    !is_string($row['host'] ?? null) || DnsQuery::hostname($row['host']) !== $query->name ||
                    !is_int($row['ttl'] ?? null) || $row['ttl'] < 0 || $row['ttl'] > 2147483647 ||
                    isset($row['truncated']) || isset($row['error'])) { return self::unknown($query); }
                $key = match ($query->type) { 'A' => 'ip', 'AAAA' => 'ipv6', default => 'target' };
                $value = $row[$key] ?? null;
                if (!is_string($value)) { return self::unknown($query); }
                if (in_array($query->type, ['A', 'AAAA'], true)) {
                    $flag = $query->type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
                    if (!filter_var($value, FILTER_VALIDATE_IP, $flag)) { return self::unknown($query); }
                    $value = inet_ntop(inet_pton($value));
                } else {
                    $value = $query->type === 'MX' && in_array($value, ['', '.'], true) ? '.' : DnsQuery::hostname($value);
                }
                $record = ['value' => $value, 'ttl' => $row['ttl']];
                if ($query->type === 'MX') {
                    if (!is_int($row['pri'] ?? null) || $row['pri'] < 0 || $row['pri'] > 65535 || ($value === '.' && $row['pri'] !== 0)) { return self::unknown($query); }
                    $record['priority'] = $row['pri'];
                }
                $records[] = $record; // Copy by value, severing references in injected arrays.
            }
            if ($query->type === 'CNAME' && count($records) !== 1) { return self::unknown($query); }
            if ($query->type === 'MX' && in_array('.', array_column($records, 'value'), true) && count($records) !== 1) { return self::unknown($query); }
            return new self($query, 'rrset', $records, 'records');
        } catch (\Throwable) { return self::unknown($query); }
    }
    public function known(): bool { return $this->state !== 'unknown'; }
    public function values(): array { return array_values(array_unique(array_column($this->records, 'value'))); }
    public function facts(): array { return ['name' => $this->query->name, 'type' => $this->query->type, 'state' => $this->state, 'records' => $this->records, 'code' => $this->code, 'negative_ttl' => $this->negativeTtl]; }
}
