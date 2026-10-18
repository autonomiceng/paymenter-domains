<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use OteContract\{GuardedTransport, Shapes};
require __DIR__.'/profile-diagnostics.php';
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
$limited = new GuardedTransport('offline-secret-token', false, $output, $fake, $clock, 1);
$limited->raw('https://ote.namesilo.com/api', 'getAccountBalance', []); $beforeCalls = $calls;
$refuse(fn () => $limited->raw('https://ote.namesilo.com/api', 'getAccountBalance', []));
same($beforeCalls, $calls, 'Lower slice cap enforced before sender'); same(0, $limited->remaining(), 'Lower cap remaining');
foreach ([0, 61] as $badLimit) { $refuse(fn () => new GuardedTransport('offline-secret-token', false, $output, $fake, $clock, $badLimit)); }
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
$diagnostic = static fn ($reply, $request) => \OteContract\ParserDiagnostics::inspect(json_encode([
    'request' => ['operation' => $request->command], 'reply' => ['code' => 300, 'detail' => 'offline-secret-token'] + $reply]), $request, 'offline-secret-token');
$d = $diagnostic(['balance' => 'invented-secret-amount'], RegistrarRequest::balance());
same('amount.balance', $d['predicate'], 'Actual balance predicate diagnosed');
same('int', $d['code_type'], 'Wire JSON numeric code type retained');
same('int', $d['structure']['$.reply.code'], 'Wire structural type agrees');
same([false], $d['money_pattern']['$.reply.balance'], 'Decimal-pattern failure recorded without value');
expect(!str_contains(json_encode($d), 'invented-secret-amount') && !str_contains(json_encode($d), 'offline-secret-token'), 'Diagnostics omit scalar secrets');
$d = $diagnostic(['available' => [['domain' => 'example.com', 'price' => 'malformed']]], RegistrarRequest::availability(['example.com'], false));
same('amount.availability.price', $d['predicate'], 'Actual exception predicate diagnosed');
same('UnexpectedValueException', $d['exception_class'], 'Exception class without message');
$validPrices = ['registration' => '1.00', 'transfer' => '1.00', 'renew' => '1.00'];
$d = $diagnostic(['xn--synthetic' => $validPrices], RegistrarRequest::prices());
same('prices.tld_key', $d['predicate'], 'Actual isolated TLD-key predicate diagnosed');
same(true, $d['price_key_structure']['[other-field]'][0]['punycode_label'], 'Key syntax boolean without key value');
expect(!str_contains(json_encode($d), 'xn--synthetic'), 'Dynamic rejected key omitted');
$d = $diagnostic(['com' => ['registration' => '1.00', 'transfer' => '1.00']], RegistrarRequest::prices());
same('prices.amount.renew', $d['predicate'], 'Missing amount predicate diagnosed');
$d = $diagnostic(['com' => null], RegistrarRequest::prices());
same('prices.entry_object', $d['predicate'], 'Malformed entry predicate diagnosed');
foreach (['missing' => [], 'null' => ['registration' => null], 'zero' => ['registration' => '0.00'], 'json-type' => ['registration' => false],
    'empty-string' => ['registration' => ''], 'grouped-ambiguous' => ['registration' => '1,234'],
    'fraction-digits>2' => ['registration' => '1.234'], 'negative' => ['registration' => '-1.00'],
    'fraction-trailing-zeros' => ['registration' => '1.2300'], 'whitespace' => ['registration' => ' 1.00'],
    'decimal-edge' => ['registration' => '.99'], 'positive-sign' => ['registration' => '+1.00'],
    'exponent' => ['registration' => '1e2'],
    'non-decimal' => ['registration' => 'invented-secret-amount']] as $category => $bad) {
    $d = $diagnostic(['example' => $bad + ['transfer' => '1.00', 'renew' => '1.00']], RegistrarRequest::prices());
    same([['tld' => 'example', 'categories' => ['registration' => $category],
        'passes' => ['amount.registration' => false, 'amount.transfer' => true, 'amount.renew' => true]]],
        $d['catalogue_failures'], 'Closed per-row category and field predicates');
    expect(!str_contains(json_encode($d), 'invented-secret-amount'), 'Per-row diagnostics omit amount values');
}
$d = $diagnostic(['example' => ['registration' => 'bad'] + $validPrices,
    'invalid' => ['renew' => null] + $validPrices], RegistrarRequest::prices());
same(2, count($d['catalogue_failures']), 'Every failed row named independently');
$d = $diagnostic(['invalid-key' => null], RegistrarRequest::prices());
same('[invalid-tld]', $d['catalogue_failures'][0]['tld'], 'Invalid key never retained');
same('tld-key', $d['catalogue_failures'][0]['categories']['tld_key'], 'Bad key classified');
same('json-type', $d['catalogue_failures'][0]['categories']['row'], 'Bad row independently classified');
$d = $diagnostic(['com' => $validPrices, 'feedback' => ['transfer' => '1.00', 'renew' => '1.00']], RegistrarRequest::prices());
same('Success', $d['outcome'], 'Valid catalogue row survives unrelated quarantine');
same(null, $d['predicate'], 'Successful catalogue does not falsely fail its envelope');
same('feedback', $d['catalogue_failures'][0]['tld'], 'Successful catalogue still diagnoses quarantined rows');
$groupedCatalogue = ['request' => ['operation' => 'getPrices', 'ip' => '192.0.2.1'],
    'reply' => ['code' => 300, 'detail' => 'synthetic', 'com' => $validPrices, 'net' => $validPrices]];
$groupedCatalogue['reply']['com']['registration'] = '1,234.56';
same([], Shapes::check('getPrices', $groupedCatalogue), 'Runner accepts observed strict grouping');
foreach (['12,34.56', '0,123.45', '000,001.00', '1,234', '1,234.5', '0', '0.00', null] as $bad) {
    $groupedCatalogue['reply']['com']['registration'] = $bad;
    expect(Shapes::check('getPrices', $groupedCatalogue) !== [], 'Runner rejects malformed or ambiguous grouping');
}
$inventory = ['request' => ['operation' => 'listDomains', 'ip' => '192.0.2.1'],
    'reply' => ['code' => 300, 'detail' => 'synthetic', 'domains' => null, 'pager' => ['page' => 1, 'pageSize' => 10, 'total' => '0']]];
same([], Shapes::check('listDomains', $inventory), 'Successful independently empty null inventory accepted');
foreach ([['code' => 200], ['detail' => null], ['pager' => null], ['pager' => ['page' => 1, 'pageSize' => 10, 'total' => '1']],
    ['pager' => ['page' => 0, 'pageSize' => 10, 'total' => '0']], ['pager' => ['page' => 1, 'pageSize' => false, 'total' => '0']],
    ['pager' => ['page' => 1, 'pageSize' => 10]], ['domains' => ['broken']],
    ['domains' => [['domain' => 'example.com', 'created' => 'bad', 'expires' => '2027-01-01']]]] as $bad) {
    $rejected = $inventory; $rejected['reply'] = array_replace($rejected['reply'], $bad);
    expect(Shapes::check('listDomains', $rejected) !== [], 'Unknown or malformed inventory cannot normalize to empty');
}
$rejected = $inventory; $rejected['request']['operation'] = 'getPrices';
expect(Shapes::check('listDomains', $rejected) !== [], 'Wrong operation cannot establish empty inventory');
$rejected = $inventory; unset($rejected['reply']['domains']);
expect(Shapes::check('listDomains', $rejected) !== [], 'Missing inventory is unknown');
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
    $wire = new GuardedTransport('offline-secret-token', getenv('OTE_SELF_TEST_SCENARIO') !== 'profile-read', $output,
        static function ($command, $p) use ($state, &$wire, &$op): TransportResponse {
            $r = ['code' => 300, 'detail' => 'synthetic'];
            if ($command === 'contactAdd') { $state->profile = $p; $r['contact_id'] = '101'; }
            elseif ($command === 'contactList') {
                if (getenv('OTE_SELF_TEST_SCENARIO') === 'profile-read') {
                    $state->profile = \Paymenter\Extensions\Servers\Domains\Services\RegistrationProfiles::parameters(
                        \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::validate('namesilo', $op->payload['contacts'])['registrant']);
                }
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
        }, static fn () => 21, match (getenv('OTE_SELF_TEST_SCENARIO')) { 'limited-budget' => 28, 'short-budget' => 26, default => 60 });
    if (in_array(getenv('OTE_SELF_TEST_SCENARIO'), ['limited-budget', 'short-budget'], true)) {
        // Reserve the same six requests as write mode's initial read phase.
        for ($i = 0; $i < 6; $i++) { $wire->raw('https://ote.namesilo.com/api', 'getAccountBalance', []); }
    }
    $mode = getenv('OTE_SELF_TEST_SCENARIO') === 'profile-read' ? '--profile-read' : '--write';
    require __DIR__.'/registration.php';
    if ($mode === '--profile-read') {
        same(1, $wire->receipt['request_count'], 'Read-only probe reuses existing synthetic contact');
        same(false, $wire->attempted('contactAdd'), 'Read-only probe creates no contact');
        same(false, $wire->attempted('registerDomain'), 'Read-only probe cannot purchase');
        same(true, $wire->receipt['profile_inventory']['rows'][0]['identity_match'], 'Frozen synthetic identity compared');
        same(true, $wire->receipt['checks']['profile_authority_after'], 'Current authority checked after read');
    } else {
    same('accepted_via_real_admitted_protocol', $wire->receipt['checks']['registration'], 'Real registration path exercised offline');
    same(true, $wire->receipt['checks']['charge_evidence_shape_matches'], 'Charge correlation exercised offline');
    if (in_array(getenv('OTE_SELF_TEST_SCENARIO'), ['limited-budget', 'short-budget'], true)) {
        expect($wire->receipt['request_count'] <= (getenv('OTE_SELF_TEST_SCENARIO') === 'short-budget' ? 26 : 28), 'Constrained write never exceeds remaining slice allowance');
        if (getenv('OTE_SELF_TEST_SCENARIO') === 'short-budget') {
            same('skipped_budget', $wire->receipt['checks']['remove_auto_renew_readback'], 'Management reserves its complete readback group');
        }
        same('skipped_budget', $wire->receipt['checks']['txt_sequence'], 'TXT explicitly omitted under cap');
        same('skipped_budget', $wire->receipt['checks']['renew_probe'], 'Renewal explicitly omitted under cap');
        same(false, $wire->attempted('renewDomain'), 'No renewal without reserve');
    } else {
    same(true, $wire->receipt['checks']['renew_added_one_year'], 'One renewal exercised offline');
    same(true, $wire->receipt['checks']['renewal_charge_evidence_shape_matches'], 'Renewal charge correlation exercised offline');
    same(true, $wire->receipt['checks']['txt_deleted'], 'TXT cleanup exercised offline');
    }
    same([], $wire->receipt['mismatches'], 'Native fake matches all contract projections');
    }
}
$summary = ['self_test' => 'passed', 'real_provider_calls' => 0, 'native_protocol_exercised' => (bool) getenv('OTE_SELF_TEST_NATIVE')];
if ($wire) { $wire->receipt += $summary; $wire->save(); }
else { file_put_contents($output.'/receipt.json', json_encode($summary, JSON_PRETTY_PRINT)."\n"); }
