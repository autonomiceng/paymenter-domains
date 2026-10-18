<?php
// Synthetic provider responses only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
// Exercise redaction even when the host includes arguments in exception traces.
ini_set('zend.exception_ignore_args', '0');

use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DomainName, Environment, Outcome, RegistrarClient,
    RegistrarRequest as Request, PurchaseMapping as Purchase, ResponseParser, RejectionScope, RequestContext, Transport, TransportResponse, UsNexus, CurlTransport};

final class FakeTransport implements Transport
{
    public int $calls = 0;
    public ?RequestContext $context = null;
    public ?Environment $environment = null;
    public function __construct(private string $body, private int $status = 200, private bool $fail = false) {}
    public function send(Environment $environment, RequestContext $context, Request $request,
        string $apiKey): TransportResponse
    {
        ++$this->calls;
        $this->context = $context;
        $this->environment = $environment;
        if ($this->fail) { throw new RuntimeException('Synthetic timeout https://example.com/?key='.$apiKey); }
        return new TransportResponse($this->status, $this->body);
    }
}

function throws(callable $call, string $message): Throwable
{
    try { $call(); } catch (Throwable $error) { return $error; }
    throw new RuntimeException($message);
}

function rendered(mixed $value): string
{
    ob_start(); var_dump($value); $dump = ob_get_clean();
    return json_encode($value, JSON_THROW_ON_ERROR).' '.serialize($value).' '.var_export($value, true)
        .' '.print_r($value, true).' '.$dump.(is_object($value) && method_exists($value, '__toString') ? (string) $value : '');
}

$fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true, 32, JSON_THROW_ON_ERROR);
$ns = ['ns1.example.net', 'ns2.example.net'];
$requests = [Request::availability(['example.com', 'example.net', 'example.org'], false), Request::availability(['example.com', 'example.net'], true),
    Request::prices(), Request::domainInfo('example.com'), Request::balance(), Request::listOrders(),
    Request::orderDetails('123')];
$purchases = [Purchase::register('example.com', 1, false, '123', $ns),
    Purchase::transfer('example.com', 'synthetic-auth', false, '123', $ns)];
$parser = new ResponseParser;

foreach ($requests as $request) {
    $fake = new FakeTransport(json_encode($fixtures[$request->command]));
    $client = new RegistrarClient('test-api-key', Environment::Sandbox, $fake);
    $result = $client->execute($request, RequestContext::Background);
    same(Outcome::Success, $result->outcome, 'Synthetic command shape accepted: '.$request->command);
    same(300, $result->code, 'Success code');
    same(1, $fake->calls, 'One transport attempt');
    same(RequestContext::Background, $fake->context, 'Explicit background context forwarded');
    same(Environment::Sandbox, $fake->environment, 'Explicit environment forwarded');
    same(null, $result->value('detail'), 'Provider diagnostics omitted');
    if ($request->command === 'orderDetails') {
        same('Processed', $result->value('order_details.0.status'), 'Order evidence retained');
    }
}

foreach ($purchases as $mapping) {
    $result = $parser->parse(json_encode($fixtures[$mapping->command]), $mapping, 'test-api-key');
    same(Outcome::Success, $result->outcome, 'Pure purchase response interpretation');
    same('12.34', $result->value('order_amount'), 'Canonical decimal amount');
}

// Independent expected table from api-contract.md; irrelevant refusals stay unknown.
$rejected = [101,102,103,104,105,106,107,108,109,110,111,112,113,114,116,117,118,119,120];
$otherRefusals = [200,250,251,252,253,254,255,256,263,264,265,266,267];
$unknown = [115,201,210,261,262,268,280,400];
foreach ([...$rejected, ...$otherRefusals, ...$unknown, 300,301,302,999] as $code) {
    foreach ([$code, (string) $code] as $wireCode) {
        $fixture = $fixtures['getAccountBalance'];
        $fixture['reply']['code'] = $wireCode;
        $fake = new FakeTransport(json_encode($fixture));
        $result = (new RegistrarClient('test-api-key', Environment::Live, $fake))->execute(Request::balance(), RequestContext::Interactive);
        $expected = in_array($code, $rejected, true) ? Outcome::Rejected : ($code === 300 ? Outcome::Success : Outcome::Unknown);
        same($expected, $result->outcome, 'Documented classification '.$code);
        same($code, $result->code, 'Bounded numeric codes retained');
        if ($code === 119) { same(RejectionScope::Funds, $result->scope, 'Funds refusal scope'); }
        if ($code === 110) { same(RejectionScope::Account, $result->scope, 'Account refusal scope'); }
        same(1, $fake->calls, 'Never retry mapped response');
    }
}
foreach ([301,302,119,262,400] as $code) {
    $fixture = $fixtures['registerDomain'];
    $fixture['reply']['code'] = $code;
    $result = $parser->parse(json_encode($fixture), $purchases[0], 'test-api-key');
    same(in_array($code, [301,302], true) ? Outcome::Fallback : Outcome::Unknown, $result->outcome,
        'Purchased fallback or contradictory charge evidence never rejected');
    if (in_array($code, [301,302], true)) {
        same('example.com', $result->value('domain'), 'Fallback retains correlated domain');
        same('12.34', $result->value('order_amount'), 'Fallback retains exact charge evidence');
    }
}

foreach (['', '{', 'null', '[]', str_repeat('x', CurlTransport::BODY_LIMIT + 1),
    '{"namesilo":{"reply":{"code":300,"detail":"success"}}}',
    '{"request":{"operation":"getPrices"},"reply":{"code":300,"detail":"success","balance":1}}',
    '{"request":{"operation":"getAccountBalance"},"reply":{"code":300,"detail":"success"}}',
    '{"request":{"operation":"getAccountBalance"},"reply":{"code":300.1,"detail":"success","balance":1}}',
    '{"request":{"operation":"getAccountBalance"},"reply":{"code":300,"detail":"success","balance":-1}}'] as $body) {
    $result = (new RegistrarClient('test-api-key', Environment::Sandbox, new FakeTransport($body)))
        ->execute(Request::balance(), RequestContext::Interactive);
    same(Outcome::Unknown, $result->outcome, 'Malformed, mismatched or oversized result is unknown');
}
foreach ([301,302,500] as $http) {
    $fake = new FakeTransport(json_encode($fixtures['getAccountBalance']), $http);
    same(Outcome::Unknown, (new RegistrarClient('test-api-key', Environment::Live, $fake))
        ->execute(Request::balance(), RequestContext::Interactive)->outcome, 'HTTP status not API reply code');
    same(1, $fake->calls, 'HTTP error/redirect never retried');
}
$timeout = new FakeTransport('', 200, true);
$result = (new RegistrarClient('test-api-key', Environment::Live, $timeout))->execute(Request::balance(), RequestContext::Interactive);
same(Outcome::Unknown, $result->outcome, 'Timeout is unknown');
same(1, $timeout->calls, 'Timeout never retried');

foreach ([254,250,200,268] as $code) {
    $body = ['request' => ['operation' => 'registerDomain'], 'reply' => ['code' => $code, 'detail' => 'synthetic']];
    same(Outcome::Unknown, $parser->parse(json_encode($body), $purchases[0], 'test-api-key')->outcome, 'Post-purchase refusal not definitive');
}
foreach (['missing', 'duplicate', 'extra'] as $case) {
    $body = $fixtures['checkRegisterAvailability'];
    if ($case === 'missing') { unset($body['reply']['unavailable']); }
    if ($case === 'duplicate') { $body['reply']['unavailable']['domain'] = 'example.com'; }
    if ($case === 'extra') { $body['reply']['unavailable']['domain'] = 'example.us'; }
    same(Outcome::Unknown, $parser->parse(json_encode($body), $requests[0], 'test-api-key')->outcome, 'Availability correlation fails closed');
}
$body = $fixtures['checkTransferAvailability'];
unset($body['reply']['available'][0]['premium']);
$result = $parser->parse(json_encode($body), $requests[1], 'test-api-key');
same(null, $result->value('available.0.premium'), 'Missing premium is explicitly unknown');
$body = $fixtures['orderDetails'];
$body['reply']['order_number'] = 123;
$result = $parser->parse(json_encode($body), Request::orderDetails('123'), 'test-api-key');
same(Outcome::Success, $result->outcome, 'Numeric order echo normalized');
same('123', $result->value('order_number'), 'Order identifier is canonical string');
$body = $fixtures['registerDomain'];
$body['reply']['code'] = 301;
$body['reply']['domain'] = 'example.net';
same(Outcome::Unknown, $parser->parse(json_encode($body), $purchases[0], 'test-api-key')->outcome, 'Uncorrelated fallback stays unknown');

foreach (['9007199254740993.25' => Outcome::Success, '12.345' => Outcome::Unknown, '1e2' => Outcome::Unknown] as $amount => $expected) {
    $body = '{"request":{"operation":"getAccountBalance"},"reply":{"code":300,"detail":"success","balance":'.$amount.'}}';
    $result = $parser->parse($body, Request::balance(), 'test-api-key');
    same($expected, $result->outcome, 'Exact numeric amount syntax');
    if ($expected === Outcome::Success) { same($amount, $result->value('balance'), 'Amount never passes through float'); }
}

$key = 'test-api-key+/&';
$fixture = $fixtures['getDomainInfo'];
$fixture['reply']['detail'] = 'https://example.com/?key='.$key;
$fixture['reply']['portfolio'] = $key.' '.rawurlencode($key).' '.base64_encode($key).' https://example.com/?key='.urlencode($key);
$fixture['reply']['api_key'] = $key;
$fixture['reply']['unexpected'] = 'untrusted payload';
$fixture['reply']['nameservers'][0]['unexpected'] = 'untrusted payload';
$fixture['reply']['nameservers'][0]['nameserver'] = $key;
$fixture['reply']['contact_ids'][$key] = $key;
$client = new RegistrarClient($key, Environment::Sandbox, new FakeTransport(json_encode($fixture)));
$result = $client->execute(Request::domainInfo('example.com'), RequestContext::Interactive);
ob_start(); var_dump((array) $client, (array) $result); $castDump = ob_get_clean();
$serialized = $castDump.' '.print_r((array) $client, true).' '.rendered($result).' '.rendered($client).' '.json_encode($result->value('contact_ids'))
    .' '.$result->value('portfolio').' '.json_encode($result->value('nameservers'));
foreach ([$key, rawurlencode($key), urlencode($key), base64_encode($key)] as $secret) {
    expect(!str_contains($serialized, $secret), 'Credential absent from stored data and diagnostic forms');
}
same(null, $result->value('api_key'), 'Key field dropped');
same(null, $result->value('unexpected'), 'Unexpected response field dropped');
same(null, $result->value('nameservers.0.unexpected'), 'Unexpected nested field dropped');
expect(!str_contains(rendered($result), 'Synthetic'), 'Diagnostic forms omit account data');
same('No', $result->value('auto_renew'), 'Useful sanitized fields accessible');
same('fallback', $result->value('missing', 'fallback'), 'Explicit fallback accessor');
foreach (['portfolio', 'nameservers'] as $field) {
    $malformed = $fixtures['getDomainInfo'];
    if ($field === 'portfolio') { $malformed['reply'][$field] = ['customer' => ['email' => 'synthetic@example.com']]; }
    else { $malformed['reply'][$field][0]['nameserver'] = ['customer' => ['email' => 'synthetic@example.com']]; }
    $malformedResult = (new RegistrarClient($key, Environment::Sandbox, new FakeTransport(json_encode($malformed))))
        ->execute(Request::domainInfo('example.com'), RequestContext::Interactive);
    same(Outcome::Unknown, $malformedResult->outcome, 'Nested value in scalar field rejected');
}
$numeric = $fixtures['getAccountBalance'];
$numeric['reply']['balance'] = 12345;
$numericResult = (new RegistrarClient('12345', Environment::Sandbox, new FakeTransport(json_encode($numeric))))
    ->execute(Request::balance(), RequestContext::Interactive);
expect(!str_contains(rendered($numericResult).' '.json_encode($numericResult->value('balance')), '12345'), 'Numeric credential echo redacted');
$response = new TransportResponse(200, $key);
expect(!str_contains(rendered($response), $key), 'Transport response diagnostics omit body');
$error = throws(fn () => new RegistrarClient("test-api-key\n", Environment::Live, new FakeTransport('')), 'Invalid key must fail');
expect(!str_contains($error->getMessage().print_r($error->getTrace(), true).$error->getTraceAsString().json_encode($error->getTrace(), JSON_THROW_ON_ERROR), 'test-api-key'), 'Credential validation exception redacted');

foreach (['EXAMPLE.COM', ' example.com '] as $domain) {
    same('example.com', DomainName::parse($domain)['domain'], 'ASCII normalization');
}
foreach (['https://example.com', 'example.com.', 'a.example.com', 'éxample.com', 'xn--example.com',
    'ab--example.com', '-example.com', 'example-.com', 'example..com', 'example.invalid',
    str_repeat('a', 64).'.com', "example.com\0"] as $domain) {
    throws(fn () => DomainName::parse($domain), 'Invalid/IDN domain rejected');
}
same('example.org', Request::availability(['Example.ORG', 'example.org'], false)->parameters()['domains'], 'Deduplicated domains');
same(['registration_domains' => 2], Request::prices(2)->parameters(), 'Account pricing quantity');
same([], Request::prices()->parameters(), 'No retail/default quantity flag sent');
same(['date_from' => '2026-01-01', 'date_to' => '2026-02-01 12:34:56'],
    Request::listOrders('2026-01-01', '2026-02-01 12:34:56')->parameters(), 'Date filter mapping');
foreach ([[], array_fill(0, 201, 'example.com'), ['a' => 'example.com'], [123]] as $domains) {
    throws(fn () => Request::availability($domains, false), 'Invalid search bounds rejected');
}
foreach (['2026-02-30', '2026-01-01 25:00:00', '2026-1-1', 'invalid'] as $date) {
    throws(fn () => Request::listOrders($date), 'Invalid calendar filter rejected');
}
throws(fn () => Request::listOrders('2026-02-01', '2026-01-01'), 'Reversed filter rejected');
throws(fn () => Request::orderDetails('0'), 'Invalid order ID rejected');
throws(fn () => Request::prices(0), 'Invalid quantity rejected');
foreach ([false, true] as $privacy) {
    foreach ([Purchase::register('example.com', 10, $privacy, '123', $ns),
        Purchase::transfer('example.com', 'synthetic&+auth', $privacy, '123', $ns),
        Purchase::renew('example.com', 1, $privacy)] as $request) {
        same(0, $request->parameters()['auto_renew'], 'Registrar auto-renew explicitly off for all purchase mappings');
        same((int) $privacy, $request->parameters()['private'], 'Explicit privacy choice for all mappings');
        expect(!isset($request->parameters()['payment_id']), 'Account funds only');
    }
}
$transfer = Purchase::transfer('example.com', 'synthetic&+auth', false, '123', $ns);
same('base64:'.base64_encode('synthetic&+auth'), $transfer->parameters()['auth'], 'Auth encoding');
expect(!str_contains(rendered($transfer), 'synthetic&+auth'), 'Request debug/serialization omit auth');
$nexus = new UsNexus('C11', 'P3');
same('C11', Purchase::register('example.us', 1, false, '123', $ns, $nexus)->parameters()['usnc'], '.us category mapped');
same('P3', Purchase::transfer('example.us', 'synthetic-auth', false, '123', $ns, $nexus)->parameters()['usap'], '.us purpose mapped');
foreach ([fn () => Purchase::register('example.us', 1, false, '123', $ns),
    fn () => Purchase::register('example.us', 1, true, '123', $ns, $nexus),
    fn () => Purchase::register('example.com', 0, false, '123', $ns),
    fn () => Purchase::renew('example.com', 11, false),
    fn () => Purchase::register('example.com', 1, false, '123', ['ns1.example.net']),
    fn () => Purchase::register('example.com', 1, false, '123', ['192.0.2.1', '192.0.2.2']),
    fn () => Purchase::register('example.com', 1, false, '123', ['ns1.example.com', 'ns2.example.com']),
    fn () => Purchase::register('example.com', 1, false, '123', ['192.0.2.999', 'ns2.example.net']),
    fn () => Purchase::register('example.com', 1, false, '123', ['ns1.example.net', 'NS1.example.net']),
    fn () => Purchase::register('example.com', 1, false, '123', ['bad/host.example.net', 'ns2.example.net']),
    fn () => Purchase::register('example.com', 1, false, 'not-id', $ns),
    fn () => Purchase::transfer('example.com', '', false, '123', $ns),
    fn () => new UsNexus('C00', 'P3')] as $invalid) {
    throws($invalid, 'Invalid purchase parameters rejected');
}
$fake = new FakeTransport('');
$client = new RegistrarClient('test-api-key', Environment::Sandbox, $fake);
foreach ([...$purchases, Purchase::renew('example.com', 1, false)] as $mapping) {
    $error = throws(fn () => $client->execute($mapping, RequestContext::Background), 'Purchase mapping has no dispatch path');
    expect($error instanceof TypeError, 'Purchase dispatch structurally refused');
}
same(0, $fake->calls, 'Purchase mappings cannot dispatch');
echo "API: synthetic shapes, all 43 documented codes, redaction, bounds and builders passed.\n";
