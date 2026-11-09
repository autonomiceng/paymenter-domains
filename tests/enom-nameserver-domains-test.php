<?php
// Synthetic cURL and account data only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/enom-test-counter.php';

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, CurlTransport, ReadCommand, ResponseParser};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit, EnomTestSendLimit};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;

// Only the exact observed not-found response can stop reads as outside this account.
$missing='<interface-response><Command>GetDomainInfo</Command><ErrCount>1</ErrCount><Done>true</Done>'
    .'<errors><Err1>Domain name not found</Err1></errors><responses><response><ResponseNumber>301029</ResponseNumber>'
    .'<ResponseString>Validation error; not found; domain name(s)</ResponseString></response></responses></interface-response>';
$absence=(new ResponseParser)->parse(ReadCommand::Info,$missing);
same(['rejected','domain_not_in_account'],[$absence->status,$absence->code],'Exact not-found response supplies absence evidence');
foreach(['<DomainName>example.com</DomainName>','<RRPCode>200</RRPCode>','<ResponseCount>2</ResponseCount>',
    '<debug><private>PRIVATE-TOKEN</private></debug>'] as $contradiction){
    $ambiguous=(new ResponseParser)->parse(ReadCommand::Info,str_replace('</interface-response>',$contradiction.'</interface-response>',$missing));
    expect($ambiguous->code!=='domain_not_in_account','Contradictory account evidence cannot prove absence');
}
same(null,(new ResponseParser)->parse(ReadCommand::Info,str_replace('Domain name not found','PRIVATE-TOKEN',$missing))->value('errors'),'An arbitrary account error stays private');
// A counted reply proves absence only with the one matching response it counts.
$counted=str_replace('<responses>','<ResponseCount>1</ResponseCount><responses>',$missing);
same(['rejected','domain_not_in_account'],[(new ResponseParser)->parse(ReadCommand::Info,$counted)->status,(new ResponseParser)->parse(ReadCommand::Info,$counted)->code],'A counted reply with its matching response proves absence');
$uncounted=preg_replace('#<responses>.*</responses>#s','',$counted);
foreach(['missing'=>$uncounted,'empty'=>str_replace('</errors>','</errors><responses></responses>',$uncounted),
    'self-closed'=>str_replace('</errors>','</errors><responses/>',$uncounted),
    'contradictory'=>str_replace('Validation error; not found; domain name(s)','Command completed successfully',$counted)] as $case=>$body){
    expect(substr_count($body,'<ResponseCount>1</ResponseCount>')===1,'Counted '.$case.' fixture');
    expect((new ResponseParser)->parse(ReadCommand::Info,$body)->code!=='domain_not_in_account','A counted reply with a '.$case.' response cannot prove absence');
}
same('domain_not_in_account',(new ResponseParser)->parse(ReadCommand::Info,preg_replace('#<responses>.*</responses>#s','',$missing))->code,'The uncounted reply keeps its accepted shape');
// Absence evidence is a read result only. The recorded purchase refusal keeps its own rules.
require __DIR__.'/Support/registration-refusal-reply.php';
$refusal=(new ResponseParser)->parse(Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\RegistrationCommand::Purchase,$purchaseRefusal);
expect($refusal->status==='rejected'&&$refusal->value('purchase_refusal')!==null,'The recorded purchase refusal still carries refusal proof');
foreach([$counted,$uncounted] as $body){
    $purchase=(new ResponseParser)->parse(Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\RegistrationCommand::Purchase,str_replace('GetDomainInfo','Purchase',$body));
    expect($purchase->code!=='domain_not_in_account'&&$purchase->value('purchase_refusal')===null,'A not-found reply never becomes purchase refusal proof');
}

$command = ReadCommand::tryFrom('GetAllDomains');
expect($command instanceof ReadCommand, 'Nameserver-filtered account domains have a typed read command');
$binding = new AccountBinding('enom', 1, Environment::Test, 'synthetic-uid');
$client = new Client($binding, 'synthetic-uid', 'SYNTHETIC-TOKEN');
$clock = $GLOBALS['enomTestCallClock'];
$context = static fn (int $limit = 1) => new RequestContext($binding, RequestOrigin::Background,
    $clock->now()->modify('+30 seconds'), new RequestBudget($limit), $clock);
$parameters = ['NameServer' => 'ns1.example.com'];
$events = [];
config(['domains.provider_send_guard' => static function ($registrar, $environment, $named) use (&$events): void {
    $events[] = [$registrar, $environment, $named];
}]);
resetWire(['chunks' => ['<interface-response><Command>GetAllDomains</Command><ErrCount>0</ErrCount><Done>true</Done><GetAllDomains><DomainDetail><DomainName>example.com</DomainName></DomainDetail></GetAllDomains></interface-response>']]);
$before = EnomTestSendLimit::usage('synthetic-uid')['day'];
$reply = $client->read($command, $parameters, $context());
same('success', $reply->status, 'Filtered domain read is admitted');
same([['enom', 'test', 'GetAllDomains']], $events, 'Physical read passes through the send guard');
same(1, count($GLOBALS['enomWire']['forms']), 'Read sends once without retries');
same($before + 1, EnomTestSendLimit::usage('synthetic-uid')['day'], 'Read uses the normal TEST call limit');
same(['example.com'], $reply->value('domains'), 'Read returns only canonical domain names');

$envelope = static fn (string $payload): string => '<interface-response><Command>GETALLDOMAINS</Command><ErrCount>0</ErrCount><Done>true</Done>'.$payload.'</interface-response>';
$row = static fn (string $name): string => '<DomainDetail><DomainName>'.$name.'</DomainName><DomainNameID>12345</DomainNameID><expiration-date>PRIVATE-DATE</expiration-date><Contact>PRIVATE-CONTACT</Contact></DomainDetail>';
$parser = new ResponseParser;
$parsed = static fn (string $payload) => $parser->parse($command, $envelope($payload));
$reply = $parsed('<GetAllDomains>'.$row('EXAMPLE.COM').$row('other.example.net').'<UserRequestStatus>DomainBox</UserRequestStatus></GetAllDomains><UID>PRIVATE-UID</UID>');
same('success', $reply->status, 'Documented XML collection is accepted');
same(['example.com', 'other.example.net'], $reply->value('domains'), 'Multiple names are canonicalized in order');
foreach (['getalldomains', 'uid', 'contact', 'domainnameid'] as $private) {
    same(null, $reply->value($private), 'Unneeded account fields are discarded');
}
expect(!str_contains(print_r($reply, true).json_encode($reply), 'example.com'), 'Generic diagnostics hide account names');
foreach (['<GetAllDomains/>', '<GetAllDomains><UserRequestStatus>DomainBox</UserRequestStatus></GetAllDomains>'] as $empty) {
    same([], $parsed($empty)->value('domains'), 'Explicit empty collection is usable');
}
$rows = '';
for ($i = 0; $i < 200; ++$i) { $rows .= $row('domain'.$i.'.example.com'); }
same(200, count($parsed('<GetAllDomains>'.$rows.'</GetAllDomains>')->value('domains')), 'Exact 200-domain response boundary');
foreach (['', '<GetAllDomains>PRIVATE-TEXT</GetAllDomains>', '<GetAllDomains><Unexpected/></GetAllDomains>',
    '<GetAllDomains/><GetAllDomains/>', '<GetAllDomains><DomainDetail/></GetAllDomains>',
    '<GetAllDomains><DomainDetail><DomainName>example.com</DomainName><DomainName>other.example.net</DomainName></DomainDetail></GetAllDomains>',
    '<GetAllDomains>'.$row('example.com').$row('EXAMPLE.COM').'</GetAllDomains>',
    '<GetAllDomains>'.$rows.$row('overflow.example.com').'</GetAllDomains>'] as $invalid) {
    $reply = $parsed($invalid);
    same('unknown', $reply->status, 'Missing, malformed, duplicate or oversized collections are unknown');
    same(null, $reply->value('domains'), 'Invalid collection never exposes partial results');
}
foreach (['bad name.com', 'example.com/path', 'user@example.com', 'localhost', '192.0.2.1', '*.example.com',
    '-bad.com', 'example.com.', str_repeat('a', 64).'.com', str_repeat('a.', 126).'com'] as $invalid) {
    same('unknown', $parsed('<GetAllDomains>'.$row($invalid).'</GetAllDomains>')->status, 'Invalid domain name refused');
}
same('unknown', $parser->parse($command, str_repeat('x', 1048577))->status, 'Injected response cap is 1 MiB');
same('unknown', $parser->parse($command, str_replace('GETALLDOMAINS', 'Purchase', $envelope('<GetAllDomains/>')))->status, 'Wrong command cannot supply domain evidence');
same('rejected', $parser->parse($command, str_replace('<ErrCount>0', '<ErrCount>1', $envelope('<GetAllDomains/>')))->status, 'Read refusal remains a refusal');

same(['Command' => 'GetAllDomains', 'UID' => 'synthetic-uid', 'PW' => 'SYNTHETIC-TOKEN',
    'ResponseType' => 'XML', 'NameServer' => 'ns1.example.com'], $GLOBALS['enomWire']['forms'][0], 'Only the documented filter is sent');
foreach ([[], ['NameServer' => ''], ['nameserver' => 'ns1.example.com'], ['NameServer' => null],
    ['NameServer' => ['ns1.example.com']], ['NameServer' => 'NS1.example.com'], ['NameServer' => 'localhost'],
    ['NameServer' => '192.0.2.1'], ['NameServer' => 'ns1.example.com.'], ['NameServer' => '*.example.com'],
    ['NameServer' => ' ns1.example.com'], ['NameServer' => str_repeat('a.', 67).'com'],
    ...array_map(static fn ($key) => $parameters + [$key => 'override'], ['UID', 'PW', 'Command', 'command', 'ResponseType', 'PageSize', 'Page', 'Start', 'UseDNS', 'GetDefaultOnly'])] as $invalid) {
    resetWire(); $events = []; $before = EnomTestSendLimit::usage('synthetic-uid')['day'];
    try { $client->read($command, $invalid, $context()); throw new LogicException('Expected invalid filtered read refusal'); }
    catch (InvalidArgumentException) {}
    same([], $events, 'Invalid filtered read never reaches the send guard');
    same([], $GLOBALS['enomWire']['forms'], 'Invalid filtered read never sends');
    same($before, EnomTestSendLimit::usage('synthetic-uid')['day'], 'Invalid filtered read never spends a TEST call');
    try { (new CurlTransport)->send(Environment::Test, $command, $invalid, 'synthetic-uid', 'SYNTHETIC-TOKEN', 25000); throw new LogicException('Expected direct transport refusal'); }
    catch (RuntimeException) {}
    same([], $GLOBALS['enomWire']['forms'], 'Direct transport also rejects unsafe variants');
}
foreach (['Purchase', 'ModifyNS', 'DeleteNameServer', 'UpdateNameServer', 'Extend'] as $write) {
    same(null, ReadCommand::tryFrom($write), 'Write command remains unavailable through reads');
}
foreach (['guard', 'minute', 'day', 'budget'] as $refusal) {
    config(['domains.enom_test_sends_per_day' => 1000, 'domains.enom_test_sends_per_minute' => 1000,
        'domains.provider_send_guard' => $refusal === 'guard' ? static fn () => throw new RuntimeException('PRIVATE-GUARD') : null]);
    $before = EnomTestSendLimit::usage('synthetic-uid');
    if ($refusal === 'minute') { config(['domains.enom_test_sends_per_minute' => $before['minute']]); }
    if ($refusal === 'day') { config(['domains.enom_test_sends_per_day' => $before['day']]); }
    resetWire(); $caught = null;
    try { $client->read($command, $parameters, $context($refusal === 'budget' ? 0 : 1)); }
    catch (Throwable $error) { $caught = $error; }
    expect($caught instanceof Throwable, 'Guard and call budgets refuse the filtered read');
    if ($refusal !== 'budget') { expect($caught instanceof EnomTestCallLimit, 'Physical refusal stays definitely unsent'); }
    same([], $GLOBALS['enomWire']['forms'], 'Refused read sends nothing');
    same($before['day'], EnomTestSendLimit::usage('synthetic-uid')['day'], 'Refused read records no attempt');
}
config(['domains.enom_test_sends_per_day' => 1000, 'domains.enom_test_sends_per_minute' => 1000, 'domains.provider_send_guard' => null]);
resetWire(['chunks' => [str_repeat('x', 1048576), 'x']]);
same('unknown', $client->read($command, $parameters, $context())->status, 'Streaming response cap fails closed');
same(1, count($GLOBALS['enomWire']['forms']), 'Oversized response is never retried');

$nativeSuccessful = true;
echo "Nameserver-filtered account domain read passed.\n";
