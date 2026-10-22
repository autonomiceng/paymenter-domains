<?php
// Synthetic injected HTTPS exchanges only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Paymenter\Extensions\Servers\Domains\Dns\{DohFailure,DohEndpoint,DohTransport,DohDnsReader,FallbackDnsReader,NativeDnsReader,DnsQuery,DnsContext};
$q = new DnsQuery('example.com', 'A'); $endpoint = new DohEndpoint(DohEndpoint::URL, DohEndpoint::IP);
$response = ['Status' => 0, 'Answer' => [['name' => 'example.com.', 'type' => 1, 'TTL' => 60, 'data' => '192.0.2.1']]];
$dohCalls = $nativeCalls = 0; $options = []; $mode = 'normal';
$transport = new DohTransport(function ($request, $opts) use (&$dohCalls, &$options, &$response, &$mode) {
    $dohCalls++; $options = $opts; same('cloudflare-dns.com', $request->getUri()->getHost(), 'Fixed configured endpoint');
    if ($mode === 'real_timeout') { throw new \GuzzleHttp\Exception\ConnectException('timeout', $request, null, ['errno' => 28]); }
    if ($mode === 'real_tls') { throw new \GuzzleHttp\Exception\RequestException('tls', $request, null, null, ['errno' => 60]); }
    if (in_array($mode, ['connect', 'timeout', 'tls', 'unavailable'], true)) { throw new DohFailure($mode); }
    $generated = !array_key_exists('Question', $response); if ($generated) { $response['Question'] = [['name' => 'example.com.']]; parse_str($request->getUri()->getQuery(), $query); $response['Question'][0]['type'] = ['A'=>1,'NS'=>2,'CNAME'=>5,'MX'=>15,'AAAA'=>28][$query['type']]; }
    $mock = new MockHandler([new Response($mode === 'http' ? 503 : 200, [], $mode === 'malformed' ? '{' : json_encode($response))]);
    if ($generated) { unset($response['Question']); }
    return $mock($request, $opts);
});
$doh = new DohDnsReader($endpoint, $transport);
$reader = new FallbackDnsReader($doh, new NativeDnsReader(function () use (&$nativeCalls) { $nativeCalls++; return []; }));
$c = new DnsContext; $a = $reader->read($q, $c);
same('rrset', $a->state, 'Exact owner rrset'); same(['192.0.2.1'], $a->values(), 'Address retained');
same('doh', $a->facts()['source'], 'DoH source recorded'); same(1, $c->used(), 'One slot');
same(false, $options['allow_redirects'], 'No redirects'); same('', $options['proxy'], 'No inherited proxies');
same(1, $options['connect_timeout'], 'One second connect'); same(2, $options['timeout'], 'Two second total');
same(['cloudflare-dns.com:443:1.1.1.1'], $options['curl'][CURLOPT_RESOLVE], 'Pinned public bootstrap, no endpoint DNS');
same(false, $options['decode_content'], 'No decompression'); same(['https'], $options['protocols'], 'HTTPS only');
foreach ([0, 1, 2, 3, 4, 5, 9, 16, 4095] as $rcode) {
    $response = ['Status' => $rcode]; $before = $nativeCalls;
    $a = $reader->read($q, new DnsContext);
    same($rcode === 0 || $rcode === 3 ? 'negative' : 'unknown', $a->state, 'RCODE state '.$rcode);
    same(match ($rcode) { 0 => 'nodata', 3 => 'nxdomain', default => 'dns_rcode_'.$rcode }, $a->code, 'RCODE preserved');
    same($before, $nativeCalls, 'Valid RCODE never falls back '.$rcode);
}
foreach (['connect', 'timeout', 'tls', 'unavailable', 'real_timeout', 'real_tls', 'http', 'malformed'] as $kind) {
    $mode = $kind; $c = new DnsContext; $before = $nativeCalls; $a = $reader->read($q, $c);
    same($before + 1, $nativeCalls, 'Transport failure falls back '.$kind); same(1, $c->used(), 'Fallback reuses budget slot');
    same('unknown', $a->state, 'Native empty is not proof'); same('ambiguous_empty', $a->code, 'Native ambiguity retained');
    same('native_fallback', $a->source, 'Native source recorded');
    same(match ($kind) { 'http' => 'http_status', 'real_timeout' => 'timeout', 'real_tls' => 'tls', default => $kind }, $a->fallbackReason, 'Transport reason retained');
}
$mode = 'connect'; $c = new DnsContext; $before = $dohCalls;
for ($i = 0; $i < 10; $i++) { $reader->read($q, $c); }
same($before + 2, $dohCalls, 'Two failures trip circuit for rest of job'); same(10, $c->used(), 'Ten slots with fallback');
$before = [$nativeCalls, $dohCalls]; $reader->read($q, $c); same($before, [$nativeCalls, $dohCalls], 'No eleventh query');
$reader->read($q, new DnsContext); same($before[1] + 1, $dohCalls, 'New job resets breaker');
$mode = 'normal';
foreach ([['Status' => '0'], ['Status' => -1], ['Status' => 4096], ['Status' => 0, 'TC' => true], ['Status' => 0, 'TC' => null], ['Status' => 0, 'Answer' => 'bad'],
    ['Status' => 0, 'Question' => [['name' => 'unrelated-placeholder.com', 'type' => 1]]],
    ['Status' => 0, 'Answer' => [['name' => 'example.com', 'type' => '1', 'TTL' => 1, 'data' => '192.0.2.1']]],
    ['Status' => 0, 'Question' => null]] as $bad) {
    $response = $bad; same('transport_malformed', $doh->read($q, new DnsContext)->code, 'Malformed body is transport failure');
}
$response = ['Status' => 0, 'Answer' => [
    ['name' => 'example.com.', 'type' => 5, 'TTL' => 60, 'data' => 'target.example.net.'],
    ['name' => 'target.example.net.', 'type' => 1, 'TTL' => 60, 'data' => '192.0.2.1']]];
same('cname_requires_walk', $reader->read($q, new DnsContext)->code, 'Alias address is not owner rrset or NODATA');
$response['Answer'] = [$response['Answer'][0]];
same(['target.example.net'], $reader->read(new DnsQuery('example.com', 'CNAME'), new DnsContext)->values(), 'Direct CNAME for collector chain walk');
foreach ([['NS', 2, 'ns1.example.net.'], ['AAAA', 28, '2001:db8::1'], ['MX', 15, '10 mail.example.net.'], ['MX', 15, '0 .']] as [$type, $wire, $data]) {
    $response = ['Status' => 0, 'Answer' => [['name' => 'example.com.', 'type' => $wire, 'TTL' => 0, 'data' => $data]]];
    same('rrset', $doh->read(new DnsQuery('example.com', $type), new DnsContext)->state, 'Supported wire type '.$type);
}
$time = 0.0; $c = new DnsContext(function () use (&$time) { return $time; }); $time = 44.75;
$slow = new DohDnsReader($endpoint, new DohTransport(function ($request, $opts) use (&$time) {
    same(0.25, $opts['timeout'], 'Remaining job deadline bounds HTTP'); same(0.25, $opts['connect_timeout'], 'Remaining bounds connect');
    $time = 45.0; return (new MockHandler([new Response(200, [], '{"Status":0,"Question":[{"name":"example.com","type":1}]}')]))($request, $opts);
}));
$before = $nativeCalls; $bounded = new FallbackDnsReader($slow, new NativeDnsReader(function () use (&$nativeCalls) { $nativeCalls++; return []; }));
same('deadline', $bounded->read($q, $c)->code, 'Discard over-deadline result'); same($before, $nativeCalls, 'No fallback past deadline');
same('deadline', $reader->read($q, $c)->code, 'Expired job starts no exchange');
$response = ['Status' => 0, 'Answer' => array_fill(0, 101, ['name' => 'example.com', 'type' => 1, 'TTL' => 1, 'data' => '192.0.2.1'])];
$before = $nativeCalls; same('invalid_answer', $reader->read($q, new DnsContext)->code, 'Large zone RRset stays unknown'); same($before, $nativeCalls, 'Large zone never causes fallback');
$response = ['Status' => 0, 'Answer' => [['name' => 'unrelated-placeholder.com', 'type' => 1, 'TTL' => 1, 'data' => '192.0.2.1']]];
$before = $nativeCalls; same('invalid_answer', $reader->read($q, new DnsContext)->code, 'Unrelated semantic data stays DoH unknown'); same($before, $nativeCalls, 'Zone content never causes fallback');
foreach (['*.example.com', '_service.example.com', '.'] as $owner) {
    $response = ['Status' => 0, 'Answer' => [['name' => $owner, 'type' => 1, 'TTL' => 1, 'data' => '192.0.2.1']]];
    $before = $nativeCalls; same('invalid_answer', $reader->read($q, new DnsContext)->code, 'Unsupported owner stays unknown'); same($before, $nativeCalls, 'Unsupported owner never falls back');
}
foreach (['{"Status":0,"Question":[{"name":"example.com","type":1}],"Answer":{}}', '{"Status":0,"Question":{"0":{"name":"example.com","type":1}}}'] as $json) {
    $bad = new DohDnsReader($endpoint, new DohTransport(fn($request, $opts) => (new MockHandler([new Response(200, [], $json)]))($request, $opts)));
    same('transport_malformed', $bad->read($q, new DnsContext)->code, 'JSON objects are not arrays');
}
$response = ['Status' => 3, 'Answer' => [['name' => 'example.com.', 'type' => 5, 'TTL' => 60, 'data' => 'missing.example.net.']]];
same('cname_requires_walk', $reader->read($q, new DnsContext)->code, 'Target NXDOMAIN does not prove alias absent');
foreach (['chunked', 'length'] as $overflow) {
    $large = new DohDnsReader($endpoint, new DohTransport(function ($request, $opts) use ($overflow) {
        if ($overflow === 'length') { $opts['on_headers'](new Response(200, ['Content-Length' => '65537'])); }
        else { same(65536, $opts['sink']->write(str_repeat('x', 65536)), 'Sink accepts limit'); same(0, $opts['sink']->write('x'), 'Sink aborts next byte'); }
        return (new MockHandler([new Response(200, [], '{}')]))($request, $opts);
    }));
    $before = $nativeCalls; $fallback = new FallbackDnsReader($large, new NativeDnsReader(function () use (&$nativeCalls) { $nativeCalls++; return []; }));
    same('too_large', $fallback->read($q, new DnsContext)->fallbackReason, 'Oversized response falls back '.$overflow); same($before + 1, $nativeCalls, 'One overflow fallback');
}
$response = ['Status' => 0, 'Comment' => str_repeat('x', 65536)];
same('transport_too_large', $doh->read($q, new DnsContext)->code, 'Bounded oversized response');
$time = 0.0; $c = new DnsContext(function () use (&$time) { return $time; }); $time = 44.9995; $before = [$dohCalls, $nativeCalls];
same('deadline', $reader->read($q, $c)->code, 'Submillisecond deadline never falls back'); same($before, [$dohCalls, $nativeCalls], 'No near-deadline I/O');
same('deadline', (new FallbackDnsReader(null, new NativeDnsReader(function () { throw new LogicException('No near-deadline native call'); })))->read($q, $c)->code, 'Native-only near deadline is bounded');
$c = new DnsContext; $c->reserve(); $c->fallback(fn() => (new NativeDnsReader(fn() => []))->read($q, $c));
try { $c->fallback(fn() => (new NativeDnsReader(fn() => []))->read($q, $c)); expect(false, 'A slot cannot be reused twice'); } catch (LogicException) {}
foreach ([['http://resolver.example.org/dns-query', '1.1.1.1'], ['https://127.0.0.1/dns-query', '1.1.1.1'],
    ['https://127.1/dns-query', '1.1.1.1'], ['https://0177.0.0.1/dns-query', '1.1.1.1'], ['https://0x7f.0.0.1/dns-query', '1.1.1.1'],
    ['https://resolver.local/dns-query', '1.1.1.1'], ['https://x..org/dns-query', '1.1.1.1'],
    ['https://user@resolver-placeholder.org/dns-query', '1.1.1.1'], ['https://resolver-placeholder.org/dns-query?x=1', '1.1.1.1'],
    ['https://resolver-placeholder.org:444/dns-query', '1.1.1.1'], [DohEndpoint::URL, '127.0.0.1'], [DohEndpoint::URL, '192.168.1.1'],
    [DohEndpoint::URL, '169.254.169.254'], [DohEndpoint::URL, '::1'], [DohEndpoint::URL, 'fd00::1']] as [$url, $ip]) {
    try { new DohEndpoint($url, $ip); expect(false, 'Unsafe endpoint refused'); } catch (InvalidArgumentException) {}
}
echo "DoH RCODEs, strict rows, transport fallback, sources, breaker, budget, deadline and public endpoint passed.\n";

$nativeSuccessful = true;
