<?php
// Synthetic DNS readback fixtures only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Dns;

expect(is_file(dirname(__DIR__).'/Domains/Registrars/NameSilo/Dns.php'), 'NameSilo DNS inventory adapter exists');
$body = static fn (array $reply, string $operation = 'dnsListRecords'): string => json_encode([
    'request' => ['operation' => $operation], 'reply' => array_replace(['code' => 300, 'detail' => 'synthetic'], $reply),
], JSON_THROW_ON_ERROR);
$row = ['record_id' => 'a1b2c3', 'type' => 'A', 'host' => 'EXAMPLE.COM.',
    'value' => '192.0.2.1', 'ttl' => '7207', 'distance' => '0'];
$expected = ['name' => '@', 'type' => 'A', 'address' => '192.0.2.1', 'mx_pref' => 10,
    'record_id' => 'a1b2c3', 'ttl' => 7207];
foreach ([$row, [$row]] as $rows) {
    same(['state' => 'complete', 'records' => [$expected]], Dns::inventory($body(['resource_record' => $rows]), 'example.com'),
        'Singleton and list normalize apex, record ID and explicit provider TTL');
}
$fixtures = [
    ['record_id' => 'v6', 'type' => 'AAAA', 'host' => 'www.example.com', 'value' => '2001:db8::1', 'ttl' => 300, 'distance' => 0],
    ['record_id' => 'alias', 'type' => 'CNAME', 'host' => '*.nested.example.com', 'value' => 'TARGET.EXAMPLE.COM', 'ttl' => 3600, 'distance' => 0],
    ['record_id' => 'mail', 'type' => 'MX', 'host' => 'example.com', 'value' => 'MAIL.EXAMPLE.COM.', 'ttl' => 600, 'distance' => 20],
    ['record_id' => 'txt', 'type' => 'TXT', 'host' => '_verify.example.com', 'value' => '  quoted "text" & https://example.com/?x=1  ', 'ttl' => 60, 'distance' => 0],
    ['record_id' => 'caa', 'type' => 'CAA', 'host' => 'example.com', 'value' => '0:issue:ca.example.com', 'ttl' => 7207, 'distance' => 0],
    ['record_id' => 'srv', 'type' => 'SRV', 'host' => '_sip._tcp.example.com', 'value' => '5:443:sip.example.com', 'ttl' => 900, 'distance' => 25],
];
$records = Dns::inventory($body(['resource_record' => $fixtures]), 'example.com');
same('complete', $records['state'], 'Documented record families form a complete inventory');
same('www', $records['records'][0]['name'], 'Relative owner name');
same('*.nested', $records['records'][1]['name'], 'Nested wildcard owner name');
same('target.example.com.', $records['records'][1]['address'], 'Shared target spelling has a root dot');
same(20, $records['records'][2]['mx_pref'], 'MX distance has proven preference meaning');
same($fixtures[3]['value'], $records['records'][3]['address'], 'TXT whitespace, quoting and URLs survive readback');
same('0:issue:ca.example.com', $records['records'][4]['address'], 'CAA value survives without an edit mapping');
same(25, $records['records'][5]['distance'], 'SRV distance is retained without interpreting priority');
same(5, $records['records'][5]['weight'], 'Documented SRV weight is parsed');
same(443, $records['records'][5]['port'], 'Documented SRV port is parsed');
same('sip.example.com.', $records['records'][5]['target'], 'Documented SRV target is normalized');
same(false, $records['records'][5]['priority_proven'], 'SRV priority is explicitly unproven');
expect(!isset($records['records'][5]['priority']), 'No invented SRV priority');
same(['state' => 'complete', 'records' => []], Dns::inventory($body(['resource_record' => []]), 'example.com'), 'Explicit empty list is complete');
foreach ([[], ['resource_record' => null], ['resource_record' => ''], ['resource_record' => ['bad']],
    ['resource_record' => $row, 'code' => 280], ['resource_record' => $row, 'code' => 301]] as $reply) {
    same(['state' => 'unknown', 'records' => []], Dns::inventory($body($reply), 'example.com'), 'Missing, malformed or unsuccessful inventory is unknown');
}
foreach (['{', $body(['resource_record' => $row], 'dnsAddRecord')] as $invalid) {
    same('unknown', Dns::inventory($invalid, 'example.com')['state'], 'Invalid JSON or wrong operation is unknown');
}
foreach (['record_id', 'ttl', 'distance', 'value', 'host', 'type'] as $field) {
    $bad = $row; unset($bad[$field]);
    $parsed = Dns::inventory($body(['resource_record' => [$row, $bad]]), 'example.com');
    same('partial', $parsed['state'], 'Missing row field never certifies complete');
    same([$expected], $parsed['records'], 'Valid rows survive a malformed sibling');
}
foreach (['record_id' => 'bad id', 'ttl' => '1e3', 'distance' => -1, 'value' => 'not-an-ip',
    'host' => 'example.com.evil.example', 'type' => 'INVENTED'] as $field => $value) {
    $bad = array_replace($row, [$field => $value]);
    same('partial', Dns::inventory($body(['resource_record' => [$row, $bad]]), 'example.com')['state'], 'Invalid or unproven row prevents complete inventory');
}
foreach (['more' => true, 'next_page' => '2', 'total' => 2, 'unexpected' => []] as $field => $value) {
    $parsed = Dns::inventory($body(['resource_record' => [$row], $field => $value]), 'example.com');
    same('partial', $parsed['state'], 'Unrecognized list metadata cannot silently prove completeness');
    same([$expected], $parsed['records'], 'Known rows retained with unknown list metadata');
}
same('partial', Dns::inventory($body(['resource_record' => [$row, $row]]), 'example.com')['state'], 'Duplicate provider IDs cannot authorize a delta');
same('partial', Dns::inventory($body(['resource_record' => $row + ['unproven' => 'field']]), 'example.com')['state'], 'Unknown row fields prevent a complete preimage');
same('unknown', Dns::inventory(str_repeat(' ', 1048577), 'example.com')['state'], 'Oversized response is bounded');
$duplicateList = '{"request":{"operation":"dnsListRecords"},"reply":{"code":300,"detail":"synthetic",'
    .'"resource_record":[],"resource_record":['.json_encode($row).']}}';
same('unknown', Dns::inventory($duplicateList, 'example.com')['state'], 'Duplicate JSON list keys cannot erase inventory');
$duplicateTtl = str_replace('"ttl":"7207"', '"ttl":"60","\\u0074tl":"7207"', $body(['resource_record' => $row]));
same('unknown', Dns::inventory($duplicateTtl, 'example.com')['state'], 'Escaped duplicate JSON row keys cannot replace TTL silently');
echo "NameSilo DNS inventory checks passed\n";
