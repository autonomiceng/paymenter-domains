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
$refuse = static function (callable $fn, string $expected): void {
    try { $fn(); } catch (Throwable $error) {
        same(RuntimeException::class, $error::class, 'Contract refusal class');
        same($expected, $error->getMessage(), 'Contract refusal must name its guard'); return;
    }
    throw new RuntimeException('Missing contract refusal: '.$expected);
};
$refuse(fn () => new GuardedTransport('offline-secret-token', true, $output, null, static fn () => 21), 'OTE live clock cannot be overridden.');
foreach (['https://www.namesilo.com/api', 'http://ote.namesilo.com/api', 'https://ote.namesilo.com.evil.test/api',
    'https://ote.namesilo.com:443/api', 'https://ote.namesilo.com/api/../api', 'https://ote.namesilo.com/apibatch?x=1',
    'https://ote.namesilo.com@other.test/api'] as $origin) { $refuse(fn () => $guard->raw($origin, 'getAccountBalance', []), 'OTE origin refused.'); }
$refuse(fn () => $guard->send(WireEnvironment::Live, WireContext::Interactive, RegistrarRequest::balance(), 'offline-secret-token'), 'OTE environment refused.');
foreach ([18, 19, 20] as $hour) { $refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getAccountBalance', []), 'OTE time window closed.'); }
$hour = 21;
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'registerDomain', ['domain' => $guard->domain]), 'OTE write flag required.');
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getDomainInfo', ['domain' => 'example.com']), 'OTE domain scope refused.');
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getDomainInfo', ['domain' => 'contract-ote-123456789abc.org']), 'OTE domain scope refused.');
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getAccountBalance', ['key' => 'override']), 'OTE parameters refused.');
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'transferDomain', []), 'OTE command refused.');
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getDomainInfo', []), 'OTE domain scope refused.');
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'checkRegisterAvailability', []), 'OTE availability scope required.');
same(0, $calls, 'All unsafe inputs refused before sender');
$boundaryChecks = 0;
$boundary = new GuardedTransport('offline-secret-token', false, $output, $fake,
    static function () use (&$boundaryChecks): int { return ++$boundaryChecks === 1 ? 17 : 18; });
$refuse(fn () => $boundary->raw('https://ote.namesilo.com/api', 'getAccountBalance', []), 'OTE time window closed.');
same(0, $calls, 'Crossing closed window before send refused');
$badStore = $output.'/failed-save'; mkdir($badStore, 0700); mkdir($badStore.'/receipt.json', 0700);
$storageGuard = new GuardedTransport('offline-secret-token', false, $badStore, $fake, $clock);
$beforeCalls = $calls;
$refuse(fn () => $storageGuard->raw('https://ote.namesilo.com/api', 'getAccountBalance', []), 'OTE receipt persistence failed.');
same($beforeCalls, $calls, 'Receipt failure prevents network send');
$guard->send(WireEnvironment::Sandbox, WireContext::Interactive, RegistrarRequest::balance(), 'offline-secret-token');
$receipt = file_get_contents($output.'/receipt.json'); expect(!str_contains($receipt, 'offline-secret-token'), 'Echoed secrets omitted');
same('[redacted]', $guard->receipt['requests'][0]['request']['key'], 'Key query capture redacted');
for ($i = 1; $i < 60; $i++) { $guard->raw('https://ote.namesilo.com/api', 'getAccountBalance', []); }
$refuse(fn () => $guard->raw('https://ote.namesilo.com/api', 'getAccountBalance', []), 'OTE budget refused.'); same(60, $calls, 'Hard budget');
$limited = new GuardedTransport('offline-secret-token', false, $output, $fake, $clock, 1);
$limited->raw('https://ote.namesilo.com/api', 'getAccountBalance', []); $beforeCalls = $calls;
$refuse(fn () => $limited->raw('https://ote.namesilo.com/api', 'getAccountBalance', []), 'OTE budget refused.');
same($beforeCalls, $calls, 'Lower slice cap enforced before sender'); same(0, $limited->remaining(), 'Lower cap remaining');
foreach ([0, 61] as $badLimit) { $refuse(fn () => new GuardedTransport('offline-secret-token', false, $output, $fake, $clock, $badLimit), 'OTE budget refused.'); }
$writer = new GuardedTransport('offline-secret-token', true, $output, $fake, $clock);
$writer->purchaseAuthorization(static fn () => new \Paymenter\Extensions\Servers\Domains\Data\Money('8.95', 'USD'));
$p = ['domain' => $writer->domain, 'years' => 1, 'auto_renew' => 0, 'private' => 0];
foreach ([['years' => 2], ['private' => 1], ['auto_renew' => 1], ['payment_id' => '1'], ['ns1' => 'ns1.other.test']] as $bad) {
    $expected = match (array_key_first($bad)) {
        'payment_id' => 'OTE parameters refused.', 'ns1' => 'OTE nameserver refused.', default => 'OTE purchase refused.',
    };
    $refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'registerDomain', array_replace($p, $bad)), $expected);
}
$refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'domainLock', []), 'OTE domain scope refused.');
$refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'domainLock', ['domain' => substr($writer->domain, 0, -4).'.net']), 'OTE domain scope refused.');
$writer->raw('https://ote.namesilo.com/api', 'registerDomain', $p);
$refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'registerDomain', $p), 'OTE purchase refused.');
$refuse(fn () => $writer->raw('https://ote.namesilo.com/apibatch', 'renewDomain', ['domain' => $writer->domain, 'years' => 1, 'auto_renew' => 0]), 'OTE durable registration required.');
$writer->authorizeRenewal(true); $writer->renewalBefore('2027-01-01');
$beforeCalls = $calls;
$refuse(fn () => $writer->raw('https://ote.namesilo.com/api', 'renewDomain', ['domain' => $writer->domain, 'years' => 1, 'auto_renew' => 0]), 'Renewal route requires claimed continuation.');
same($beforeCalls, $calls, 'Interactive refusal precedes sender');
$writer->raw('https://ote.namesilo.com/apibatch', 'renewDomain', ['domain' => $writer->domain, 'years' => 1, 'auto_renew' => 0]);
same($beforeCalls + 1, $calls, 'Batch renewal reaches sender exactly once');
$refuse(fn () => $writer->raw('https://ote.namesilo.com/apibatch', 'renewDomain', ['domain' => $writer->domain, 'years' => 1, 'auto_renew' => 0]), 'OTE purchase refused.');
same($beforeCalls + 1, $calls, 'Renewal replay refused before sender');
$http = new GuardedTransport('offline-secret-token', true, $output,
    static fn () => new TransportResponse(500, json_encode(['request' => ['operation' => 'registerDomain'], 'reply' => ['code' => 300, 'detail' => 'synthetic', 'domain' => 'synthetic', 'order_amount' => '8.95']])), $clock);
$http->purchaseAuthorization(static fn () => new \Paymenter\Extensions\Servers\Domains\Data\Money('8.95', 'USD'));
$refuse(fn () => $http->raw('https://ote.namesilo.com/api', 'registerDomain', ['domain' => $http->domain, 'years' => 1, 'auto_renew' => 0, 'private' => 0]), 'OTE exchange unresolved.');
same(true, $http->receipt['checks']['purchase_unresolved'], 'HTTP error preserves uncertain purchase');
$failedCalls = 0;
$failure = new GuardedTransport('offline-secret-token', true, $output,
    static function () use (&$failedCalls) { $failedCalls++; throw new RuntimeException('offline-secret-token'); }, $clock);
$failure->purchaseAuthorization(static fn () => new \Paymenter\Extensions\Servers\Domains\Data\Money('8.95', 'USD'));
$refuse(fn () => $failure->raw('https://ote.namesilo.com/api', 'registerDomain', ['domain' => $failure->domain, 'years' => 1, 'auto_renew' => 0, 'private' => 0]), 'OTE exchange unresolved.');
same(1, $failedCalls, 'Transport error never retries');
$refuse(fn () => $failure->raw('https://ote.namesilo.com/api', 'registerDomain', ['domain' => $failure->domain, 'years' => 1, 'auto_renew' => 0, 'private' => 0]), 'OTE purchase refused.');
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
$singleShape = ['request' => ['operation' => 'checkRegisterAvailability', 'ip' => '192.0.2.1'],
    'reply' => ['code' => 300, 'detail' => 'synthetic', 'available' => ['domain' =>
        ['domain' => 'example.com', 'price' => 8.95, 'premium' => 0, 'duration' => 1, 'renew' => 9.95]]]];
same([], Shapes::check('checkRegisterAvailability', $singleShape, ['example.com']), 'Observed singleton projection accepted');
expect(Shapes::check('checkRegisterAvailability', $singleShape, ['example.com', 'example.net']) !== [], 'Multi-name nested projection refused');
$badShape = $singleShape; $badShape['reply']['available']['extra'] = true;
expect(Shapes::check('checkRegisterAvailability', $badShape, ['example.com']) !== [], 'Extra wrapper field not silently discarded');
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
$d = \OteContract\ParserDiagnostics::inspect(json_encode(['request' => ['operation' => 'getPrices'],
    'reply' => ['code' => 300, 'detail' => null, 'com' => $validPrices, 'net' => $validPrices]]), RegistrarRequest::prices(), 'offline-secret-token');
same('envelope', $d['predicate'], 'Envelope failure not replaced by per-row predicate');
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
$incomplete = ['request' => ['operation' => 'listDomains'], 'reply' => ['code' => 300, 'detail' => 'synthetic', 'domains' => [['domain' => 'synthetic-placeholder.com']], 'pager' => ['page' => 1]]];
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
same(false, GuardedTransport::durableRegistration(['registration' => ['accepted' => true, 'query' => ['name' => 'other-placeholder.com', 'years' => 1]]], $writer->domain), 'Wrong durable name cannot continue');
if (getenv('OTE_SELF_TEST_NATIVE')) {
    foreach (glob($output.'/*attempt-private*.json') as $private) { unlink($private); }
    putenv('NAMESILO_SANDBOX_API_KEY=offline-secret-token');
    $state = (object) ['profile' => [], 'locked' => 'Yes', 'expiry' => gmdate('Y-m-d', strtotime('+1 year')), 'record' => false, 'renewed' => false];
    $scenario = (string) getenv('OTE_SELF_TEST_SCENARIO'); $profileRead = str_starts_with($scenario, 'profile-read');
    if ($scenario === 'profile-read-claim') {
        require_once dirname(__DIR__).'/native-app.php';
        \Paymenter\Extensions\Servers\Domains\Models\Operation::updated(static function ($row): void {
            if ($row->wasChanged('payload') && $row->attempt === null) {
                \Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $row->id)->update(['state' => 'held']);
            }
        });
    }
    $wire = new GuardedTransport('offline-secret-token', !$profileRead, $output,
        static function ($command, $p) use ($state, &$wire, &$op): TransportResponse {
            $r = ['code' => 300, 'detail' => 'synthetic'];
            if ($command === 'contactAdd') { $state->profile = $p; $r['contact_id'] = '101'; }
            elseif ($command === 'contactList') {
                if (str_starts_with((string) getenv('OTE_SELF_TEST_SCENARIO'), 'profile-read')) {
                    $state->profile = \Paymenter\Extensions\Servers\Domains\Services\RegistrationProfiles::parameters(
                        \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::validate('namesilo', $op->payload['contacts'])['registrant']);
                }
                $fields = ['first_name','last_name','company','address','address2','city','state','zip','country','email','phone'];
                $r['contact'] = [array_combine($fields, array_values($state->profile)) + ['contact_id' => '101', 'default_profile' => '0', 'nickname' => '', 'fax' => '']];
                $scenario = getenv('OTE_SELF_TEST_SCENARIO');
                if ($scenario === 'profile-read-contact') { $r['contact'][0]['email'] = 'other@example.com'; }
                if ($scenario === 'profile-read-duplicate') { $r['contact'][] = ['contact_id' => '102'] + $r['contact'][0]; }
                if ($scenario === 'profile-read-authority') { $op->fresh()->update(['policy' => $op->policy + ['diagnostic_drift' => true]]); }
                if ($scenario === 'profile-read-status') { $r['code'] = 200; }
                if ($scenario === 'profile-read-malformed') { unset($r['code']); }
                if ($scenario === 'profile-read-transport') { throw new RuntimeException('Synthetic read failure'); }
            } elseif ($command === 'getAccountBalance') { $r['balance'] = '100.00'; }
            elseif ($command === 'getPrices') { $r['com'] = $r['net'] = ['registration' => '8.95', 'renew' => '8.95', 'transfer' => '8.95']; }
            elseif ($command === 'checkRegisterAvailability') {
                foreach (explode(',', $p['domains']) as $name) { $r['available'][] = ['domain' => $name, 'price' => 8.95, 'premium' => 0, 'duration' => 1, 'renew' => 9.95]; }
                if (count($r['available']) === 1) {
                    $row = $r['available'][0];
                    if (getenv('OTE_SELF_TEST_SCENARIO') === 'missing-premium') { unset($row['premium']); }
                    if (getenv('OTE_SELF_TEST_SCENARIO') === 'premium-one') { $row['premium'] = 1; }
                    $r['available'] = ['domain' => $row];
                }
            } elseif ($command === 'registerDomain' || $command === 'renewDomain') {
                if ($command === 'renewDomain' && getenv('OTE_SELF_TEST_SCENARIO') === 'renewal-transport-failure') { throw new RuntimeException('offline-secret-token'); }
                $r += ['message' => 'synthetic', 'domain' => $p['domain'], 'order_amount' => '8.95'];
                if ($command === 'registerDomain' && getenv('OTE_SELF_TEST_SCENARIO') === 'malformed-registration') { unset($r['order_amount']); }
                if ($command === 'registerDomain' && str_starts_with((string) getenv('OTE_SELF_TEST_SCENARIO'), 'unknown-')) { $r = ['code' => null, 'detail' => null]; }
                if ($command === 'renewDomain') { $state->renewed = true; $state->expiry = (new DateTimeImmutable($state->expiry))->modify('+1 year')->format('Y-m-d'); }
            } elseif ($command === 'getDomainInfo') {
                if ($state->failInfo ?? false) { $state->failInfo = false; throw new RuntimeException('Synthetic readback failure'); }
                $r += ['created' => gmdate('Y-m-d'), 'expires' => $state->expiry, 'status' => 'Active', 'locked' => $state->locked,
                    'private' => 'No', 'auto_renew' => 'No', 'traffic_type' => 'Custom DNS', 'email_verification_required' => 'No', 'portfolio' => '', 'forward_url' => 'N/A', 'forward_type' => 'N/A', 'nameservers' => [['nameserver' => 'ns1.namesilo.com', 'position' => 1], ['nameserver' => 'ns2.namesilo.com', 'position' => 2]],
                    'contact_ids' => array_fill_keys(['registrant','administrative','technical','billing'], '101')];
                if (getenv('OTE_SELF_TEST_SCENARIO') === 'unknown-absent') { $r = ['code' => 200, 'detail' => 'synthetic']; }
            } elseif ($command === 'listOrders') { $r['order'] = [['order_number' => '901', 'order_date' => gmdate('Y-m-d H:i:s'), 'method' => 'Account Funds', 'total' => '8.95']];
                if (getenv('OTE_SELF_TEST_SCENARIO') === 'unknown-absent') { $r['order'] = []; }
            }
            elseif ($command === 'orderDetails') {
                $r += ['order_number' => '901', 'order_date' => gmdate('Y-m-d H:i:s'), 'method' => 'Account Funds', 'total' => '8.95',
                    'order_details' => [['description' => $wire->domain.' - '.($state->renewed ? 'renewal' : 'registration'), 'years_qty' => '1', 'price' => '8.95', 'subtotal' => getenv('OTE_SELF_TEST_SCENARIO') === 'malformed-registration' ? '9.95' : '8.95', 'status' => 'Processed']]];
            } elseif ($command === 'domainUnlock') { $state->locked = 'No'; }
            elseif ($command === 'domainLock') { $state->locked = 'Yes'; }
            elseif ($command === 'removeAutoRenewal') { $r['code'] = 251; }
            elseif ($command === 'dnsAddRecord') { $state->record = true; $r['record_id'] = 'txt-record'; }
            elseif ($command === 'dnsDeleteRecord') { $state->record = false; $state->failDns = getenv('OTE_SELF_TEST_SCENARIO') === 'failed-readbacks'; }
            elseif ($command === 'dnsListRecords') {
                if ($state->failDns ?? false) { $state->failDns = false; }
                else { $r['resource_record'] = $state->record ? [['record_id' => 'txt-record', 'type' => 'TXT', 'host' => '_contract', 'value' => 'synthetic-contract', 'ttl' => 3600, 'distance' => 0]] : []; }
            } elseif ($command === 'changeNameServers') { $state->failInfo = getenv('OTE_SELF_TEST_SCENARIO') === 'failed-readbacks'; }
            elseif ($command !== 'changeNameServers') { throw new RuntimeException('Unexpected offline command'); }
            return new TransportResponse(200, json_encode(['request' => ['operation' => $command, 'ip' => '192.0.2.1'], 'reply' => $r], JSON_THROW_ON_ERROR));
        }, static fn () => 21, in_array(getenv('OTE_SELF_TEST_SCENARIO'), ['limited-budget', 'short-budget'], true) ? (int) getenv('OTE_REQUEST_LIMIT') : 60);
    if (in_array(getenv('OTE_SELF_TEST_SCENARIO'), ['limited-budget', 'short-budget'], true)) {
        // Reserve the same six requests as write mode's initial read phase.
        for ($i = 0; $i < 6; $i++) { $wire->raw('https://ote.namesilo.com/api', 'getAccountBalance', []); }
    }
    $mode = $profileRead ? '--profile-read' : '--write';
    try { require __DIR__.'/registration.php'; }
    catch (RuntimeException $error) {
        if ($scenario !== 'profile-read-transport') { throw $error; }
        same('OTE exchange unresolved.', $error->getMessage(), 'Transport failure remains a transport mismatch');
    }
    $nativeSuccessful = false; // Require completion of the assertions below after the registration helper returns.
    if ($mode === '--profile-read') {
        same($scenario === 'profile-read-claim' ? 0 : 1, $wire->receipt['request_count'], 'Read-only probe has bounded reads');
        expect($op->fresh()->state !== 'running', 'Read-only probe always finishes its synthetic claim');
        same(false, $wire->attempted('contactAdd'), 'Read-only probe creates no contact');
        same(false, $wire->attempted('registerDomain'), 'Read-only probe cannot purchase');
        if ($scenario === 'profile-read-claim') {
            same(false, $wire->receipt['checks']['profile_claim'], 'Claim refusal has its own closed predicate');
            same('blocked_profile_claim', $wire->receipt['checks']['registration'], 'Claim refusal has its own result');
            expect(!array_key_exists('profile_authority_before', $wire->receipt['checks']), 'Claim refusal never diagnoses authority');
        } elseif ($scenario === 'profile-read-transport') {
            same(true, $wire->receipt['requests'][0]['transport_failed'], 'Transport mismatch retained');
        } else {
            same($scenario !== 'profile-read-contact', $wire->receipt['profile_inventory']['rows'][0]['identity_match'], 'Frozen synthetic identity compared');
            same(!in_array($scenario, ['profile-read-contact', 'profile-read-duplicate'], true), $wire->receipt['checks']['profile_read_gate'], 'Read-only identity gate needs exactly one match');
            same($scenario !== 'profile-read-authority', $wire->receipt['checks']['profile_authority_after'], 'Current authority checked after read');
            same(!in_array($scenario, ['profile-read-status', 'profile-read-malformed'], true), $wire->receipt['checks']['profile_contact_list_success'], 'Reply status independently diagnosed');
        }
    } elseif (in_array(getenv('OTE_SELF_TEST_SCENARIO'), ['missing-premium', 'premium-one'], true)) {
        same('held', $wire->receipt['checks']['registration_engine_state'], 'Unknown/premium availability holds standard registration');
        same(false, $wire->attempted('registerDomain'), 'Unknown/premium singleton cannot purchase');
        same(false, $wire->attempted('renewDomain'), 'Unknown/premium singleton cannot renew');
    } else {
    same(getenv('OTE_SELF_TEST_SCENARIO') === 'unknown-registered' ? 'reconciled_in_account' : 'accepted_via_real_admitted_protocol', $wire->receipt['checks']['registration'], 'Real registration path exercised offline');
    same(true, $wire->receipt['checks']['charge_evidence_shape_matches'], 'Charge correlation exercised offline');
    if (in_array(getenv('OTE_SELF_TEST_SCENARIO'), ['limited-budget', 'short-budget'], true)) {
        expect($wire->receipt['request_count'] <= (int) getenv('OTE_REQUEST_LIMIT'), 'Constrained write never exceeds remaining slice allowance');
        if (getenv('OTE_SELF_TEST_SCENARIO') === 'short-budget') {
            same('skipped_budget', $wire->receipt['checks']['remove_auto_renew_readback'], 'Management reserves its complete readback group');
        }
        same('skipped_budget', $wire->receipt['checks']['txt_sequence'], 'TXT explicitly omitted under cap');
        same('skipped_budget', $wire->receipt['checks']['renew_probe'], 'Renewal explicitly omitted under cap');
        same(false, $wire->attempted('renewDomain'), 'No renewal without reserve');
    } else {
    same(true, $wire->receipt['checks']['renew_added_one_year'], 'One renewal exercised offline');
    same(true, $wire->receipt['checks']['renewal_charge_evidence_shape_matches'], 'Renewal charge correlation exercised offline');
    same(getenv('OTE_SELF_TEST_SCENARIO') !== 'failed-readbacks', $wire->receipt['checks']['txt_deleted'], 'TXT cleanup requires observed readback');
    }
    if (getenv('OTE_SELF_TEST_SCENARIO') === 'failed-readbacks') {
        same(false, $wire->receipt['checks']['nameservers_readback'], 'Failed read cannot reuse pre-change nameservers');
    }
    elseif (getenv('OTE_SELF_TEST_SCENARIO') !== 'unknown-registered') { same([], $wire->receipt['mismatches'], 'Native fake matches all contract projections'); }
    else {
        same('registered_in_account', $wire->receipt['reconciliation']['category'], 'Unknown reconciles before continuing same sequence');
        expect($wire->receipt['mismatches'] !== [], 'Unknown purchase retains expected mismatches');
        same([], array_values(array_filter($wire->receipt['mismatches'], static fn ($row) => $row['command'] !== 'registerDomain')), 'Reconciliation and renewal have no unrelated mismatches');
    }
    }
    $nativeSuccessful = true; // All expected native refusals and their receipt assertions completed.
}
$summary = ['self_test' => 'passed', 'real_provider_calls' => 0, 'native_protocol_exercised' => (bool) getenv('OTE_SELF_TEST_NATIVE')];
if ($wire) { $wire->receipt += $summary; $wire->save(); }
else { file_put_contents($output.'/receipt.json', json_encode($summary, JSON_PRETTY_PRINT)."\n"); }
