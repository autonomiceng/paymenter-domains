<?php
// Synthetic cURL only; adapted from paymenter-enom 444f31c Api/RegistrarClient.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api;
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Data\Environment;
ini_set('zend.exception_ignore_args', '0');
function curl_init(): object { return new \stdClass; }
function curl_setopt_array($handle, array $options): bool { $GLOBALS['enomCurl']['options'] = $options; return !$GLOBALS['enomCurl']['configFail']; }
function curl_exec($handle): bool
{
    $fake = &$GLOBALS['enomCurl']; $fake['calls']++;
    if ($fake['timeout']) { throw new \RuntimeException('private-token '.$fake['options'][CURLOPT_POSTFIELDS]); }
    foreach ($fake['chunks'] as $chunk) { if (($fake['options'][CURLOPT_WRITEFUNCTION])($handle, $chunk) !== strlen($chunk)) { return false; } }
    return true;
}
function curl_getinfo($handle, int $option): int { return $GLOBALS['enomCurl']['status']; }
function curl_close($handle): void { $GLOBALS['enomCurl']['closed']++; }
function resetCurl(array $overrides = []): void
{
    $GLOBALS['enomCurl'] = $overrides + ['calls' => 0, 'closed' => 0, 'options' => [], 'chunks' => ['xml'], 'timeout' => false, 'configFail' => false, 'status' => 200];
}
$transport = new CurlTransport;
foreach ([Environment::Test, Environment::Live] as $environment) {
    resetCurl();
    same('xml', $transport->send($environment, ReadCommand::Info, ['SLD' => 'example', 'TLD' => 'com'], 'private-uid', 'private-token', 1200), 'Complete body');
    $options = $GLOBALS['enomCurl']['options'];
    same($environment === Environment::Live ? 'https://reseller.enom.com/interface.asp' : 'https://resellertest.enom.com/interface.asp', $options[CURLOPT_URL], 'Fixed endpoint');
    parse_str($options[CURLOPT_POSTFIELDS], $form);
    same(['Command' => 'GetDomainInfo', 'UID' => 'private-uid', 'PW' => 'private-token', 'ResponseType' => 'XML', 'SLD' => 'example', 'TLD' => 'com'], $form, 'Source POST form');
    foreach ([CURLOPT_POST => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT_MS => 1200, CURLOPT_TIMEOUT_MS => 1200, CURLOPT_VERBOSE => false, CURLOPT_HEADER => false,
        CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true] as $key => $expected) { same($expected, $options[$key], 'Bounded transport options'); }
    same(1, $GLOBALS['enomCurl']['closed'], 'Handle closed');
}
foreach ([['status' => 302], ['status' => 500], ['configFail' => true], ['timeout' => true], ['chunks' => [str_repeat('x', 1048576), 'x']]] as $failure) {
    resetCurl($failure);
    try { $transport->send(Environment::Test, ReadCommand::Balance, [], 'private-uid', 'private-token', 25000); throw new \LogicException('Expected transport exception'); }
    catch (\RuntimeException $error) {
        $trace = (string) $error.print_r($error->getTrace(), true).json_encode($error->getTrace());
        expect(!str_contains($trace, 'private-token') && !str_contains($trace, 'private-uid'), 'Arguments-enabled direct exception redacted');
        same(null, $error->getPrevious(), 'Unsafe cause discarded');
    }
    expect($GLOBALS['enomCurl']['calls'] <= 1, 'No retries');
    same(1, $GLOBALS['enomCurl']['closed'], 'Failure handle closed');
}
foreach ([ReadCommand::Balance, ReadCommand::Tlds] as $command) {
    resetCurl(['chunks' => [str_repeat('x', $command->bodyLimit())]]);
    same($command->bodyLimit(), strlen($transport->send(Environment::Test, $command, [], 'uid', 'token', 25000)), 'Exact streaming boundary');
}
foreach (['synthetic-keylog', '0'] as $keylog) {
resetCurl(); putenv('SSLKEYLOGFILE='.$keylog);
try { $transport->send(Environment::Test, ReadCommand::Balance, [], 'uid', 'token', 25000); throw new \LogicException('Expected TLS guard'); }
catch (\RuntimeException) { same(0, $GLOBALS['enomCurl']['calls'], 'TLS key logging refuses before dispatch'); }
putenv('SSLKEYLOGFILE');
}
echo "Enom transport: synthetic HTTPS, POST, deadline cap, streaming, no retries and redaction passed.\n";

foreach ([['command' => 'Purchase'], ['UID' => 'override'], ['Years' => 1]] as $unsafe) {
    resetCurl();
    try { $transport->send(Environment::Test, ReadCommand::Balance, $unsafe, 'uid', 'token', 25000); throw new \LogicException('Expected named parameter refusal'); }
    catch (\RuntimeException) { same(0, $GLOBALS['enomCurl']['calls'], 'Direct transport rejects mutation/credential/unrelated parameters'); }
}
