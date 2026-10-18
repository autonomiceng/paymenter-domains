<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use OteContract\{GuardedTransport, Shapes};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};

$output = (string) getenv('OTE_OUTPUT'); $calls = 0;
$fake = static function ($command, $p) use (&$calls): TransportResponse {
    $calls++;
    return new TransportResponse(200, json_encode(['request' => ['operation' => $command, 'key' => 'offline-secret-token'],
        'reply' => ['code' => 300, 'detail' => 'offline-secret-token', 'balance' => '10.00']], JSON_THROW_ON_ERROR));
};
$hour = 17;
$clock = static function () use (&$hour): int { return $hour; };
$guard = new GuardedTransport('offline-secret-token', false, $output, $fake, $clock);
$refuse = static function ($fn): void {
    try { $fn(); } catch (Throwable) { return; } throw new RuntimeException('Missing contract refusal');
};
$refuse(fn () => new GuardedTransport('offline-secret-token', true, $output, null, static fn () => 21));
foreach (['https://www.namesilo.com/api', 'http://ote.namesilo.com/api', 'https://ote.namesilo.com.evil.test/api',
    'https://ote.namesilo.com:443/api', 'https://ote.namesilo.com/api/../api', 'https://ote.namesilo.com/apibatch?x=1',
    'https://ote.namesilo.com@other.test/api'] as $origin) { $refuse(fn () => $guard->raw($origin, 'getAccountBalance', [])); }
$refuse(fn () => $guard->send(WireEnvironment::Live, WireContext::Interactive, RegistrarRequest::balance(), 'offline-secret-token'));
foreach ([18, 19, 20] as $hour) { $refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getAccountBalance', [])); }
$hour = 21;
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'registerDomain', ['domain' => $guard->domain]));
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getDomainInfo', ['domain' => 'example.com']));
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getDomainInfo', ['domain' => 'contract-ote-123456789abc.org']));
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getAccountBalance', ['key' => 'override']));
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'transferDomain', []));
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getDomainInfo', []));
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'checkRegisterAvailability', []));
same(0, $calls, 'All unsafe inputs refused before sender');
$boundaryChecks = 0;
$boundary = new GuardedTransport('offline-secret-token', false, $output, $fake,
    static function () use (&$boundaryChecks): int { return ++$boundaryChecks === 1 ? 17 : 18; });
$refuse(fn () => $boundary->raw('https://ote.namesilo.com/api', 'getAccountBalance', []));
same(0, $calls, 'Crossing closed window before send refused');
$badStore = $output.'/failed-save'; mkdir($badStore, 0700); mkdir($badStore.'/receipt.json', 0700);
$storageGuard = new GuardedTransport('offline-secret-token', false, $badStore, $fake, $clock);
$beforeCalls = $calls;
$refuse(fn () => $storageGuard->raw('https://ote.namesilo.com/api', 'getAccountBalance', []));
same($beforeCalls, $calls, 'Receipt failure prevents network send');
$guard->send(WireEnvironment::Sandbox, WireContext::Interactive, RegistrarRequest::balance(), 'offline-secret-token');
$receipt = file_get_contents($output.'/receipt.json'); expect(!str_contains($receipt, 'offline-secret-token'), 'Echoed secrets omitted');
same('[redacted]', $guard->receipt['requests'][0]['request']['key'], 'Key query capture redacted');
for ($i = 1; $i < 60; $i++) { $guard->raw('https://ote.namesilo.com/api', 'getAccountBalance', []); }
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getAccountBalance', [])); same(60, $calls, 'Hard budget');
$writer = new GuardedTransport('offline-secret-token', true, $output, $fake, $clock);
$p = ['domain' => $writer->domain, 'years' => 1, 'auto_renew' => 0, 'private' => 0];
foreach ([['years' => 2], ['private' => 1], ['auto_renew' => 1], ['payment_id' => '1'], ['ns1' => 'ns1.other.test']] as $bad) {
    $refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'registerDomain', array_replace($p, $bad)));
}
$refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'domainLock', []));
$refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'domainLock', ['domain' => substr($writer->domain, 0, -4).'.net']));
$writer->raw('https://ote.namesilo.com/api', 'registerDomain', $p);
$refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'registerDomain', $p));
$refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'renewDomain', ['domain' => $writer->domain, 'years' => 1, 'auto_renew' => 0]));
$writer->authorizeRenewal(true);
$writer->raw('https://ote.namesilo.com/api', 'renewDomain', ['domain' => $writer->domain, 'years' => 1, 'auto_renew' => 0]);
$refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'renewDomain', ['domain' => $writer->domain, 'years' => 1, 'auto_renew' => 0]));
$http = new GuardedTransport('offline-secret-token', true, $output,
    static fn () => new TransportResponse(500, json_encode(['request' => ['operation' => 'registerDomain'], 'reply' => ['code' => 300, 'detail' => 'synthetic', 'domain' => 'synthetic', 'order_amount' => '8.95']])), $clock);
$refuse(fn () => $http->raw('https://ote.namesilo.com/api', 'registerDomain', ['domain' => $http->domain, 'years' => 1, 'auto_renew' => 0, 'private' => 0]));
same(true, $http->receipt['checks']['purchase_unresolved'], 'HTTP error preserves uncertain purchase');
$failedCalls = 0;
$failure = new GuardedTransport('offline-secret-token', true, $output,
    static function () use (&$failedCalls) { $failedCalls++; throw new RuntimeException('offline-secret-token'); }, $clock);
$refuse(fn () => $failure->raw('https://ote.namesilo.com/api', 'registerDomain', ['domain' => $failure->domain, 'years' => 1, 'auto_renew' => 0, 'private' => 0]));
same(1, $failedCalls, 'Transport error never retries');
$refuse(fn () => $failure->raw('https://ote.namesilo.com/api', 'registerDomain', ['domain' => $failure->domain, 'years' => 1, 'auto_renew' => 0, 'private' => 0]));
same(1, $failedCalls, 'Uncertain purchase cannot replay');
$shape = Shapes::shape(['account-identifier' => ['key' => 'offline-secret-token']]);
expect(!str_contains(json_encode($shape), 'account-identifier'), 'Dynamic field names never escape');
$fixtures = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/synthetic-success.json'), true, 24, JSON_THROW_ON_ERROR);
foreach ($fixtures as $command => $fixture) {
    if ($command === 'getPrices') {
        expect(in_array('reply.net.missing', Shapes::check($command, $fixture), true), 'Missing net price detected');
        $fixture['reply']['net'] = $fixture['reply']['com'];
    }
    if ($command !== '_fixture') { same([], Shapes::check($command, $fixture), 'Documented fixture shape '.$command); }
}
$incomplete = ['request' => ['operation' => 'listDomains'], 'reply' => ['code' => 300, 'detail' => 'synthetic', 'domains' => [['domain' => 'synthetic.com']], 'pager' => ['page' => 1]]];
expect(in_array('reply.domains.[].created.missing', Shapes::check('listDomains', $incomplete), true), 'Nested missing date detected');
expect(in_array('reply.pager.pageSize.missing', Shapes::check('listDomains', $incomplete), true), 'Missing pager size detected');
$wrongCode = ['request' => ['operation' => 'changeNameServers'], 'reply' => ['code' => 251, 'detail' => 'synthetic']];
expect(in_array('reply.code_not_success', Shapes::check('changeNameServers', $wrongCode), true), 'Unrelated state code refused');
foreach (['2027-99-99', '2027-02-29', '2027-02-30', '2027-01-01suffix', '', null] as $badDate) {
    same(null, Shapes::strictDate($badDate), 'Invalid expiry refuses renewal probe');
}
same('2028-02-29', Shapes::strictDate('2028-02-29')?->format('Y-m-d'), 'Strict leap date accepted');
$missingMethod = $fixtures['orderDetails']; unset($missingMethod['reply']['method']);
expect(in_array('reply.method.missing', Shapes::check('orderDetails', $missingMethod), true), 'Missing order method detected');
$wrongText = $fixtures['listOrders']; $wrongText['reply']['order'][0]['method'] = 123;
expect(in_array('fixture_type.$.reply.order.[].method', Shapes::check('listOrders', $wrongText), true), 'Numeric text mismatch detected');
$purchase = $fixtures['registerDomain']; unset($purchase['reply']['order_amount']);
$parsed = (new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\ResponseParser)->parse(json_encode($purchase),
    \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::register('example.com', 1, false, '101', ['ns1.namesilo.com', 'ns2.namesilo.com']), 'offline-secret-token');
same(\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Outcome::Unknown, $parsed->outcome, 'Malformed purchase stays unknown');
same(false, GuardedTransport::durableRegistration([], $writer->domain), 'Unresolved purchase cannot continue');
same(false, GuardedTransport::durableRegistration(['registration' => ['accepted' => true, 'query' => ['name' => 'other.com', 'years' => 1]]], $writer->domain), 'Wrong durable name cannot continue');
if (getenv('OTE_SELF_TEST_NATIVE')) {
    putenv('NAMESILO_SANDBOX_API_KEY=offline-secret-token');
    $state = (object) ['profile' => [], 'locked' => 'Yes', 'expiry' => gmdate('Y-m-d', strtotime('+1 year')), 'record' => false, 'renewed' => false];
    $wire = new GuardedTransport('offline-secret-token', true, $output,
        static function ($command, $p) use ($state, &$wire): TransportResponse {
            $r = ['code' => 300, 'detail' => 'synthetic'];
            if ($command === 'contactAdd') { $state->profile = $p; $r['contact_id'] = '101'; }
            elseif ($command === 'contactList') {
                $fields = ['first_name','last_name','company','address','address2','city','state','zip','country','email','phone'];
                $r['contact'] = [array_combine($fields, array_values($state->profile)) + ['contact_id' => '101', 'default_profile' => '0', 'nickname' => '', 'fax' => '']];
            } elseif ($command === 'getAccountBalance') { $r['balance'] = '100.00'; }
            elseif ($command === 'getPrices') { $r['com'] = $r['net'] = ['registration' => '8.95', 'renew' => '8.95', 'transfer' => '8.95']; }
            elseif ($command === 'checkRegisterAvailability') {
                foreach (explode(',', $p['domains']) as $name) { $r['available'][] = ['domain' => $name, 'price' => '8.95', 'premium' => 0, 'duration' => 1]; }
            } elseif ($command === 'registerDomain' || $command === 'renewDomain') {
                if ($command === 'renewDomain' && getenv('OTE_SELF_TEST_SCENARIO') === 'renewal-transport-failure') { throw new RuntimeException('offline-secret-token'); }
                $r += ['message' => 'synthetic', 'domain' => $p['domain'], 'order_amount' => '8.95'];
                if ($command === 'registerDomain' && getenv('OTE_SELF_TEST_SCENARIO') === 'malformed-registration') { unset($r['order_amount']); }
                if ($command === 'renewDomain') { $state->renewed = true; $state->expiry = (new DateTimeImmutable($state->expiry))->modify('+1 year')->format('Y-m-d'); }
            } elseif ($command === 'getDomainInfo') {
                $r += ['created' => gmdate('Y-m-d'), 'expires' => $state->expiry, 'status' => 'Active', 'locked' => $state->locked,
                    'private' => 'No', 'auto_renew' => 'No', 'traffic_type' => 'Custom DNS', 'email_verification_required' => 'No', 'portfolio' => '', 'forward_url' => 'N/A', 'forward_type' => 'N/A', 'nameservers' => [['nameserver' => 'ns1.namesilo.com', 'position' => 1], ['nameserver' => 'ns2.namesilo.com', 'position' => 2]],
                    'contact_ids' => array_fill_keys(['registrant','administrative','technical','billing'], '101')];
            } elseif ($command === 'listOrders') { $r['order'] = [['order_number' => '901', 'order_date' => gmdate('Y-m-d H:i:s'), 'method' => 'Account Funds', 'total' => '8.95']]; }
            elseif ($command === 'orderDetails') {
                $r += ['order_number' => '901', 'order_date' => gmdate('Y-m-d H:i:s'), 'method' => 'Account Funds', 'total' => '8.95',
                    'order_details' => [['description' => $wire->domain.' - '.($state->renewed ? 'renewal' : 'registration'), 'years_qty' => '1', 'price' => '8.95', 'subtotal' => '8.95', 'status' => 'Processed']]];
            } elseif ($command === 'domainUnlock') { $state->locked = 'No'; }
            elseif ($command === 'domainLock') { $state->locked = 'Yes'; }
            elseif ($command === 'removeAutoRenewal') { $r['code'] = 251; }
            elseif ($command === 'dnsAddRecord') { $state->record = true; $r['record_id'] = 'txt-record'; }
            elseif ($command === 'dnsDeleteRecord') { $state->record = false; }
            elseif ($command === 'dnsListRecords') {
                $r['resource_record'] = $state->record ? [['record_id' => 'txt-record', 'type' => 'TXT', 'host' => '_contract', 'value' => 'synthetic-contract', 'ttl' => 3600, 'distance' => 0]] : [];
            } elseif ($command !== 'changeNameServers') { throw new RuntimeException('Unexpected offline command'); }
            return new TransportResponse(200, json_encode(['request' => ['operation' => $command, 'ip' => '192.0.2.1'], 'reply' => $r], JSON_THROW_ON_ERROR));
        }, static fn () => 21);
    require __DIR__.'/registration.php';
    same('accepted_via_real_admitted_protocol', $wire->receipt['checks']['registration'], 'Real registration path exercised offline');
    same(true, $wire->receipt['checks']['charge_evidence_shape_matches'], 'Charge correlation exercised offline');
    same(true, $wire->receipt['checks']['renew_added_one_year'], 'One renewal exercised offline');
    same(true, $wire->receipt['checks']['renewal_charge_evidence_shape_matches'], 'Renewal charge correlation exercised offline');
    same(true, $wire->receipt['checks']['txt_deleted'], 'TXT cleanup exercised offline');
    same([], $wire->receipt['mismatches'], 'Native fake matches all contract projections');
}
$summary = ['self_test' => 'passed', 'real_provider_calls' => 0, 'native_protocol_exercised' => (bool) getenv('OTE_SELF_TEST_NATIVE')];
if ($wire) { $wire->receipt += $summary; $wire->save(); }
else { file_put_contents($output.'/receipt.json', json_encode($summary, JSON_PRETTY_PRINT)."\n"); }
