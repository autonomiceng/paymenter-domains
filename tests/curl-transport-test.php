<?php
// Synthetic cURL functions: no sockets. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;
require dirname(__FILE__).'/bootstrap.php';
// Exercise redaction even when the host includes arguments in exception traces.
ini_set('zend.exception_ignore_args', '0');

function curl_init(): object { return new \stdClass; }
function curl_setopt_array($handle, array $options): bool { $GLOBALS['curlFake']['options'] = $options; return true; }
function curl_exec($handle): bool
{
    ++$GLOBALS['curlFake']['calls'];
    if ($GLOBALS['curlFake']['timeout']) { throw new \RuntimeException('Synthetic timeout '.$GLOBALS['curlFake']['options'][CURLOPT_URL]); }
    foreach ($GLOBALS['curlFake']['chunks'] as $chunk) {
        if (($GLOBALS['curlFake']['options'][CURLOPT_WRITEFUNCTION])($handle, $chunk) !== strlen($chunk)) { return false; }
    }
    return true;
}
function curl_getinfo($handle, int $option): int { return $GLOBALS['curlFake']['status']; }
function curl_close($handle): void { ++$GLOBALS['curlFake']['closed']; }
function fake(array $overrides = []): void
{
    $GLOBALS['curlFake'] = $overrides + ['options' => [], 'calls' => 0, 'closed' => 0,
        'timeout' => false, 'chunks' => ['{}'], 'status' => 200];
}

$transport = new CurlTransport;
foreach ([[1750, 1750], [20000, 8000], [1, 1], [25000, 8000]] as [$timeoutMs, $connectMs]) {
    fake(); $accepted = true;
    try { $transport->sendWithin(Environment::Sandbox, RequestContext::Background, RegistrarRequest::balance(), 'test-api-key', $timeoutMs); }
    catch (\InvalidArgumentException) { $accepted = false; }
    same(true, $accepted, "Deadline {$timeoutMs} ms accepted");
    same($connectMs, $GLOBALS['curlFake']['options'][CURLOPT_CONNECTTIMEOUT_MS], "Connect timeout for {$timeoutMs} ms deadline");
    same($timeoutMs, $GLOBALS['curlFake']['options'][CURLOPT_TIMEOUT_MS], "Total timeout for {$timeoutMs} ms deadline");
    same(1, $GLOBALS['curlFake']['calls'], "Deadline {$timeoutMs} ms is one read");
}
foreach ([0, -1, 25001] as $timeoutMs) {
    fake(); $refused = false;
    try { $transport->sendWithin(Environment::Sandbox, RequestContext::Background, RegistrarRequest::balance(), 'test-api-key', $timeoutMs); }
    catch (\InvalidArgumentException) { $refused = true; }
    same(true, $refused, "Invalid deadline {$timeoutMs} ms refused");
    same(0, $GLOBALS['curlFake']['calls'], "Invalid deadline {$timeoutMs} ms never dispatches");
}
foreach ([Environment::Live, Environment::Sandbox] as $environment) {
    foreach ([RequestContext::Interactive, RequestContext::Background] as $context) {
        fake();
        $response = $transport->send($environment, $context, RegistrarRequest::balance(), 'test-api-key');
        $options = $GLOBALS['curlFake']['options'];
        $url = parse_url($options[CURLOPT_URL]);
        same($environment === Environment::Live ? 'www.namesilo.com' : 'ote.namesilo.com', $url['host'], 'Fixed environment host');
        same('/'.$context->value.'/getAccountBalance', $url['path'], 'Caller-declared route');
        same('https', $url['scheme'], 'HTTPS only');
        parse_str($url['query'], $query);
        same(['version' => '1', 'type' => 'json', 'key' => 'test-api-key'], $query, 'Required JSON v1 query');
        foreach ([CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_VERBOSE => false] as $option => $expected) {
            same($expected, $options[$option], 'Bounded transport option');
        }
        same('{}', $response->body(), 'Complete streamed response');
        same(1, $GLOBALS['curlFake']['calls'], 'One cURL execution');
        same(1, $GLOBALS['curlFake']['closed'], 'Handle closed');
    }
}
fake(['status' => 302]);
$client = new RegistrarClient('test-api-key', Environment::Sandbox, $transport);
same(Outcome::Unknown, $client->execute(RegistrarRequest::balance(), RequestContext::Interactive)->outcome, 'Redirect refused');
same(false, $GLOBALS['curlFake']['options'][CURLOPT_FOLLOWLOCATION], 'Redirect never followed');
same(1, $GLOBALS['curlFake']['calls'], 'Redirect not retried');
fake(['chunks' => [str_repeat('x', CurlTransport::BODY_LIMIT), 'x']]);
same(Outcome::Unknown, $client->execute(RegistrarRequest::balance(), RequestContext::Interactive)->outcome, 'Streaming cap is unknown');
same(1, $GLOBALS['curlFake']['closed'], 'Oversized stream closed');
fake(['chunks' => [str_repeat('x', CurlTransport::BODY_LIMIT)]]);
same(CurlTransport::BODY_LIMIT, strlen($transport->send(Environment::Sandbox, RequestContext::Background,
    RegistrarRequest::balance(), 'test-api-key')->body()), 'Exact streaming boundary accepted');
fake(['timeout' => true]);
same(Outcome::Unknown, $client->execute(RegistrarRequest::balance(), RequestContext::Interactive)->outcome, 'cURL timeout unknown');
same(1, $GLOBALS['curlFake']['calls'], 'Timeout no retry');
try {
    $transport->send(Environment::Sandbox, RequestContext::Interactive, RegistrarRequest::balance(), 'test-api-key');
    throw new \LogicException('Expected sanitized transport exception');
} catch (\RuntimeException $error) {
    expect(!str_contains($error->getMessage().print_r($error->getTrace(), true).$error->getTraceAsString().json_encode($error->getTrace(), JSON_THROW_ON_ERROR), 'test-api-key'), 'Direct transport exception redacted');
    same(null, $error->getPrevious(), 'Unsafe cause discarded');
}
fake();
try {
    $transport->send(Environment::Sandbox, RequestContext::Background, PurchaseMapping::renew('example.com', 1, false), 'test-api-key');
    throw new \RuntimeException('Draft renewal reached transport');
} catch (\TypeError) {
    same(0, $GLOBALS['curlFake']['calls'], 'Direct transport structurally blocks purchase mapping');
}
putenv('SSLKEYLOGFILE=synthetic-keylog');
same(Outcome::Unknown, $client->execute(RegistrarRequest::balance(), RequestContext::Background)->outcome, 'TLS key logging guard');
same(0, $GLOBALS['curlFake']['calls'], 'TLS logging blocks before dispatch');
putenv('SSLKEYLOGFILE');

echo "Transport: synthetic HTTPS options, routing, streaming cap, redirects and timeouts passed.\n";
