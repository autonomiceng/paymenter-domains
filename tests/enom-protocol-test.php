<?php
// Synthetic scenarios adapted from paymenter-enom 444f31c tests/Enom/provider-contracts.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Disposition, Environment, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ProtocolResult, ReadCommand, ResponseParser, Transport};
ini_set('zend.exception_ignore_args', '0');
function envelope(string $command, string $payload = ''): string
{
    return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$payload.'</interface-response>';
}
$parser = new ResponseParser;
$valid = envelope('GetBalance', '<AvailableBalance>1200.00</AvailableBalance>');
same('success', $parser->parse(ReadCommand::Balance, $valid)->status, 'Clean envelope acknowledged');
foreach (['<interface-response>', '<html/>', envelope('Purchase'), str_replace('true', 'false', $valid),
    str_replace('<ErrCount>0', '<ErrCount>-1', $valid), envelope('GetBalance', '<ErrCount>0</ErrCount>'),
    envelope('GetBalance', '<Err1>private error</Err1>'), envelope('GetBalance', '<errors><Err1>secret</Err1></errors>'),
    envelope('GetBalance', '<ResponseCode>unknown</ResponseCode>'), envelope('GetBalance', '<RRPCode>999</RRPCode>'),
    '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'.$valid,
    str_repeat('x', 1048577), envelope('GetBalance', str_repeat('<x>', 18).str_repeat('</x>', 18)),
    envelope('GetBalance', str_repeat('<x/>', 20001))] as $invalid) {
    $reply = $parser->parse(ReadCommand::Balance, $invalid);
    same('unknown', $reply->status, 'Malformed/contradictory/bounded input unknown');
    same(null, $reply->value('availablebalance'), 'Unknown reply discards private body');
}
// UTF-16 bypasses a raw-byte DOCTYPE scan; structural rejection is still required.
$utf16 = "\xff\xfe".implode('', array_map(fn ($c) => $c."\0", str_split('<!DOCTYPE interface-response>'. $valid)));
same('unknown', $parser->parse(ReadCommand::Balance, $utf16)->status, 'Encoded DTD rejected structurally');
same('unknown', $parser->parse(ReadCommand::Check, envelope('Check', '<RRPCode>999</RRPCode>'))->status, 'Check top-level status allow-list');
$refused = $parser->parse(ReadCommand::Balance, str_replace('<ErrCount>0', '<ErrCount>1', envelope('GetBalance', '<RRPCode>530</RRPCode><Err1>secret</Err1>')));
same('rejected', $refused->status, 'Read refusal retained internally');
same('530', $refused->code, 'Safe numeric code only');
$catalogue = envelope('GetTLDList', '<TLDList>'.str_repeat('<TLD>example</TLD>', 65000).'</TLDList>');
expect(strlen($catalogue) > 1048576, 'Catalogue fixture exceeds normal cap');
same('success', $parser->parse(ReadCommand::Tlds, $catalogue)->status, 'Source larger catalogue allowance retained');
same('unknown', $parser->parse(ReadCommand::Tlds, str_repeat('x', 8388609))->status, 'Catalogue cap enforced on injection');
$binding = new AccountBinding('enom', 1, Environment::Test, 'synthetic-uid');
$transport = new class implements Transport {
    public int $calls = 0;
    public bool $fail = false;
    public int $timeout = 0;
    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        $this->calls++; $this->timeout = $timeoutMs;
        if ($this->fail) { throw new RuntimeException('synthetic-token private body'); }
        return envelope($command->value, '<AvailableBalance>private-value</AvailableBalance>');
    }
};
foreach ([[new AccountBinding('namesilo', 1, Environment::Test, 'synthetic-uid'), 'synthetic-uid', 'token'],
    [$binding, 'other', 'token'], [$binding, 'synthetic-uid', ''], [$binding, 'synthetic-uid', str_repeat('x', 4097)],
    [$binding, 'synthetic-uid', "bad\ntoken"]] as [$invalidBinding, $uid, $token]) {
    try { new Client($invalidBinding, $uid, $token, $transport); throw new LogicException('Expected credential refusal'); }
    catch (InvalidArgumentException $e) {
        $trace = print_r($e->getTrace(), true);
        expect(!str_contains($trace, $invalidBinding->fingerprint) && !str_contains($trace, $uid), 'Constructor trace excludes binding/credentials');
    }
}
$client = new Client($binding, 'synthetic-uid', 'synthetic-token', $transport);
$context = fn (int $limit = 10, ?AccountBinding $account = null, string $date = '+1 hour') => new RequestContext(
    $account ?? $binding, RequestOrigin::Background, new DateTimeImmutable($date), new RequestBudget($limit));
$clock = new class implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2020-01-01T00:00:00Z'); }
};
$fixed = new RequestContext($binding, RequestOrigin::Interactive, new DateTimeImmutable('2020-01-01T00:00:02Z'), new RequestBudget(1), $clock);
$reply = $client->read(ReadCommand::Balance, [], $fixed);
same('success', $reply->status, 'Injected clock governs eligibility and transport deadline');
same(2000, $transport->timeout, 'Timeout derived from the same engine-selected clock');
$expired = new RequestContext($binding, RequestOrigin::Interactive, $clock->now(), new RequestBudget(1), $clock);
try { $client->read(ReadCommand::Balance, [], $expired); throw new LogicException('Expected clock refusal'); } catch (RuntimeException) {}
same(1, $expired->budget->remaining(), 'Expired injected deadline preserves budget');
$reply = $client->read(ReadCommand::Balance, [], $context());
expect(!method_exists($client, 'result'), 'Protocol plumbing cannot fabricate neutral financial/account evidence');
same(25000, $transport->timeout, 'Distant context uses total transport cap');
foreach ([$context(0), $context(1, null, '-1 second'), $context(1, new AccountBinding('enom', 2, Environment::Test, 'other'))] as $blocked) {
    $before = $transport->calls;
    try { $client->read(ReadCommand::Balance, [], $blocked); throw new LogicException('Expected context refusal'); }
    catch (RuntimeException|InvalidArgumentException $error) {
        expect(!str_contains((string) $error, 'synthetic-token'), 'Context error redacted');
    }
    same($before, $transport->calls, 'Binding/deadline/budget refuses before transport');
}
foreach ([['UID' => 'override'], ['Command' => 'Purchase'], ['UseCreditCard' => 'yes'], ['TLD' => 'com', 'tld' => 'net'], ['SLD' => []], ['SLD' => str_repeat('x', 64), 'TLD' => 'com'], ['SLD' => 'example', 'TLD' => 'com', 'Years' => 11]] as $bad) {
    $before = $transport->calls;
    try { $client->read(ReadCommand::Info, $bad, $context()); throw new LogicException('Expected parameter refusal'); }
    catch (InvalidArgumentException) {}
    same($before, $transport->calls, 'Unsafe parameter has no dispatch path');
}
expect(ReadCommand::tryFrom('Purchase') === null && ReadCommand::tryFrom('Extend') === null, 'Purchase commands structurally unavailable');
$transport->fail = true;
$before = $transport->calls;
$failure = $client->read(ReadCommand::Balance, [], $context());
same(['status' => 'unknown', 'code' => 'transport'], $failure->jsonSerialize(), 'Secret exception discarded');
same($before + 1, $transport->calls, 'Timeout never retried');
foreach ([$client, $reply, $failure] as $private) {
    $diagnostic = print_r($private, true).var_export((array) $private, true).json_encode($private);
    foreach (['synthetic-token', 'synthetic-uid', 'private-value'] as $secret) { expect(!str_contains($diagnostic, $secret), 'Generic diagnostics hide secrets/raw data'); }
    try { serialize($private); throw new LogicException('Expected serialization refusal'); } catch (LogicException $e) { expect(!str_contains($e->getMessage(), 'Expected'), 'Serialization refused'); }
    try { $copy = clone $private; throw new LogicException('Expected clone refusal'); } catch (LogicException $e) { expect(!str_contains($e->getMessage(), 'Expected'), 'Cloning refused'); }
}
echo "Enom protocol: bounded XML, context, redaction and read-only dispatch passed.\n";
