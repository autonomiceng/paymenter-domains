<?php
// Synthetic DNS wire fixtures only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DnsMapping, RegistrarRequest};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Dns;

expect(is_file(dirname(__DIR__).'/Domains/Registrars/NameSilo/Api/DnsMapping.php'), 'Dormant DNS wire builders exist');
$reject = static function (callable $call): void {
    try { $call(); } catch (DomainException|TypeError) { return; }
    throw new RuntimeException('Expected invalid DNS mapping refusal');
};
$record = ['name' => '@', 'type' => 'A', 'address' => '192.0.2.1', 'mx_pref' => 10, 'ttl' => 7207];
$list = DnsMapping::listRecords('EXAMPLE.COM');
same('dnsListRecords', $list->command, 'Exact inventory command');
same(['domain' => 'example.com'], $list->parameters(), 'Inventory is scoped to one canonical domain');
$add = DnsMapping::add('example.com', $record);
same('dnsAddRecord', $add->command, 'Exact create command');
same(['domain' => 'example.com', 'rrtype' => 'A', 'rrhost' => '', 'rrvalue' => '192.0.2.1', 'rrttl' => 7207],
    $add->parameters(), 'Apex uses empty rrhost and explicit TTL; no MX distance on A');
$before = $record + ['record_id' => 'oldA1'];
$after = array_replace($record, ['name' => 'www.example.com.', 'address' => '192.0.2.2', 'ttl' => 300]);
$update = DnsMapping::update('example.com', $before, $after);
same('dnsUpdateRecord', $update->command, 'Exact update command');
same(['domain' => 'example.com', 'rrid' => 'oldA1', 'rrhost' => 'www', 'rrvalue' => '192.0.2.2', 'rrttl' => 300],
    $update->parameters(), 'Update uses old ID and never sends undocumented rrtype');
$delete = DnsMapping::delete('example.com', 'oldA1');
same('dnsDeleteRecord', $delete->command, 'Exact delete command');
same(['domain' => 'example.com', 'rrid' => 'oldA1'], $delete->parameters(), 'Delete addresses only the observed record ID');
// Provider absolute owners lose the zone suffix exactly once before builders consume relative names.
foreach ([['example.com.example.com', 'example.com'], ['www.example.com.example.com', 'www.example.com'],
    ['example.com.example.com.', 'example.com'], ['www.example.com.example.com.', 'www.example.com'],
    ['example.com', '@'], ['EXAMPLE.COM.', '@'], ['www.example.com', 'www'], ['www.example.com.', 'www'],
    ['nested.www.example.com', 'nested.www']] as [$providerOwner, $relative]) {
    $body = json_encode(['request' => ['operation' => 'dnsListRecords'], 'reply' => ['code' => 300, 'detail' => 'synthetic',
        'resource_record' => [['record_id' => 'roundtrip', 'type' => 'A', 'host' => $providerOwner,
            'value' => '192.0.2.1', 'ttl' => 300, 'distance' => 0]]]], JSON_THROW_ON_ERROR);
    $inventory = Dns::inventory($body, 'example.com');
    same('complete', $inventory['state'], 'Valid absolute provider owner is complete');
    $observed = $inventory['records'][0];
    same($relative, $observed['name'], 'Provider owner becomes the intended shared relative name');
    $wireOwner = $relative === '@' ? '' : $relative;
    same($wireOwner, DnsMapping::add('example.com', $observed)->parameters()['rrhost'],
        'Parsed relative owner survives the add builder without stripping its suffix again');
    same($wireOwner, DnsMapping::update('example.com', $observed, $observed)->parameters()['rrhost'],
        'Parsed relative owner survives the update builder without moving the record');
}
foreach ([['@', ''], ['', ''], ['www', 'www'], ['nested.www', 'nested.www'], ['example.com', 'example.com'],
    ['www.example.com', 'www.example.com'], ['example.com.', ''], ['www.example.com.', 'www']] as [$owner, $wireOwner]) {
    $r = array_replace($record, ['name' => $owner]);
    same($wireOwner, DnsMapping::add('example.com', $r)->parameters()['rrhost'],
        'Unrooted shared owners are relative; a root dot explicitly selects an absolute owner');
    same($wireOwner, DnsMapping::update('example.com', $r + ['record_id' => 'owner'], $r)->parameters()['rrhost'],
        'Add and update interpret shared owner names consistently');
}
$cases = [
    ['AAAA', 'v6', '2001:db8::1', 10, ['rrhost' => 'v6', 'rrvalue' => '2001:db8::1']],
    ['CNAME', '*.nested', 'TARGET.EXAMPLE.COM.', 10, ['rrhost' => '*.nested', 'rrvalue' => 'target.example.com.']],
    ['MX', 'example.com.', 'MAIL.EXAMPLE.COM', 0, ['rrhost' => '', 'rrvalue' => 'mail.example.com.', 'rrdistance' => 0]],
    ['TXT', '_verify.nested', '  "text" & https://example.com/?x=1  ', 10, ['rrhost' => '_verify.nested', 'rrvalue' => '  "text" & https://example.com/?x=1  ']],
];
foreach ($cases as [$type, $name, $address, $preference, $expected]) {
    $r = array_replace($record, ['type' => $type, 'name' => $name, 'address' => $address, 'mx_pref' => $preference]);
    $params = ['domain' => 'example.com', 'rrtype' => $type] + $expected + ['rrttl' => 7207];
    $actual = DnsMapping::add('example.com', $r)->parameters(); ksort($params); ksort($actual);
    same($params, $actual, 'Documented '.$type.' create mapping');
    $old = $r + ['record_id' => 'recordA1'];
    $actual = DnsMapping::update('example.com', $old, $r)->parameters();
    unset($params['rrtype']); $params['rrid'] = 'recordA1'; ksort($params); ksort($actual);
    same($params, $actual, 'Documented '.$type.' update preserves explicit TTL and TXT bytes');
}
foreach (['CAA', 'SRV', 'URL', 'FRAME', 'MXE'] as $type) {
    $r = array_replace($record, ['type' => $type]);
    $reject(fn () => DnsMapping::add('example.com', $r));
    $reject(fn () => DnsMapping::update('example.com', $r + ['record_id' => 'oldA1'], $r));
}
$reject(fn () => DnsMapping::update('example.com', $before, array_replace($after, ['type' => 'TXT'])));
$reject(fn () => DnsMapping::update('example.com', $record, $after));
$reject(fn () => DnsMapping::update('example.com', $before, $after + ['record_id' => 'different']));
foreach (['', 'bad id', 'a/b', "id\n", str_repeat('a', 129)] as $id) { $reject(fn () => DnsMapping::delete('example.com', $id)); }
foreach (['name' => 'outside.example.net.', 'ttl' => -1, 'address' => 'not-ip', 'type' => 'a'] as $field => $value) {
    $reject(fn () => DnsMapping::add('example.com', array_replace($record, [$field => $value])));
}
foreach (['ttl', 'name', 'address', 'type'] as $field) {
    $bad = $record; unset($bad[$field]); $reject(fn () => DnsMapping::add('example.com', $bad));
}
foreach ([true, null, '1e3', '2147483648'] as $ttl) { $reject(fn () => DnsMapping::add('example.com', array_replace($record, ['ttl' => $ttl]))); }
$txt = DnsMapping::add('example.com', array_replace($record, ['type' => 'TXT', 'address' => 'synthetic-private-txt']));
expect(!$txt instanceof RegistrarRequest, 'Draft DNS mappings cannot enter the read client/transport');
ob_start(); var_dump($txt); $dump = ob_get_clean();
foreach ([json_encode($txt), serialize($txt), print_r($txt, true), $dump] as $visible) {
    expect(!str_contains($visible, 'synthetic-private-txt') && !str_contains($visible, 'example.com'), 'Diagnostics expose only command');
}
echo "NameSilo dormant DNS wire mapping checks passed\n";
