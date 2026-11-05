<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DsMapping, Outcome, RegistrarResult};

/** Pure, dormant DS parsing. Recognized wire formats do not establish registry support. */
final class Ds
{
    /** SHA-1, SHA-256, SHA-384 wire lengths (RFCs 4034, 4509, 6605). Unknown formats refuse. */
    private const DIGEST_LENGTHS = [1 => 40, 2 => 64, 4 => 96];

    public static function tuple(#[\SensitiveParameter] array $row): array
    {
        if (array_diff(array_keys($row), ['key_tag', 'algorithm', 'digest_type', 'digest'])) {
            throw new \DomainException('Unexpected DS field.');
        }
        $keyTag = self::integer($row['key_tag'] ?? null, 0, 65535);
        $algorithm = self::integer($row['algorithm'] ?? null, 1, 255);
        $digestType = self::integer($row['digest_type'] ?? null, 1, 255);
        $digest = $row['digest'] ?? null;
        $length = self::DIGEST_LENGTHS[$digestType] ?? null;
        if (!is_string($digest) || $length === null || strlen($digest) !== $length || !preg_match('/^[a-fA-F0-9]+$/D', $digest)) {
            throw new \DomainException('Unrecognized DS digest format.');
        }
        return ['key_tag' => $keyTag, 'algorithm' => $algorithm, 'digest_type' => $digestType, 'digest' => strtoupper($digest)];
    }

    private static function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', (string) $value)
            || (int) $value < $min || (int) $value > $max) { throw new \DomainException('Invalid DS integer.'); }
        return (int) $value;
    }

    /** Shared tuple fields, server-side only. Only an explicit JSON list can be complete. */
    public static function inventory(#[\SensitiveParameter] string $body): array
    {
        $unknown = ['state' => 'unknown', 'records' => []];
        try {
            $reply = self::reply($body, 'dnsSecListRecords');
            $rows = $reply['ds_record'] ?? null;
            if (!is_array($rows)) { return $unknown; }
            $complete = !array_diff(array_keys($reply), ['code', 'detail', 'ds_record']) && count($rows) <= 50;
            $records = []; $seen = [];
            foreach (array_slice($rows, 0, 50) as $row) {
                try {
                    if (!$row instanceof \stdClass) { throw new \DomainException; }
                    $fields = (array) $row;
                    $extra = array_diff(array_keys($fields), ['key_tag', 'algorithm', 'digest_type', 'digest']);
                    $tuple = self::tuple(array_intersect_key($fields, array_flip(['key_tag', 'algorithm', 'digest_type', 'digest'])));
                    $key = json_encode($tuple, JSON_THROW_ON_ERROR);
                    if ($extra || isset($seen[$key])) { $complete = false; }
                    $seen[$key] = true;
                    $records[] = $tuple;
                } catch (\DomainException|\TypeError) { $complete = false; }
            }
            if ($rows && !$records) { return $unknown; }
            return ['state' => $complete ? 'complete' : 'partial', 'records' => $records];
        } catch (\Throwable) { return $unknown; }
    }

    /** Original command acknowledgement only. Success is never proof of current DS state. */
    public static function acknowledgement(#[\SensitiveParameter] string $body, DsMapping $mapping): RegistrarResult
    {
        try {
            if (!in_array($mapping->command, ['dnsSecAddRecord', 'dnsSecDeleteRecord'], true)) {
                return new RegistrarResult(Outcome::Unknown);
            }
            $reply = self::reply($body, $mapping->command);
            if (array_diff(array_keys($reply), ['code', 'detail'])) { return new RegistrarResult(Outcome::Unknown, 300); }
            return new RegistrarResult(Outcome::Success, 300);
        } catch (\Throwable) { return new RegistrarResult(Outcome::Unknown); }
    }

    /** Pure tuple delta only. Caller must bind both independent reads to the same account/domain and sequence. */
    public static function readback(DsMapping $mapping, #[\SensitiveParameter] string $beforeBody,
        #[\SensitiveParameter] string $afterBody): bool
    {
        if (!in_array($mapping->command, ['dnsSecAddRecord', 'dnsSecDeleteRecord'], true)) { return false; }
        $before = self::inventory($beforeBody); $after = self::inventory($afterBody);
        return self::confirm($mapping, $before, $after);
    }

    /** Decoded encrypted preimages require the same completeness and tuple validation as wire inventories. */
    public static function records(array $inventory): array
    {
        if (($inventory['state'] ?? null) !== 'complete' || !is_array($inventory['records'] ?? null)
            || !array_is_list($inventory['records']) || count($inventory['records']) > 50) {
            throw new \DomainException('Complete DS inventory required.');
        }
        $records = [];
        foreach ($inventory['records'] as $row) {
            if (!is_array($row)) { throw new \DomainException('Invalid DS inventory.'); }
            $tuple = self::tuple($row);
            $key = json_encode($tuple, JSON_THROW_ON_ERROR);
            if (isset($records[$key])) { throw new \DomainException('Unique DS tuples required.'); }
            $records[$key] = $tuple;
        }
        ksort($records);
        return array_values($records);
    }

    public static function confirm(DsMapping $mapping, array $before, array $after): bool
    {
        if (!in_array($mapping->command, ['dnsSecAddRecord', 'dnsSecDeleteRecord'], true)) { return false; }
        try { $records = self::records($before); $observed = self::records($after); }
        catch (\DomainException|\TypeError) { return false; }
        $parameters = $mapping->parameters();
        $target = ['key_tag' => $parameters['keyTag'], 'algorithm' => $parameters['alg'],
            'digest_type' => $parameters['digestType'], 'digest' => $parameters['digest']];
        $index = array_search($target, $records, true);
        if ($mapping->command === 'dnsSecDeleteRecord') {
            if ($index === false) { return false; }
            unset($records[$index]);
        } else {
            if ($index !== false) { return false; }
            $records[] = $target;
        }
        $expected = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), array_values($records));
        $observed = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $observed);
        sort($expected); sort($observed);
        return $expected === $observed;
    }

    /** Duplicate JSON keys must not erase rows or completeness metadata. Same guard as DNS primitives. */
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
        if (!$data instanceof \stdClass || !($data->request ?? null) instanceof \stdClass
            || ($data->request->operation ?? null) !== $operation || !($data->reply ?? null) instanceof \stdClass
            || array_diff(array_keys((array) $data), ['request', 'reply'])
            || array_diff(array_keys((array) $data->request), ['operation', 'ip'])
            || !in_array($data->reply->code ?? null, [300, '300'], true) || !is_string($data->reply->detail ?? null)) {
            throw new \DomainException;
        }
        return (array) $data->reply;
    }
}
