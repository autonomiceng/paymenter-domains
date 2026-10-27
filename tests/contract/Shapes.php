<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace OteContract;

/** Structural projection only: no scalar values, dynamic property names, IDs, IPs or messages. */
final class Shapes
{
    private const KEYS = 'request reply operation ip code detail message balance com net registration transfer renew available unavailable invalid domain price premium duration created expires status locked private auto_renew traffic_type email_verification_required portfolio forward_url forward_type nameservers nameserver position contact_ids registrant administrative technical billing order order_amount order_number order_date method total order_details description years_qty subtotal credited_date credited_amount domains pager page pageSize total_pages maxBid contact contact_id default_profile nickname company first_name last_name address address2 city state zip country email phone fax resource_record record_id type host value ttl distance';
    public static function strictDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) { return null; }
        try {
            new \Paymenter\Extensions\Servers\Domains\Data\DateEvidence($value,
                \Paymenter\Extensions\Servers\Domains\Data\ExpiryPrecision::Date, 'ote_contract_readback');
            return \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) ?: null;
        } catch (\Throwable) { return null; }
    }
    public static function shape(mixed $value, string $path = '$'): array
    {
        if (!is_array($value)) { return [$path => get_debug_type($value)]; }
        $result = [$path => array_is_list($value) ? 'list' : 'object'];
        foreach ($value as $key => $child) {
            $part = is_int($key) ? '[]' : (in_array($key, explode(' ', self::KEYS), true) ? $key : '[other-field]');
            foreach (self::shape($child, $path.'.'.$part) as $p => $type) {
                $types = array_unique(explode('|', ($result[$p] ?? $type).'|'.$type)); sort($types);
                $result[$p] = implode('|', $types);
            }
        }
        ksort($result); return $result;
    }
    private static function fixture(string $command): ?array
    {
        $fixtures = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/synthetic-success.json'), true, 24, JSON_THROW_ON_ERROR);
        if (isset($fixtures[$command])) { return $fixtures[$command]; }
        // Runner-local synthetic projections of api-contract.md for commands absent from the shared fixture.
        $reply = match ($command) {
            'listDomains' => ['domains' => [['domain' => 'example.com', 'created' => '2026-01-01', 'expires' => '2027-01-01', 'maxBid' => 0]], 'pager' => ['page' => 1, 'pageSize' => 10, 'total' => 1]],
            'contactAdd' => ['contact_id' => '101'],
            'contactList' => ['contact' => [array_fill_keys(explode(' ', 'contact_id default_profile nickname company first_name last_name address address2 city state zip country email phone fax'), 'synthetic')]],
            'dnsAddRecord' => ['record_id' => '101'],
            'dnsListRecords' => ['resource_record' => [['record_id' => '101', 'type' => 'TXT', 'host' => '_contract', 'value' => 'synthetic', 'ttl' => 3600, 'distance' => 0]]],
            'renewDomain' => ['domain' => 'example.com', 'order_amount' => '8.95'],
            'changeNameServers', 'domainLock', 'domainUnlock', 'removeAutoRenewal', 'dnsDeleteRecord' => [],
            default => null,
        };
        return $reply === null ? null : ['request' => ['operation' => $command, 'ip' => '192.0.2.1'], 'reply' => ['code' => 300, 'detail' => 'synthetic', 'message' => 'synthetic'] + $reply];
    }
    private static function financialAmount(mixed $value): bool
    {
        // Numeric wire types are structural only; production validates their exact JSON lexemes.
        return is_int($value) || is_float($value) || (is_string($value)
            && preg_match('/^(?:[0-9]+(?:\.[0-9]{1,2})?|[1-9][0-9]{0,2}(?:,[0-9]{3})+\.[0-9]{2})$/D', $value) === 1);
    }
    private static function positiveInteger(mixed $value): bool
    { return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1; }
    /** Observed collection variants only; preserve every row and unknown field for validation. */
    public static function normalizeCollections(string $command, array $data): array
    {
        $r = $data['reply'] ?? [];
        if ($command === 'listDomains' && array_key_exists('domains', $r) && $r['domains'] === null
            && in_array($r['code'] ?? null, [300, '300'], true) && is_string($r['detail'] ?? null)
            && is_string($data['request']['ip'] ?? null) && is_array($r['pager'] ?? null)
            && in_array($r['pager']['total'] ?? null, [0, '0'], true)
            && self::positiveInteger($r['pager']['page'] ?? null) && self::positiveInteger($r['pager']['pageSize'] ?? null)) {
            $data['reply']['domains'] = [];
        }
        $field = match ($command) { 'dnsListRecords' => 'resource_record', 'listDomains' => 'domains', default => null };
        if ($field === null || !is_array($data['reply'][$field] ?? null)) { return $data; }
        $rows = $data['reply'][$field];
        if ($command === 'listDomains' && array_keys($rows) === ['domain']
            && is_array($rows['domain']) && !array_is_list($rows['domain'])) { $rows = $rows['domain']; }
        $data['reply'][$field] = array_is_list($rows) ? $rows : [$rows];
        return $data;
    }
    public static function check(string $command, mixed $data, array $requested = []): array
    {
        $errors = [];
        if (!is_array($data) || ($data['request']['operation'] ?? null) !== $command || !is_array($data['reply'] ?? null)) {
            return ['envelope.operation'];
        }
        $data = self::normalizeCollections($command, $data);
        $r = $data['reply'];
        if ($command === 'checkRegisterAvailability' && is_array($r['available'] ?? null)) {
            if (count($requested) === 1 && array_keys($r['available']) === ['domain']
                && is_array($r['available']['domain']) && !array_is_list($r['available']['domain'])) {
                $r['available'] = [$r['available']['domain']];
            }
            // Explicitly ignored optional OTE metadata, matching production projection.
            if (array_is_list($r['available'])) {
                foreach ($r['available'] as &$row) { if (is_array($row)) { unset($row['renew']); } } unset($row);
            } else { unset($r['available']['renew']); }
            $data['reply']['available'] = $r['available'];
        }
        if ($command === 'contactList' && is_array($r['contact'] ?? null) && !array_is_list($r['contact'])) {
            // Targeted OTE readback uses one object; inventory uses a list. Preserve every field.
            $r['contact'] = [$r['contact']]; $data['reply']['contact'] = $r['contact'];
        }
        if (!is_string($data['request']['ip'] ?? null)) { $errors[] = 'request.ip'; }
        if (!is_string($r['detail'] ?? null)) { $errors[] = 'reply.detail'; }
        $codes = match ($command) {
            'registerDomain' => [300, 301, 302], 'removeAutoRenewal' => [300, 250, 251],
            'domainLock' => [300, 252], 'domainUnlock' => [300, 253], default => [300],
        };
        if (!in_array($r['code'] ?? null, [...$codes, ...array_map('strval', $codes)], true)) {
            return [...$errors, 'reply.code_not_success'];
        }
        $required = match ($command) {
            'getAccountBalance' => ['balance'], 'getPrices' => ['com', 'net'],
            'checkRegisterAvailability' => [], 'listDomains' => ['domains', 'pager'], 'listOrders' => ['order'],
            'orderDetails' => ['order_number', 'order_date', 'method', 'total', 'order_details'],
            'getDomainInfo' => explode(' ', 'created expires status locked private auto_renew traffic_type email_verification_required portfolio forward_url forward_type nameservers contact_ids'),
            'registerDomain', 'renewDomain' => ['message', 'domain', 'order_amount'], 'contactAdd' => ['contact_id'], 'contactList' => ['contact'],
            'dnsAddRecord' => ['record_id'], 'dnsListRecords' => ['resource_record'], default => [],
        };
        foreach ($required as $field) { if (!array_key_exists($field, $r)) { $errors[] = 'reply.'.$field.'.missing'; } }
        foreach (['locked', 'private', 'auto_renew'] as $field) {
            if ($command === 'getDomainInfo' && !in_array($r[$field] ?? null, ['Yes', 'No'], true)) { $errors[] = 'reply.'.$field.'.flag'; }
        }
        if ($command === 'getDomainInfo') {
            foreach (['created', 'expires'] as $f) {
                if (self::strictDate($r[$f] ?? null) === null) { $errors[] = 'reply.'.$f.'.date'; }
            }
        }
        foreach (['order', 'order_details', 'domains', 'nameservers', 'contact_ids', 'resource_record', 'contact', 'pager'] as $f) {
            if (array_key_exists($f, $r) && !is_array($r[$f])) { $errors[] = 'reply.'.$f.'.collection'; }
        }
        if ($command === 'getPrices') {
            foreach (array_intersect(['com', 'net'], array_keys($r)) as $tld) {
                foreach (['registration', 'transfer', 'renew'] as $action) {
                    $amount = $r[$tld][$action] ?? null;
                    if (!self::financialAmount($amount) || preg_match('/[1-9]/', (string) $amount) !== 1) {
                        $errors[] = 'reply.'.$tld.'.'.$action.'.amount';
                    }
                }
            }
        }
        $records = match ($command) {
            'listDomains' => ['domains' => ['domain', 'created', 'expires']],
            'listOrders' => ['order' => ['order_number', 'order_date', 'method', 'total']],
            'orderDetails' => ['order_details' => ['description', 'years_qty', 'price', 'subtotal', 'status']],
            'getDomainInfo' => ['nameservers' => ['nameserver', 'position']],
            'contactList' => ['contact' => explode(' ', 'contact_id default_profile nickname company first_name last_name address address2 city state zip country email phone fax')],
            'dnsListRecords' => ['resource_record' => ['record_id', 'type', 'host', 'value', 'ttl', 'distance']],
            'checkRegisterAvailability' => ['available' => ['domain', 'price', 'premium', 'duration'], 'unavailable' => ['domain'], 'invalid' => ['domain']],
            default => [],
        };
        foreach ($records as $collection => $fields) {
            if (!is_array($r[$collection] ?? null)) { continue; }
            $rows = array_is_list($r[$collection]) ? $r[$collection] : [$r[$collection]];
            foreach ($rows as $row) {
                if (!is_array($row)) { $errors[] = 'reply.'.$collection.'.row_not_object'; continue; }
                foreach ($fields as $f) { if (!array_key_exists($f, $row)) { $errors[] = 'reply.'.$collection.'.[].'.$f.'.missing'; } }
                if ($command === 'listDomains' && (!is_string($row['domain'] ?? null) || $row['domain'] === ''
                    || self::strictDate($row['created'] ?? null) === null || self::strictDate($row['expires'] ?? null) === null)) {
                    $errors[] = 'reply.domains.[].invalid';
                }
            }
        }
        foreach (match ($command) { 'listDomains' => ['pager' => ['page', 'pageSize', 'total']],
            'getDomainInfo' => ['contact_ids' => ['registrant', 'administrative', 'technical', 'billing']], default => [] } as $object => $fields) {
            foreach ($fields as $f) { if (!is_array($r[$object] ?? null) || !array_key_exists($f, $r[$object])) { $errors[] = 'reply.'.$object.'.'.$f.'.missing'; } }
        }
        $fixture = self::fixture($command);
        if ($fixture) {
            // Docs allow numeric strings and JSON numbers; preserve both in the shape receipt.
            // Empty and singleton collections are recorded as variants for follow-up acceptance.
            if ($command === 'getPrices') {
                $fixture['reply']['net'] = $fixture['reply']['com'];
                $data['reply'] = array_intersect_key($data['reply'], array_flip(['code', 'detail', 'com', 'net']));
            }
            $expected = self::shape($fixture);
            foreach (self::shape($data) as $path => $type) {
                if (!isset($expected[$path])) {
                    $errors[] = 'undocumented_field.'.$path;
                    continue;
                }
                $wanted = $expected[$path];
                $numericFields = ['code', 'balance', 'registration', 'transfer', 'renew', 'price', 'order_amount', 'total', 'subtotal', 'credited_amount', 'premium', 'duration', 'position', 'years_qty', 'page', 'pageSize', 'maxBid', 'ttl', 'distance'];
                $numeric = static fn ($t) => !array_diff(explode('|', $t), ['int', 'float', 'string']);
                $field = substr($path, strrpos($path, '.') + 1);
                $compatible = in_array($field, $numericFields, true) && $numeric($wanted) && $numeric($type);
                if (in_array($field, ['order_number', 'contact_id', 'default_profile', 'registrant', 'administrative', 'technical', 'billing', 'record_id'], true)) {
                    $compatible = in_array($wanted, ['int', 'string'], true) && in_array($type, ['int', 'string'], true);
                }
                if ($wanted !== $type && !$compatible) { $errors[] = 'fixture_type.'.$path; }
            }
        }
        return array_values(array_unique($errors));
    }
}
