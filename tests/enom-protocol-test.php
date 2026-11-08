<?php
// Synthetic scenarios adapted from paymenter-enom 444f31c tests/Enom/provider-contracts.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Disposition, Environment, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ProtocolResult, ReadCommand, ResponseParser, RegistrationCommand, Transport};
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
// Production shape (Enom TEST GetTLDList, 2026-10-22 excerpt): nested entries, IDN and numeric endings mixed in.
$entries = ['eco', 'com', 'net', 'co.uk', 'xn--3ds443g', '3rdearlyaccess', 'example36', 'ORG'];
$listed = envelope('GetTLDList', '<tldlist>'.implode('', array_map(fn ($t) => "<tld>\n<tld>$t</tld>\n</tld>\n", $entries)).'</tldlist>');
same(['eco', 'com', 'net', 'co.uk', 'org'], Paymenter\Extensions\Servers\Domains\Registrars\Enom\Catalogue::names($parser->parse(ReadCommand::Tlds, $listed)),
    'Unsupported listed endings are skipped, not fatal to the whole list');
same(null, Paymenter\Extensions\Servers\Domains\Registrars\Enom\Catalogue::names($parser->parse(ReadCommand::Tlds, envelope('GetTLDList', '<tldlist><tld><tld>xn--3ds443g</tld></tld></tldlist>'))),
    'A list with no supported ending stays unavailable');
same(null, Paymenter\Extensions\Servers\Domains\Registrars\Enom\Catalogue::names($parser->parse(ReadCommand::Tlds, envelope('GetTLDList', '<tldlist><tld><tld>com</tld></tld><tld><tld>bad ending</tld></tld></tldlist>'))),
    'A non-hostname entry still voids the whole list');
// Sanitized Purchase refusal from the 2026-11-07 TEST probe.
$purchaseRefusal='<interface-response><RRPCode>541</RRPCode><RRPText>Data management policy violation; host ns2.provider.example is subordinate to provider.example, which does not exist yet</RRPText>'
    .'<Contacts><RegistrantEmailAddress>PRIVATE-CONTACT</RegistrantEmailAddress></Contacts><Command>PURCHASE</Command><APIType>API.NET</APIType><Language>eng</Language>'
    .'<ErrCount>1</ErrCount><errors><Err1>Validation error. Failed to create order.</Err1></errors><ResponseCount>1</ResponseCount>'
    .'<responses><response><ResponseNumber>309242</ResponseNumber><ResponseString>Validation error; failed to add; order(s)</ResponseString></response></responses>'
    .'<MinPeriod>1</MinPeriod><MaxPeriod>10</MaxPeriod><Server>TEST</Server><Site>Enom</Site><IsLockable>True</IsLockable><IsRealTimeTLD>True</IsRealTimeTLD>'
    .'<TimeDifference>+00.00</TimeDifference><ExecTime>0.01</ExecTime><Done>true</Done><TrackingKey>synthetic-tracking</TrackingKey>'
    .'<RequestDateTime>11/7/2026 6:42:36 AM</RequestDateTime><debug/></interface-response>';
same('rejected',$parser->parse(RegistrationCommand::Purchase,$purchaseRefusal)->status,'Observed 541 failure to create an order is a definite refusal');
$onlyResponse=preg_replace('~<errors>.*?</errors>~s','',$purchaseRefusal);
same('rejected',$parser->parse(RegistrationCommand::Purchase,$onlyResponse)->status,'The exact failed-to-add order response also proves refusal');
$twoErrors=str_replace(['<ErrCount>1</ErrCount>','</errors>'],['<ErrCount>2</ErrCount>','<Err2>Validation error. Failed to create order.</Err2></errors>'],$purchaseRefusal);
same('rejected',$parser->parse(RegistrationCommand::Purchase,$twoErrors)->status,'Multiple consistent errors retain explicit order-creation refusal');

// The recorded 541 has ResponseCount=1; synthetic response-only and errors-only
// controls remain complete only with their explicit matching response count.
$errorsOnly=str_replace('<ResponseCount>1</ResponseCount>','<ResponseCount>0</ResponseCount>',preg_replace('~<responses>.*?</responses>~s','',$purchaseRefusal));
same('rejected',$parser->parse(RegistrationCommand::Purchase,$errorsOnly)->status,'A complete errors-only refusal explicitly declares zero responses');
foreach ([
    'missing_response_count'=>str_replace('<ResponseCount>1</ResponseCount>','',$purchaseRefusal),
    'missing_count_errors_only'=>str_replace('<ResponseCount>0</ResponseCount>','',$errorsOnly),
    'missing_errors_count_two'=>str_replace('<ErrCount>1</ErrCount>','<ErrCount>2</ErrCount>',$onlyResponse),
    'missing_errors_count_nine'=>str_replace('<ErrCount>1</ErrCount>','<ErrCount>9</ErrCount>',$onlyResponse),
    'empty_errors_count_three'=>str_replace(['<ErrCount>1</ErrCount>','<errors><Err1>Validation error. Failed to create order.</Err1></errors>'],['<ErrCount>3</ErrCount>','<errors/>'],$purchaseRefusal),
    'error_list_count_mismatch'=>str_replace('<ErrCount>1</ErrCount>','<ErrCount>2</ErrCount>',$purchaseRefusal),
    'response_count_two'=>str_replace('<ResponseCount>1</ResponseCount>','<ResponseCount>2</ResponseCount>',$purchaseRefusal),
    'response_count_zero'=>str_replace('<ResponseCount>1</ResponseCount>','<ResponseCount>0</ResponseCount>',$purchaseRefusal),
    'response_count_empty'=>str_replace('<ResponseCount>1</ResponseCount>','<ResponseCount/>',$purchaseRefusal),
    'response_count_invalid'=>str_replace('<ResponseCount>1</ResponseCount>','<ResponseCount>invalid</ResponseCount>',$purchaseRefusal),
    'response_count_padded'=>str_replace('<ResponseCount>1</ResponseCount>','<ResponseCount>01</ResponseCount>',$purchaseRefusal),
    'missing_response'=>preg_replace('~<responses>.*?</responses>~s','',$purchaseRefusal),
    'empty_response'=>preg_replace('~<responses>.*?</responses>~s','<responses/>',$purchaseRefusal),
] as $case=>$body){
    $result=$parser->parse(RegistrationCommand::Purchase,$body);
    same(['unknown',null],[$result->status,$result->value('purchase_refusal')],'Incomplete counts never mint refusal proof: '.$case);
}


foreach ([str_replace(['<Err1>','</Err1>'],['<Err2>','</Err2>'],$purchaseRefusal),str_replace('<RRPCode>541</RRPCode>','<RRPCode>451</RRPCode>',$purchaseRefusal),
    str_replace('<RRPCode>541</RRPCode>','<RRPCode>549</RRPCode>',$purchaseRefusal),str_replace('<RRPCode>541</RRPCode>','<RRPCode>554</RRPCode>',$purchaseRefusal),
    str_replace('<RRPCode>541</RRPCode>','',$purchaseRefusal),
    str_replace('<ErrCount>1</ErrCount>','<ErrCount>0</ErrCount>',$purchaseRefusal),
    str_replace('Validation error. Failed to create order.','Validation error. Order status unknown.',str_replace('Validation error; failed to add; order(s)','Order pending',$purchaseRefusal)),
    str_replace('Validation error; failed to add; order(s)','Order pending',$purchaseRefusal),
    str_replace('<Done>true</Done>','<Done>false</Done>',$purchaseRefusal)] as $ambiguous) {
    same('unknown',$parser->parse(RegistrationCommand::Purchase,$ambiguous)->status,'An ambiguous refusal remains uncertain');
}
foreach (['<OrderID>1234</OrderID>','<OrderID/>','<GetDomainInfo><OrderID>1234</OrderID></GetDomainInfo>',
    '<GetDomainInfo><DomainName DomainNameID="456">example.com</DomainName></GetDomainInfo>','<Accepted>true</Accepted>','<ExpirationDate>2027-11-07</ExpirationDate>','<OrderStatus>Pending</OrderStatus>','<ChargedAmount>6.00</ChargedAmount>'] as $contradiction) {
    same('unknown',$parser->parse(RegistrationCommand::Purchase,str_replace('</interface-response>',$contradiction.'</interface-response>',$purchaseRefusal))->status,
        'Contradictory order or financial evidence prevents definite refusal');
}

// The reply retains fixed error wording only, never private suffixes or arbitrary error content.
foreach ([['Error 545 - Name server does not exist; PW=PRIVATE-TOKEN','Error 545 - Name server does not exist'],
    [str_repeat('PRIVATE-TOKEN',400),null],['owner@example.test PRIVATE-ACCOUNT',null],['<private>PRIVATE-TOKEN</private>',null]] as [$text,$expected]) {
    $body='<interface-response><Command>Purchase</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>545</RRPCode>'
        .'<errors><Err1>'.htmlspecialchars($text,ENT_XML1).'</Err1></errors><debug>PRIVATE-TOKEN</debug></interface-response>';
    $reply=$parser->parse(RegistrationCommand::Purchase,$body);
    same(['code'=>'545','text'=>$expected],get_object_vars($reply->registrarReply()),'Bounded sanitized diagnostic');
    same('unknown',$reply->status,'Diagnostic never makes the refusal terminal');
    expect(!str_contains(json_encode($reply->registrarReply()),'PRIVATE-'),'No private error values survive');
    same(null,$reply->value('errors'),'Non-success still discards all raw protocol data');
}
$reply=$parser->parse(RegistrationCommand::Purchase,'<interface-response><Command>Purchase</Command><ErrCount>1</ErrCount><Done>true</Done>'
    .'<errors><Err1>Invalid nameserver information</Err1></errors><responses><response><ResponseNumber>316153</ResponseNumber>'
    .'</response></responses></interface-response>');
same(['code'=>'316153','text'=>'Invalid nameserver information'],get_object_vars($reply->registrarReply()),'Nested eNom response number survives');
same(null,\Paymenter\Extensions\Servers\Domains\Data\RegistrarReply::fromArray(['code'=>'545','text'=>'PRIVATE-TOKEN']),
    'Malformed saved diagnostics cannot expose arbitrary text');

foreach (['<RRPCode>545</RRPCode>','<RRPCode>200</RRPCode><Err1>Invalid nameserver information</Err1>'] as $unconfirmed) {
    $reply=$parser->parse(RegistrationCommand::Purchase,envelope('Purchase',$unconfirmed));
    same('unknown',$reply->status,'A contradictory or unsuccessful acknowledgement stays uncertain');
    expect($reply->registrarReply()!==null,'Numeric Purchase evidence survives outside the ErrCount failure branch');
}

$reply=$parser->parse(RegistrationCommand::Purchase,envelope('Purchase','<responses><response><ResponseNumber>1300</ResponseNumber></response></responses>'));
same(['success',null,null],[$reply->status,$reply->code,$reply->registrarReply()],
    'A diagnostic response number cannot change an incomplete acknowledgement into pending acceptance');

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
foreach ([[11, 10], [0, 10], [1, 99]] as [$years, $type]) {
    $transport->calls = 0;
    try { $client->read(ReadCommand::ProductPrice, ['TLD' => 'com', 'ProductType' => $type, 'Years' => $years], $context()); throw new LogicException('Expected product price bounds refusal'); }
    catch (InvalidArgumentException) {}
    same(0, $transport->calls, 'Invalid product price bounds never dispatch');
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

foreach (['host','record'] as $container) {
    foreach (['A'=>' 192.0.2.1 ', 'CNAME'=>' target.example.com ', 'MX'=>' mail.example.com ', 'TXT'=>'  exact text  '] as $type=>$address) {
        $reply=$parser->parse(ReadCommand::Hosts,envelope('GetHosts','<'.$container.'><address>'.$address.'</address><type>'.$type.'</type></'.$container.'>'));
        same($type==='TXT'?$address:trim($address),$reply->value($container.'.address'),'Only TXT preserves address bytes in '.$container);
    }
}

// NS14 paired wire regression, kept here until C1 serializes suite registration.
(static function (): void { require __DIR__.'/Support/demo-c-ds-inventory.php'; })();
(static function (): void { require __DIR__.'/Support/demo-c-ds-policy.php'; })();
(static function (): void { require __DIR__.'/Support/demo-c-ds-readback.php'; })();
