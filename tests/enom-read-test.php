<?php
// Synthetic read scenarios adapted from paymenter-enom 444f31c provider-contracts/contacts-regressions.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, Disposition, Environment, ExpiryPrecision, Query, RequestBudget, RequestContext, RequestOrigin, SendState, Support};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ProtocolResult, ReadCommand, ResponseParser, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Driver, Expiry};
use Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
function body(string $command, string $payload = ''): string { return '<interface-response><Command>'.$command.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$payload.'</interface-response>'; }
$binding = new AccountBinding('enom', 1, Environment::Test, 'synthetic-uid');
$wire = new class implements Transport {
    public array $payloads = []; public array $calls = []; public bool $fail = false; public bool $reject = false;
    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        $this->calls[] = [$command, $parameters];
        if ($this->fail) { throw new RuntimeException('synthetic-secret timeout'); }
        $result = body($command->value, $this->payloads[$command->name] ?? '');
        return $this->reject ? str_replace('<ErrCount>0', '<ErrCount>1', $result) : $result;
    }
};
$context = fn (int $budget = 20) => new RequestContext($binding, RequestOrigin::Background, new DateTimeImmutable('+1 hour'), new RequestBudget($budget));
$registry = new RegistrarRegistry;
$verifier = new class implements AccountVerifier {
    public function verify(Environment $environment, #[\SensitiveParameter] array $credentials): string { return $credentials['uid']; }
};
$registry->register('enom', ['uid', 'token'], $verifier, fn (AccountBinding $accepted, array $credentials) => new Driver(
    new Client($accepted, $credentials['uid'], $credentials['token'], $wire), ['com', 'co.uk']));
[$verified] = $registry->verify('enom', 1, Environment::Test, ['uid' => 'synthetic-uid', 'token' => 'synthetic-secret']);
$driver = $registry->reader($verified, ['uid' => 'synthetic-uid', 'token' => 'synthetic-secret']);
expect($driver instanceof Driver && $binding->matches($driver->binding()), 'Neutral registry returns bound read-only driver');
foreach (['0' => '0.00', '1,234.50' => '1234.50', '12.3' => '12.3'] as $input => $amount) {
    $wire->payloads['Balance'] = '<AvailableBalance>'.$input.'</AvailableBalance>';
    $result = $driver->balance($context()); same($amount, $result->evidence()->balance?->amount, 'Source grouped USD balance exact');
}
foreach (['', '-1', '1,23.00', '1e3', '1.234'] as $bad) {
    $wire->payloads['Balance'] = '<AvailableBalance>'.$bad.'</AvailableBalance>';
    $result = $driver->balance($context()); same(Disposition::Unknown, $result->disposition, 'Bad balance unknown'); same(null, $result->evidence()->balance, 'Unknown is never zero');
}
$wire->payloads['Tlds'] = '<TLDList><TLD>COM</TLD><TLD>co.uk</TLD><TLD>com</TLD></TLDList>';
same(['com', 'co.uk'], $driver->catalogue($context())->facts()['tlds'], 'Compound endings normalized/deduplicated');
$wire->payloads['Tlds'] = '<TLDList><TLD>com</TLD><TLD>bad ending</TLD></TLDList>';
same('unknown', $driver->catalogue($context())->status, 'Malformed catalogue never partly accepted');
$wire->payloads['Tlds'] = '<TLDList/>'; same('unknown', $driver->catalogue($context())->status, 'No invented empty catalogue');
$details = '<TLDs><TLD><TLD>com</TLD><Registration><Minimum>2</Minimum><Maximum>10</Maximum><Unit>Year</Unit><DNSRequired>true</DNSRequired></Registration><AllowWPPS>1</AllowWPPS><AbleToLock>0</AbleToLock><Renewal><AutoRenewOnly>unknown</AutoRenewOnly></Renewal></TLD></TLDs>';
$wire->payloads['TldInfo'] = $details;
$facts = $driver->tldCapabilities('com', $context())->facts();
same(range(2, 10), $facts['capabilities']->get('register')->terms, 'Source per-TLD registration terms');
same(Support::Unsupported, $facts['capabilities']->get('lock')->support, 'Explicit unsupported flag');
same(Support::Unknown, $facts['capabilities']->get('ds')->support, 'Missing capability unknown');
same('paid', $facts['capabilities']->privacy->value, 'Privacy paid, never inferred free');
same(null, $facts['auto_renew_only'], 'Unrecognized provider flag remains unknown');
$wire->payloads['TldInfo'] = str_replace('<TLD>com</TLD>', '<TLD>net</TLD>', $details);
same('unknown', $driver->tldCapabilities('com', $context())->status, 'Different ending rejected');
$field = '<Attribute><Name>nexus</Name><Required>1</Required><IsChild>0</IsChild><UserDefined>false</UserDefined><Options><Option><Value>citizen</Value><Title> Citizen </Title><Requires><Attribute><Name>country_detail</Name></Attribute></Requires></Option></Options></Attribute>';
$child = '<Attribute><Name>country_detail</Name><Required>2</Required><IsChild>1</IsChild><UserDefined>true</UserDefined></Attribute>';
$wire->payloads['Attributes'] = '<Attributes>'.$field.$child.'</Attributes>';
$schema = $driver->registrySchema('com', $context())->facts();
same(true, $schema['supported'], 'Readable schema supported'); same(['country_detail'], $schema['fields'][0]['options'][0]['requires'], 'Dependencies preserved');
same(true, $schema['fields'][1]['child'], 'Conditional child preserved');
foreach ([str_replace('country_detail', 'missing_child', $field).$child, str_replace('<Required>1', '<Required>invalid', $field).$child,
    str_replace('<Name>nexus', '<Name>uid', $field).$child, str_replace('<Value>citizen</Value>', '<Value/>', $field).$child] as $bad) {
    $wire->payloads['Attributes'] = '<Attributes>'.$bad.'</Attributes>';
    same('unknown', $driver->registrySchema('com', $context())->status, 'Malformed schema closes ending');
}
$registryFields = \Paymenter\Extensions\Servers\Domains\Registrars\Enom\RegistryFields::class;
$parseFields = fn (string $xml) => $registryFields::normalize((new ResponseParser)->parse(ReadCommand::Attributes, body('GetExtAttributes', '<Attributes>'.$xml.'</Attributes>')));
$optional = str_replace('<Required>1', '<Required>0', $field);
foreach (['required' => $field, 'child' => str_replace('<IsChild>0', '<IsChild>1', $optional),
    'text' => str_replace('<UserDefined>false', '<UserDefined>true', $optional),
    'options' => str_replace('<Value>citizen', '<Value>resident', $optional)] as $conflict => $duplicate) {
    try { $parseFields($optional.$duplicate.$child); throw new LogicException('Expected duplicate '.$conflict.' refusal'); }
    catch (InvalidArgumentException) {}
}
$expectedFields = $parseFields($field.$child);
same($expectedFields, $parseFields($field.$field.$child), 'Identical registry duplicates collapse');
$withoutOptions = preg_replace('#<Options>.*?</Options>#', '', $field);
same($expectedFields, $parseFields($withoutOptions.$field.$child), 'Later registry options fill an empty first entry');
same($expectedFields, $parseFields($field.$withoutOptions.$child), 'Empty later registry options preserve existing choices');
foreach (['required' => str_replace('<Required>1', '<Required>0', $withoutOptions),
    'child' => str_replace('<IsChild>0', '<IsChild>1', $withoutOptions),
    'text' => str_replace('<UserDefined>false', '<UserDefined>true', $withoutOptions)] as $conflict => $first) {
    try { $parseFields($first.$field.$child); throw new LogicException('Expected option-fill '.$conflict.' refusal'); }
    catch (InvalidArgumentException) {}
}
$wire->payloads['Attributes'] = '<Attributes/>'; same([], $driver->registrySchema('com', $context())->facts()['fields'], 'Explicit no registry requirements retained');
foreach (['register' => 10, 'renew' => 16, 'transfer' => 19] as $action => $type) {
    $wire->payloads['ProductPrice'] = '<ProductPrice><ProductEnabled>true</ProductEnabled><Price>0.0000</Price></ProductPrice>';
    $price = $driver->productPrice('com', $action, 2, $context()); same('success', $price->status, 'Known zero is valid lexical price');
    same('0.0000', $price->facts()['price'], 'No float price conversion'); same(['TLD' => 'com', 'ProductType' => $type, 'Years' => 2], end($wire->calls)[1], 'Exact action/term wire');
}
foreach (['false', 'FALSE', 'FaLsE', '0'] as $disabled) {
    $wire->payloads['ProductPrice'] = '<ProductPrice><ProductEnabled>'.$disabled.'</ProductEnabled><Price>10.00</Price></ProductPrice>';
    same('no_price', $driver->productPrice('com', 'register', 1, $context())->status, 'Explicit disablement alone maps to no price');
}
foreach (['<Price>10.00000</Price>', '<Price>10.00</Price><Currency>EUR</Currency>'] as $ignoredPrice) {
    $wire->payloads['ProductPrice'] = '<ProductPrice><ProductEnabled>false</ProductEnabled>'.$ignoredPrice.'</ProductPrice>';
    same('no_price', $driver->productPrice('com', 'register', 1, $context())->status, 'Explicit disablement does not depend on price precision or currency');
}
foreach (['<Price>10.00</Price>', '<ProductEnabled><Value>true</Value></ProductEnabled><Price>10.00</Price>',
    '<ProductEnabled>true</ProductEnabled>', '<ProductEnabled>true</ProductEnabled><Price>1e3</Price>',
    '<ProductEnabled>yes</ProductEnabled><Price>10</Price>'] as $malformed) {
    $wire->payloads['ProductPrice'] = '<ProductPrice>'.$malformed.'</ProductPrice>';
    same('unknown', $driver->productPrice('com', 'register', 1, $context())->status, 'Malformed price reply is a failed read');
}
$wire->reject = true; same('unknown', $driver->productPrice('com', 'register', 1, $context())->status, 'Other provider non-success is failed read');
$wire->reject = false; $wire->fail = true; same('unknown', $driver->productPrice('com', 'register', 1, $context())->status, 'Timeout never no price'); $wire->fail = false;
$check = '<Domains><Domain><Name>example.com</Name><RRPCode>210</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP></Domain></Domains>';
$wire->payloads['Check'] = $check;
$result = $driver->availability(new Query('example.com', Action::Register, 1), $context());
same(true, $result->evidence()->available, 'Recognized available response'); same(false, $result->evidence()->premium, 'All exclusions known false');
foreach (['<Properties/>', "<Properties> \n\t </Properties>"] as $empty) {
    $wire->payloads['Check'] = str_replace('</Domain>', $empty.'</Domain>', $check);
    foreach ([1, 10] as $years) {
        $result = $driver->availability(new Query('example.com', Action::Register, $years), $context());
        same(Disposition::Accepted, $result->disposition, 'Empty Properties keeps default 1..10 registration bounds');
        same(true, $result->evidence()->available, 'Empty Properties keeps normal availability');
        same(false, $result->evidence()->premium, 'Empty Properties keeps explicit premium exclusions');
    }
    $wire->payloads['Check'] = str_replace('210', '211', $wire->payloads['Check']);
    $result = $driver->availability(new Query('example.com', Action::Transfer, 1), $context());
    same(Disposition::Accepted, $result->disposition, 'Transfer uses the same empty Properties normalization');
    same(false, $result->evidence()->available, 'Empty Properties never invents positive transfer eligibility');
}
$wire->payloads['Check'] = str_replace('</Domain>', '<Properties><MinRegYear>1</MinRegYear><MaxRegYear>1</MaxRegYear></Properties></Domain>', $check);
same(Disposition::Rejected, $driver->availability(new Query('example.com', Action::Register, 3), $context())->disposition, 'Name-specific term restriction closes multi-year checkout');
same(true, $driver->availability(new Query('example.com', Action::Register, 1), $context())->evidence()->available, 'Allowed name-specific term retained');
$wire->payloads['Check'] = str_replace('</Domain>', '<Properties><MinRegYear>bad</MinRegYear></Properties></Domain>', $check);
same(Disposition::Unknown, $driver->availability(new Query('example.com', Action::Register, 1), $context())->disposition, 'Malformed term restriction is unknown');
$wire->payloads['Check'] = str_replace('</Domain>', '<Properties><MinRegYear>2</MinRegYear><MaxRegYear>5</MaxRegYear></Properties></Domain>', $check);
same(Disposition::Rejected, $driver->availability(new Query('example.com', Action::Register, 1), $context())->disposition, 'Nested minimum excludes one year');
same(true, $driver->availability(new Query('example.com', Action::Register, 3), $context())->evidence()->available, 'Nested allowed multi-year term retained');
$wire->payloads['Check'] = str_replace('</Domain>', str_repeat('<Properties><MinRegYear>2</MinRegYear></Properties>', 2).'</Domain>', $check);
same(Disposition::Unknown, $driver->availability(new Query('example.com', Action::Register, 1), $context())->disposition, 'Repeated properties cannot bypass the minimum');
$wire->payloads['Check'] = str_replace('</Domain>', '<Properties>invalid</Properties></Domain>', $check);
same(Disposition::Unknown, $driver->availability(new Query('example.com', Action::Register, 1), $context())->disposition, 'Scalar properties are unknown');
same(Disposition::Unknown, $driver->availability(new Query('example.com', Action::Transfer, 1), $context())->disposition, 'Scalar transfer properties are unknown');
$wire->payloads['Check'] = str_replace('</Domain>', '<MinRegYear>5</MinRegYear><MaxRegYear>1</MaxRegYear></Domain>', $check);
same(true, $driver->availability(new Query('example.com', Action::Register, 3), $context())->evidence()->available, 'Unrelated top-level fields do not replace nested restrictions');
$wire->payloads['Check'] = str_replace('<IsEAP>0', '<IsEAP>1', $check); same(true, $driver->availability(new Query('example.com', Action::Register, 1), $context())->evidence()->premium, 'EAP is premium');
$wire->payloads['Check'] = str_replace('<IsEAP>0</IsEAP>', '', $check); same(null, $driver->availability(new Query('example.com', Action::Register, 1), $context())->evidence()->premium, 'Absent exclusion is unknown');
foreach ([str_replace('example.com', 'other.com', $check), str_replace('210', '999', $check), str_replace('</Domains>', substr($check, 9, -10).'</Domains>', $check)] as $bad) {
    $wire->payloads['Check'] = $bad; same(Disposition::Unknown, $driver->availability(new Query('example.com', Action::Register, 1), $context())->disposition, 'Bad Check cardinality/domain/status');
}
$info = '<GetDomainInfo><DomainName DomainNameID="123" SLD="example" TLD="com">example.com</DomainName><Status><Belongs-To>SyNtHeTiC-uId</Belongs-To><Expiration>2027-04-03</Expiration></Status></GetDomainInfo>';
$wire->payloads['Info'] = $info; $wire->payloads['Renewal'] = '<auto-renew>1</auto-renew>'; $wire->payloads['Lock'] = '<reg-lock>0</reg-lock>';
$wire->payloads['Privacy'] = '<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>1</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>';
$domain = $driver->readDomain('example.com', $context())->evidence()->domain;
same(true, $domain?->owned, 'Exact domain/UID/ID prove ownership'); same('123', $domain?->providerReference, 'Provider ID retained privately');
same('2027-04-03', $domain?->expiry?->value, 'Source expiry normalized'); same(ExpiryPrecision::Date, $domain?->expiry?->precision, 'Date-only never invented midnight');
same(true, $domain?->providerAutoRenew, 'Auto-renew independently read'); same(false, $domain?->locked, 'Known false lock preserved'); same(false, $domain?->privacy, 'Known false privacy preserved');
$wire->payloads['Renewal'] = '<auto-renew>1</auto-renew><renewname>0</renewname>';
$wire->payloads['Privacy'] = '<GetWPPSInfo><WPPSAllowed>0</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>1</WPPSEnabled></GetWPPSInfo>';
$domain = $driver->readDomain('example.com', $context())->evidence()->domain; same(null, $domain?->providerAutoRenew, 'Conflicting renew flags unknown'); same(null, $domain?->privacy, 'Contradictory privacy unknown');
$before = count($wire->calls); $partial = $driver->readDomain('example.com', $context(1)); $domain = $partial->evidence()->domain;
same(Disposition::Unknown,$partial->disposition,'Incomplete flags never grant admission'); same(SendState::PossiblySent,$partial->sendState,'Budget failure provenance retained');
same(true, $domain?->owned, 'Partial read preserves proven owner'); same(null, $domain?->locked, 'Budget exhaustion leaves flag unknown'); same($before + 1, count($wire->calls), 'Each extra read checks shared budget');
foreach ([str_replace('example.com', 'other.com', $info), str_replace('SLD="example"', 'SLD="other"', $info), str_replace('DomainNameID="123"', 'DomainNameID="0"', $info), str_replace('</GetDomainInfo>', '<DomainNameID>456</DomainNameID></GetDomainInfo>', $info), str_replace('<Belongs-To>SyNtHeTiC-uId</Belongs-To>', '', $info)] as $bad) {
    $wire->payloads['Info'] = $bad; same(Disposition::Unknown, $driver->readDomain('example.com', $context())->disposition, 'Unproven ownership never positive');
}
$wire->payloads['Info'] = str_replace('SyNtHeTiC-uId', 'other-owner', $info); $before = count($wire->calls);
$domain = $driver->readDomain('example.com', $context())->evidence()->domain;
same(false, $domain?->owned, 'Explicit other owner retained'); same(null, $domain?->expiry, 'Other-account private facts withheld'); same($before + 1, count($wire->calls), 'Other owner triggers no further reads');
$parser = new ResponseParser;
foreach ([['2027-01-03 12:00:00', '2027-01-03T20:00:00+00:00'], ['2027-07-03 12:00:00', '2027-07-03T19:00:00+00:00']] as [$date, $utc]) {
    $expiry = Expiry::read($parser->parse(ReadCommand::Info, body('GetDomainInfo', '<RegistryExpDate>'.$date.'</RegistryExpDate>'))); same($utc, $expiry?->value, 'Source Pacific/DST converted to UTC');
}
$expiry = Expiry::read($parser->parse(ReadCommand::Info, body('GetDomainInfo', '<ExpirationDate utc="2027-03-02 12:00:00">2027-03-02 04:00:00</ExpirationDate>'))); same('2027-03-02T12:00:00+00:00', $expiry?->value, 'UTC attribute authoritative');
foreach (['<RegistryExpDate>2027-02-30</RegistryExpDate>', '<RegistryExpDate>2027-03-02</RegistryExpDate><ExpirationDate>2027-03-03</ExpirationDate>', '<ExpirationDate utc="bad">2027-03-02</ExpirationDate>'] as $bad) {
    same(null, Expiry::read($parser->parse(ReadCommand::Info, body('GetDomainInfo', $bad))), 'Invalid/conflicting expiry unknown');
}
$wire->payloads['Info'] = $info;
$contact = '<GetContacts><Registrant><RegistrantFirstName>private-name</RegistrantFirstName><EmailAddress/><Phone source="registry">+1.5551234</Phone></Registrant><Tech><TechContactMatch>NotCollected</TechContactMatch></Tech><Billing><FirstName>account-only</FirstName></Billing><PendingVerification>1</PendingVerification></GetContacts>';
$wire->payloads['Contacts'] = $contact; $result = $driver->contacts('example.com', $context()); $contacts = $result->facts();
same('private-name', $contacts['contacts']['registrant']['first_name'], 'Source prefixed contact read'); same('', $contacts['contacts']['registrant']['email_address'], 'Explicit empty distinct from missing'); same(null, $contacts['contacts']['admin'], 'Missing role stays unknown'); same(false, $contacts['collected']['tech'], 'NotCollected role preserved'); expect(!isset($contacts['contacts']['billing']), 'Account billing never domain role');
same(true, $contacts['pending_verification'], 'Verification flag independent');
$diagnostic = print_r($result, true).json_encode($result).var_export((array) $result, true);
foreach (['private-name', '5551234', 'account-only'] as $private) { expect(!str_contains($diagnostic, $private), 'Contact data excluded from diagnostics'); }
$wire->payloads['Contacts'] = str_replace('<RegistrantFirstName>private-name</RegistrantFirstName>', '<RegistrantFirstName>private-name</RegistrantFirstName><FirstName>conflict</FirstName>', $contact);
same('unknown', $driver->contacts('example.com', $context())->status, 'Conflicting contact aliases unknown');
$before = count($wire->calls); same(Disposition::Unknown, $driver->quote(new Query('example.com', Action::Register, 1), $context())->disposition, 'Missing quote price stays unknown');
$before = count($wire->calls);
foreach (['true' => true, 'false' => false, 'unknown' => false] as $flag => $eligible) {
    $wire->payloads['Check'] = '<Domains><Domain><Name>example.com</Name><RRPCode>211</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Properties><MinRegYear>2</MinRegYear><Transferable>'.$flag.'</Transferable></Properties></Domain></Domains>';
    same($eligible, $driver->availability(new Query('example.com', Action::Transfer, 1), $context())->evidence()->available, 'Transfer needs explicit positive property');
}
$before = count($wire->calls);
try { $driver->balance(new RequestContext(new AccountBinding('enom', 2, Environment::Test, 'other'), RequestOrigin::Background, new DateTimeImmutable('+1 hour'), new RequestBudget(1))); throw new LogicException('Expected binding refusal'); } catch (InvalidArgumentException) {}
same($before, count($wire->calls), 'Wrong binding never dispatched');
echo "Enom reads: catalogue, exact lexical price, ownership, expiry, nullable flags and contact isolation passed.\n";
$wire->payloads['Expiry'] = '<RegistryExpDate>2027-04-03</RegistryExpDate>';
same('2027-04-03', $driver->expiry('example.com', $context())->facts()['expiry']->value, 'Named GetDomainExp strict date read');
$wire->payloads['Expiry'] = '<RegistryExpDate>bad</RegistryExpDate>'; same('unknown', $driver->expiry('example.com', $context())->status, 'Invalid GetDomainExp not acknowledged');
$wire->payloads['RenewalInfo'] = '<GetExtendInfo><Expiration>2027-04-03</Expiration><RegistrarHold>false</RegistrarHold><MaxExtension>9</MaxExtension></GetExtendInfo>';
$facts = $driver->renewalInfo('example.com', $context())->facts(); same(9, $facts['max_extension'], 'Bounded renewal maximum'); same(false, $facts['registrar_hold'], 'Known false hold'); same(null, $facts['grace_eligible'], 'Restorable/expiry never infer ordinary grace');
$wire->payloads['Info'] = str_replace(['example.com', 'TLD="com"'], ['example.co.uk', 'TLD="co.uk"'], $info);
$before = count($wire->calls); $driver->contacts('example.co.uk', $context());
same(['SLD' => 'example', 'TLD' => 'co.uk'], end($wire->calls)[1], 'Explicit longest compound suffix reaches named command');
try { $driver->readDomain('sub.example.com', $context()); throw new LogicException('Expected subdomain refusal'); } catch (InvalidArgumentException) {}
same($before + 2, count($wire->calls), 'Subdomain cannot be mistaken for registrable SLD');

foreach (['', '<Attributes>malformed</Attributes>', '<Attributes><Metadata>unknown</Metadata></Attributes>'] as $bad) {
    $wire->payloads['Attributes'] = $bad; same('unknown', $driver->registrySchema('com', $context())->status, 'Missing/malformed schema never optional');
}
$wire->fail = true;
same(SendState::PossiblySent, $driver->balance($context())->sendState, 'Timeout preserves possibly sent state');
$wire->fail = false; $wire->reject = true;
same(SendState::Sent, $driver->balance($context())->sendState, 'Provider refusal proves read was sent, never financial rejection');
$wire->reject = false;
same(SendState::PossiblySent, $driver->balance($context(0))->sendState, 'Context refusal never proves historical financial rejection');
$wire->payloads['Check'] = '<Domains><Domain><Name>example.com</Name></Domain></Domains>';
same(Disposition::Unknown, $driver->availability(new Query('example.com', Action::Register, 1), $context())->disposition, 'Missing Check code unknown');

foreach ([str_replace('</Requires>', '</Requires><Requires><Attribute><Name>missing_child</Name></Attribute></Requires>', $field),
    str_replace('<Requires><Attribute><Name>country_detail</Name></Attribute></Requires>', '<Requires><Unknown>lost</Unknown></Requires>', $field),
    str_replace('<Options>', '<Options><Unknown>lost</Unknown>', $field),
    str_replace('</Options>', '</Options><Options><Option><Value>duplicate</Value></Option></Options>', $field)] as $bad) {
    $wire->payloads['Attributes'] = '<Attributes>'.$bad.$child.'</Attributes>';
    same('unknown', $driver->registrySchema('com', $context())->status, 'Malformed/repeated schema containers cannot drop requirements');
}
$wire->payloads['Attributes'] = '<Attributes>'.str_replace('<Requires><Attribute><Name>country_detail</Name></Attribute></Requires>', '<Requires/>', $field).$child.'</Attributes>';
same([], $driver->registrySchema('com', $context())->facts()['fields'][0]['options'][0]['requires'], 'Explicit empty dependencies retained');

$wire->payloads['Info'] = str_replace('DomainNameID="123"', 'DomainNameID="123" domainnameid="456"', $info);
same(Disposition::Unknown, $driver->readDomain('example.com', $context())->disposition, 'Case-folded duplicate provider attributes cannot overwrite identity');

foreach (['', ' ', 'bad dependency', '0'] as $invalid) {
    $wire->payloads['Attributes'] = '<Attributes>'.str_replace('<Name>country_detail</Name>', '<Name>'.$invalid.'</Name>', $field).$child.'</Attributes>';
    same('unknown', $driver->registrySchema('com', $context())->status, 'Malformed dependency names cannot disappear');
}
$wire->payloads['Attributes'] = '<ResponseCount>0</ResponseCount><MinPeriod>1</MinPeriod><MaxPeriod>10</MaxPeriod>';
$emptySchema = $driver->registrySchema('com', $context());
same('success', $emptySchema->status, 'Frozen .com no-Attributes response is a known empty schema');
same([], $emptySchema->facts()['fields'], 'Known empty schema has no invented fields');
same(true, $emptySchema->facts()['supported'], 'Known empty schema remains supported');
foreach (['<ResponseCount>1</ResponseCount>', '<ResponseCount>bad</ResponseCount>', '<ResponseCount>0</ResponseCount><ResponseCount>0</ResponseCount>'] as $ambiguous) {
    $wire->payloads['Attributes'] = $ambiguous;
    same('unknown', $driver->registrySchema('com', $context())->status, 'Absent attributes without the known empty envelope stay unknown');
}

foreach (['expiry' => ReadCommand::Expiry, 'renewalInfo' => ReadCommand::RenewalInfo, 'contacts' => ReadCommand::Contacts] as $method => $command) {
    foreach ([str_replace('SyNtHeTiC-uId', 'retail-subaccount', $info), str_replace('example.com', 'other.com', $info),
        str_replace('DomainNameID="123"', 'DomainNameID="0"', $info), str_replace('<Belongs-To>SyNtHeTiC-uId</Belongs-To>', '', $info)] as $unowned) {
        $wire->payloads['Info'] = $unowned; $before = count($wire->calls);
        same('unknown', $driver->$method('example.com', $context())->status, 'Private read refuses unproven ownership');
        same($before + 1, count($wire->calls), 'Unproven owner allows only identity read');
        same(ReadCommand::Info, end($wire->calls)[0], 'No private read dispatched for another owner');
    }
    $wire->payloads['Info'] = $info; $before = count($wire->calls);
    same('unknown', $driver->$method('example.com', $context(1))->status, 'Private read cannot bypass ownership budget');
    same($before + 1, count($wire->calls), 'Ownership consumes shared read budget');
    $wire->payloads['Expiry'] = '<RegistryExpDate>2027-04-03</RegistryExpDate>';
    $wire->payloads['Contacts'] = $contact; $before = count($wire->calls);
    $read = $driver->$method('example.com', $context(2));
    same('success', $read->status, 'Owned private read needs exactly identity plus named request');
    same($before + 2, count($wire->calls), 'No unrelated flag reads needed for private read');
    same($command, end($wire->calls)[0], 'Owned private read reaches exact named command');
    same('example.com', $read->facts()['name'], 'Private read retains proven name');
    same('123', $read->facts()['provider_reference'], 'Private read retains proven provider identity');
    same(true, $read->facts()['owned'], 'Private facts carry positive ownership marker');
}
