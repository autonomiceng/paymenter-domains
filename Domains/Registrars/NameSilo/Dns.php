<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DnsMapping, Outcome, RegistrarResult};

/** Pure, dormant DNS mapping. No discovery caller, transport, grant or edit capability. */
final class Dns
{
    public const STANDARD = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

    /** Shared name/type/address/mx_pref shape, retaining provider record_id and ttl. Server-side only. */
    public static function inventory(#[\SensitiveParameter] string $body, string $domain): array
    {
        $unknown = ['state' => 'unknown', 'records' => []];
        try {
            $domain = CanonicalName::normalize($domain);
            $reply = self::reply($body, 'dnsListRecords');
            $rows = $reply['resource_record'] ?? null;
            if ($rows instanceof \stdClass) {
                $fields = (array) $rows;
                if (!$fields) { return $unknown; }
                foreach (array_keys($fields) as $field) { if (is_numeric((string) $field)) { return $unknown; } }
                $rows = [$rows];
            } elseif (!is_array($rows)) { return $unknown; }
            $complete = !array_diff(array_keys($reply), ['code', 'detail', 'resource_record']);
            if (count($rows) > 50) { $complete = false; }
            $records = []; $seen = [];
            foreach (array_slice($rows, 0, 50) as $row) {
                try {
                    if (!$row instanceof \stdClass) { throw new \DomainException; }
                    $row = (array) $row;
                    $record = self::readRecord($row, $domain);
                    if (isset($seen[$record['record_id']]) || array_diff(array_keys($row),
                        ['record_id', 'type', 'host', 'value', 'ttl', 'distance'])) { $complete = false; }
                    $seen[$record['record_id']] = true;
                    $records[] = $record;
                } catch (\DomainException|\TypeError) { $complete = false; }
            }
            if ($rows && !$records) { return $unknown; }
            return ['state' => $complete ? 'complete' : 'partial', 'records' => $records];
        } catch (\Throwable) { return $unknown; }
    }

    /** Original response acknowledgement only. It is never confirmation of current DNS state. */
    public static function acknowledgement(#[\SensitiveParameter] string $body, DnsMapping $mapping): RegistrarResult
    {
        try {
            if (!in_array($mapping->command, ['dnsAddRecord', 'dnsUpdateRecord', 'dnsDeleteRecord'], true)) {
                return new RegistrarResult(Outcome::Unknown);
            }
            $reply = self::reply($body, $mapping->command);
            $delete = $mapping->command === 'dnsDeleteRecord';
            if (array_diff(array_keys($reply), $delete ? ['code', 'detail'] : ['code', 'detail', 'record_id'])) {
                return new RegistrarResult(Outcome::Unknown, 300);
            }
            $facts = [];
            if (!$delete) {
                $id = self::id($reply['record_id'] ?? null);
                if ($mapping->command === 'dnsUpdateRecord' && $id === $mapping->parameters()['rrid']) {
                    return new RegistrarResult(Outcome::Unknown, 300);
                }
                $facts['record_id'] = $id;
            }
            return new RegistrarResult(Outcome::Success, 300, $facts);
        } catch (\Throwable) { return new RegistrarResult(Outcome::Unknown); }
    }

    private static function readRecord(#[\SensitiveParameter] array $row, string $domain): array
    {
        $id = self::id($row['record_id'] ?? null);
        $ttl = self::integer($row['ttl'] ?? null, 2147483647);
        $distance = self::integer($row['distance'] ?? null, 65535);
        $host = $row['host'] ?? null;
        if (!is_string($host)) { throw new \DomainException; }
        $absolute = strtolower(self::withoutRootDot($host));
        if ($absolute !== $domain && !str_ends_with($absolute, '.'.$domain)) { throw new \DomainException; }
        $name = self::host($domain, $host);
        $type = $row['type'] ?? null;
        if (!is_string($type) || !in_array($type, [...self::STANDARD, 'CAA', 'SRV'], true)) { throw new \DomainException; }
        $address = self::address($type, $row['value'] ?? null);
        $record = ['name' => $name === '' ? '@' : $name, 'type' => $type, 'address' => $address,
            'mx_pref' => $type === 'MX' ? $distance : 10, 'record_id' => $id, 'ttl' => $ttl];
        if ($type === 'SRV') {
            $parts = explode(':', $address);
            if (count($parts) !== 3) { throw new \DomainException; }
            $record += ['distance' => $distance, 'priority_proven' => false,
                'weight' => self::integer($parts[0], 65535), 'port' => self::integer($parts[1], 65535),
                'target' => $parts[2] === '.' ? '.' : self::address('CNAME', $parts[2])];
        }
        return $record;
    }

    /** Shared relative owners and in-zone absolute owners map to provider-relative rrhost; apex is empty. */
    public static function host(string $domain, #[\SensitiveParameter] string $name): string
    {
        $domain = CanonicalName::normalize($domain);
        if ($name === '@' || $name === '') { return ''; }
        $rooted = str_ends_with($name, '.');
        $name = strtolower(self::withoutRootDot($name));
        if ($name === $domain) { return ''; }
        if (str_ends_with($name, '.'.$domain)) { $name = substr($name, 0, -strlen('.'.$domain)); }
        elseif ($rooted) { throw new \DomainException('DNS owner is outside the domain.'); }
        if (strlen($name.'.'.$domain) > 253) { throw new \DomainException('DNS owner is too long.'); }
        foreach (explode('.', $name) as $i => $label) {
            if ($i === 0 && $label === '*') { continue; }
            if (!preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/D', $label)) {
                throw new \DomainException('Invalid DNS owner.');
            }
        }
        return $name;
    }

    public static function id(#[\SensitiveParameter] mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9]{1,128}$/D', $id)) { throw new \DomainException('Invalid DNS record ID.'); }
        return $id;
    }

    public static function integer(mixed $value, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string) $value)
            || (int) $value > $max) { throw new \DomainException('Invalid DNS integer.'); }
        return (int) $value;
    }

    public static function address(string $type, #[\SensitiveParameter] mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \DomainException('Invalid DNS value.');
        }
        if ($type === 'TXT') { return $value; }
        if (in_array($type, ['A', 'AAAA'], true)) {
            $flag = $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
            if (!filter_var($value, FILTER_VALIDATE_IP, $flag)) { throw new \DomainException('Invalid DNS address.'); }
            return inet_ntop(inet_pton($value));
        }
        if (in_array($type, ['CNAME', 'MX'], true)) {
            $target = strtolower(self::withoutRootDot($value));
            if (strlen($target) > 253 || !str_contains($target, '.') || !filter_var($target, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new \DomainException('Invalid DNS target.');
            }
            return $target.'.';
        }
        if ($value === '') { throw new \DomainException('Missing DNS value.'); }
        return $value; // Preserve CAA/SRV wire text without inventing an edit mapping.
    }

    private static function withoutRootDot(string $name): string
    { return str_ends_with($name, '.') ? substr($name, 0, -1) : $name; }

    /** JSON object duplicates would otherwise silently erase records, IDs or completeness metadata. */
    private static function uniqueKeys(#[\SensitiveParameter] string $body): void
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]]/s', $body, $tokens, PREG_OFFSET_CAPTURE);
        $stack = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if ($token === '{' || $token === '[') { $stack[] = []; }
            elseif ($token === '}' || $token === ']') { array_pop($stack); }
            elseif ($stack && preg_match('/\G\s*:/', $body, $unused, 0, $offset + strlen($token))) {
                $key = json_decode($token, true, 24, JSON_THROW_ON_ERROR);
                $top = count($stack) - 1;
                if (isset($stack[$top][$key])) { throw new \DomainException; }
                $stack[$top][$key] = true;
            }
        }
    }

    private static function reply(#[\SensitiveParameter] string $body, string $operation): array
    {
        if (strlen($body) > 1048576) { throw new \DomainException; }
        self::uniqueKeys($body);
        $data = json_decode($body, false, 24, JSON_THROW_ON_ERROR);
        if (!is_object($data) || !is_object($data->request ?? null) || ($data->request->operation ?? null) !== $operation
            || !is_object($data->reply ?? null) || !in_array($data->reply->code ?? null, [300, '300'], true)
            || !is_string($data->reply->detail ?? null)) { throw new \DomainException; }
        return (array) $data->reply;
    }
}
