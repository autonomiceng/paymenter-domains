<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Executed by the registered management-records regression; no manifest ownership change.

use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Ds;

expect(is_file(dirname(__DIR__, 2).'/Domains/Registrars/NameSilo/Ds.php'), 'DS inventory primitive exists');
$row = ['key_tag' => 12345, 'algorithm' => 13, 'digest_type' => 2, 'digest' => str_repeat('AB', 32)];
$body = static fn (mixed $rows, array $extra = []): string => json_encode([
    'request' => ['operation' => 'dnsSecListRecords'],
    'reply' => ['code' => 300, 'detail' => 'success', 'ds_record' => $rows] + $extra,
], JSON_THROW_ON_ERROR);
$read = static fn (string $wire): array => Ds::inventory($wire);
same($row, Ds::tuple($row), 'native tuple normalization');
same(['state' => 'complete', 'records' => [$row]], $read($body([$row])), 'documented tuple shape');
$text = ['key_tag' => '12345', 'algorithm' => '13', 'digest_type' => '2', 'digest' => strtolower($row['digest'])];
same($row, Ds::tuple($text), 'decimal strings and digest case normalize');
same(['state' => 'complete', 'records' => []], $read($body([])), 'explicit empty list complete');
foreach ([(object) [], (object) ['0' => $row], (object) $row, null, '', false] as $shape) {
    same('unknown', $read($body($shape))['state'], 'non-list inventory cannot prove completeness');
}
$invalid = [
    ['key_tag', -1], ['key_tag', 65536], ['key_tag', true], ['key_tag', 1.5], ['key_tag', '01'],
    ['algorithm', 0], ['algorithm', 256], ['algorithm', '1e1'], ['algorithm', null],
    ['digest_type', 0], ['digest_type', 255], ['digest_type', false],
    ['digest', str_repeat('G', 64)], ['digest', str_repeat('A', 63)], ['digest', str_repeat('A', 66)],
    ['digest', ' '.$row['digest']], ['digest', []],
];
foreach ($invalid as [$field, $value]) {
    $bad = array_replace($row, [$field => $value]);
    same('unknown', $read($body([$bad]))['state'], 'malformed-only tuple unknown: '.$field);
    same(['state' => 'partial', 'records' => [$row]], $read($body([$row, $bad])), 'valid row survives malformed sibling');
}
foreach (array_keys($row) as $field) {
    $bad = $row; unset($bad[$field]);
    same('unknown', $read($body([$bad]))['state'], 'missing field not complete');
}
same(0, Ds::tuple(array_replace($row, ['key_tag' => 0]))['key_tag'], 'zero key tag is valid');
foreach ([1 => 40, 2 => 64, 4 => 96] as $type => $length) {
    same('complete', $read($body([array_replace($row, ['digest_type' => $type, 'digest' => str_repeat('A', $length)])]))['state'], 'recognized digest format');
}
same('partial', $read($body([$row, $row]))['state'], 'duplicate normalized tuples not complete');
same('partial', $read($body([$row, $text]))['state'], 'case/string aliases are duplicate tuples');
same('partial', $read($body([$row + ['future' => 'field']]))['state'], 'extra row field not complete');
same('partial', $read($body([$row], ['more' => true]))['state'], 'unknown collection metadata not complete');
same('partial', $read($body(array_fill(0, 51, $row)))['state'], 'bounded inventory never silently truncates to complete');
foreach (['{"request":{"operation":"dnsSecListRecords"},"reply":{"code":300,"detail":"ok","ds_record":[],"ds_record":[]}}',
    '{"request":{"operation":"dnsSecListRecords"},"reply":{"code":300,"detail":"ok","ds_record":[],"ds_\\u0072ecord":[]}}',
    '{', str_repeat(' ', 1048577), str_replace('dnsSecListRecords', 'dnsSecAddRecord', $body([])),
    str_replace('300', '301', $body([])), str_replace('"detail":"success",', '', $body([])),
    str_replace('"ds_record":[]', '"ds_record":null', $body([]))] as $wire) {
    same('unknown', $read($wire)['state'], 'ambiguous or invalid envelope unknown');
}
echo "NameSilo DS inventory tests passed\n";
