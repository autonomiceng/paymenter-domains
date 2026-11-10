<?php
// Synthetic write acknowledgements and independent readback. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Dns;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DnsMapping, Outcome, RegistrarClient, Environment, RequestContext, Transport, TransportResponse, RegistrarRequest};

expect(method_exists(Dns::class, 'acknowledgement'), 'Dormant DNS acknowledgement parser exists');
$body = static fn (string $operation, array $reply): string => json_encode([
    'request' => ['operation' => $operation], 'reply' => array_replace(['code' => 300, 'detail' => 'synthetic'], $reply),
], JSON_THROW_ON_ERROR);
$record = ['name' => '@', 'type' => 'A', 'address' => '192.0.2.1', 'mx_pref' => 10, 'ttl' => 300];
$old = $record + ['record_id' => 'oldA1'];
$add = DnsMapping::add('example.com', $record);
$update = DnsMapping::update('example.com', $old, $record);
$delete = DnsMapping::delete('example.com', 'oldA1');
foreach ([$add, $update] as $mapping) {
    $ack = Dns::acknowledgement($body($mapping->command, ['record_id' => 'newB2']), $mapping);
    same(Outcome::Success, $ack->outcome, 'Exact documented acknowledgement');
    same('newB2', $ack->value('record_id'), 'Returned ID is retained for subsequent readback');
    same(null, $ack->value('detail'), 'Provider diagnostics are not retained');
    foreach ([[], ['record_id' => null], ['record_id' => 'bad id'], ['record_id' => ['newB2']],
        ['record_id' => 'newB2', 'unknown' => true], ['code' => 280], ['code' => 301], ['code' => 302]] as $reply) {
        same(Outcome::Unknown, Dns::acknowledgement($body($mapping->command, $reply), $mapping)->outcome,
            'Missing/malformed IDs, unexpected fields and nonstandard acknowledgements remain unknown');
    }
}
same(Outcome::Unknown, Dns::acknowledgement($body('dnsUpdateRecord', ['record_id' => 'oldA1']), $update)->outcome,
    'Documented update requires a new ID');
same(Outcome::Unknown, Dns::acknowledgement($body('dnsAddRecord', ['record_id' => 'newB2']), $update)->outcome,
    'Acknowledgement must match the frozen operation');
same(Outcome::Unknown, Dns::acknowledgement('{', $delete)->outcome, 'Lost/truncated response cannot be a definite failure');
same(Outcome::Success, Dns::acknowledgement($body('dnsDeleteRecord', []), $delete)->outcome, 'Delete acknowledgement needs no new ID');
same(Outcome::Unknown, Dns::acknowledgement($body('dnsDeleteRecord', ['record_id' => 'newB2']), $delete)->outcome,
    'Unexpected delete data cannot establish the documented acknowledgement');
same(Outcome::Unknown, Dns::acknowledgement($body('dnsListRecords', []), DnsMapping::listRecords('example.com'))->outcome,
    'Inventory is not a mutation acknowledgement');
$readback = ['record_id' => 'newB2', 'type' => 'A', 'host' => 'example.com', 'value' => '192.0.2.1', 'ttl' => 300, 'distance' => 0];
$observed = Dns::inventory($body('dnsListRecords', ['resource_record' => [$readback]]), 'example.com');
same(['state' => 'complete', 'records' => [['name' => '@', 'type' => 'A', 'address' => '192.0.2.1',
    'mx_pref' => 10, 'record_id' => 'newB2', 'ttl' => 300]]], $observed,
    'Independent readback retains replacement ID and TTL in shared shape');
same(['state' => 'complete', 'records' => []], Dns::inventory($body('dnsListRecords', ['resource_record' => []]), 'example.com'),
    'Deletion of the final record can be observed without a fabricated replacement row');
same('unknown', Dns::inventory($body('dnsListRecords', []), 'example.com')['state'], 'Missing list does not prove final deletion');
// Duplicate content has distinct provider identities: the future sequence must decide uniqueness, not this parser.
$duplicate = $readback; $duplicate['record_id'] = 'otherC3';
$observed = Dns::inventory($body('dnsListRecords', ['resource_record' => [$readback, $duplicate]]), 'example.com');
same(2, count($observed['records']), 'Do not collapse duplicate record content into false unique readback');
// Real read-client type boundary: every descriptor is rejected before a transport call.
$transport = new class implements Transport {
    public int $calls = 0;
    public function send(Environment $environment, RequestContext $context, RegistrarRequest $request, string $apiKey): TransportResponse
    { ++$this->calls; throw new RuntimeException('No transport should be reached'); }
};
$client = new RegistrarClient('placeholder-key', Environment::Sandbox, $transport);
foreach ([$add, $update, $delete, DnsMapping::listRecords('example.com')] as $mapping) {
    try { $client->execute($mapping, RequestContext::Interactive); throw new RuntimeException('Expected type refusal'); }
    catch (TypeError) {}
}
same(0, $transport->calls, 'Dormant primitives enable no sends');
// A malformed CAA sibling cannot certify a complete preimage or discard valid siblings.
foreach (['not-a-caa-triplet', '0:issue', '0::ca.example.com', ':issue:ca.example.com',
    'bad:issue:ca.example.com', '256:issue:ca.example.com', '0:issue:'] as $value) {
    $caa = array_replace($readback, ['record_id' => 'caa', 'type' => 'CAA', 'value' => $value]);
    $inventory = Dns::inventory($body('dnsListRecords', ['resource_record' => [$readback, $caa]]), 'example.com');
    same('partial', $inventory['state'], 'Malformed CAA prevents a complete inventory');
    same([['name' => '@', 'type' => 'A', 'address' => '192.0.2.1', 'mx_pref' => 10, 'record_id' => 'newB2', 'ttl' => 300]],
        $inventory['records'], 'Valid siblings survive malformed CAA without changing their values, IDs or TTL');
    same('unknown', Dns::inventory($body('dnsListRecords', ['resource_record' => [$caa]]), 'example.com')['state'],
        'A malformed CAA alone cannot prove a complete preimage');
}
foreach (['0:issue:ca.example.com', '0:iodef:mailto:dns@example.com',
    '128:issuewild:ca.example.com; accounturi=https://ca.example.com/accounts/123'] as $value) {
    $caa = array_replace($readback, ['record_id' => 'caa', 'type' => 'CAA', 'value' => $value]);
    $inventory = Dns::inventory($body('dnsListRecords', ['resource_record' => [$caa]]), 'example.com');
    same('complete', $inventory['state'], 'Documented CAA triplet permits colons inside its value');
    same($value, $inventory['records'][0]['address'], 'CAA validation preserves original bytes');
}
// The dormant DNS adapter refreshes through the read-only reader, so it reports what that reader reads; mutations stay as they were.
$binding = new \Paymenter\Extensions\Servers\Domains\Data\AccountBinding('namesilo', 1, \Paymenter\Extensions\Servers\Domains\Data\Environment::Test, 'synthetic-account');
$exchange = static fn () => throw new LogicException('No wire');
$baseline = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management($binding, $exchange);
$dns = new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsManagement($binding, $exchange);
same((new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ObservationReader($binding, $exchange))->observationCapabilities(),
    $dns->observationCapabilities(), 'The DNS adapter reports what its read-only reader reads');
same('supported', $dns->observationCapabilities()['families']['dns'], 'The DNS adapter reports DNS observation');
same('unsupported', $baseline->observationCapabilities()['families']['dns'], 'The installed adapter still reports no DNS observation');
foreach (['nameservers', 'contacts', 'lock', 'privacy', 'auto_renew_off', 'auth_code_email', 'glue', 'ds', 'auth_code_display'] as $action) {
    same($baseline->capabilities()->get($action)->support, $dns->capabilities()->get($action)->support, 'The DNS adapter keeps baseline '.$action.' support');
}
same(\Paymenter\Extensions\Servers\Domains\Data\Support::Unsupported, $baseline->capabilities()->get('dns')->support, 'The installed adapter still closes DNS editing');
echo "NameSilo DNS acknowledgement/readback checks passed\n";
