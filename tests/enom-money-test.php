<?php
// Synthetic scenarios ported from Enom 444f31c registrar-money/catalogue-contracts/renewal-admission.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, CostUnit, DispatchSeal, Discrepancy, Disposition,
    DriverResult, Environment, Evidence, ExecutionRecord, ExpiryPrecision, Money, Query, QuoteEvidence, RequestBudget, RequestContext, RequestOrigin, ResultReason, RenewalStatus, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ProtocolResult, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Driver, OrderCharge, Pricing};
$clock = new class implements Clock { public DateTimeImmutable $at; public function now(): DateTimeImmutable { return $this->at; } };
$clock->at = new DateTimeImmutable('2026-10-18T12:00:00Z');
$binding = new AccountBinding('enom', 1, Environment::Test, 'offline');
$context = fn (int $budget = 8) => new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+1 hour'), new RequestBudget($budget), $clock);
$wire = new class implements Transport {
    public array $data = []; public array $calls = []; public bool $fail = false; public bool $reject = false; public ?ReadCommand $failCommand = null;
    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        $this->calls[] = [$command, $parameters];
        if ($this->fail || $this->failCommand === $command) { throw new RuntimeException('private-token'); }
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>'.($this->reject ? '1' : '0').'</ErrCount><Done>true</Done>'.($this->data[$command->name] ?? '').'</interface-response>';
    }
};
$driver = new Driver(new Client($binding, 'offline', 'private-token', $wire), ['com']);
$query = new Query('example.com', Action::Register, 3);
$wire->data['ProductPrice'] = '<ProductPrice><ProductEnabled>true</ProductEnabled><Price>8.9550</Price></ProductPrice>';
$quote = $driver->quote($query, $context());
same('26.8650', $quote->evidence()->quote?->cost?->amount, 'Selected-term annual rate multiplied exactly without rounding');
same(CostUnit::TermTotal, $quote->evidence()->quote?->unit, 'Neutral quote total has exact units');
same(null, $quote->evidence()->premium, 'Catalogue cost never proves ordinary-name eligibility');
same(['TLD' => 'com', 'ProductType' => 10, 'Years' => 3], end($wire->calls)[1], 'Exact selected term sent');
$rate = Pricing::catalogue($quote, $binding, $query, $clock->now());
same('8.9550', $rate->perYear, 'Quote maps back to exact per-year selected-term rate');
same('2026-10-18T12:05:00+00:00', $rate->freshUntil?->format(DATE_ATOM), 'Explicit local five-minute freshness');
same('8.9550', $driver->catalogueRate('com', 'register', 3, $context(), new \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness(86400, 86400))->perYear, 'Direct source rate mapping');
foreach (['0' => '0.0000', '0008.95' => '8.9500', '8.95000000' => '8.9500'] as $input => $expected) {
    same($expected, Pricing::annual((string) $input), 'Exact zero/padding normalization');
}
foreach ([1.2, null, '8.95000001', '8.95001', '-1', '1e2', '1,000', '1.000000000'] as $invalid) { same(null, Pricing::annual($invalid), 'Unsupported decimal fails closed'); }
foreach (['8.95000', '8.95000000', '8.95001', '8.95000001'] as $unsupported) {
    $wire->data['ProductPrice'] = '<ProductPrice><ProductEnabled>true</ProductEnabled><Price>'.$unsupported.'</Price></ProductPrice>';
    same('unknown', $driver->productPrice('com', 'register', 3, $context())->status, 'Unsupported precision is failed read, never withdrawal');
}
$wire->data['ProductPrice'] = '<ProductPrice><ProductEnabled>false</ProductEnabled></ProductPrice>';
same(null, $driver->catalogueRate('com', 'register', 3, $context(), new \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness(86400, 86400))->perYear, 'Source confirmed no-price alone withdraws');
same(Disposition::Unknown, $driver->quote($query, $context())->disposition, 'No-price is not a known zero quote');
$wire->fail = true;
try { $driver->catalogueRate('com', 'register', 3, $context(), new \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness(86400, 86400)); throw new LogicException('Expected failed read'); } catch (DomainException) {}
$wire->fail = false; $wire->reject = true;
try { $driver->catalogueRate('com', 'register', 3, $context(), new \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness(86400, 86400)); throw new LogicException('Expected failed read'); } catch (DomainException) {}
$wire->reject = false;
$wire->data['ProductPrice'] = '<ProductPrice><ProductEnabled>true</ProductEnabled><Price>0.0000</Price></ProductPrice>';
same('0.0000', $driver->catalogueRate('com', 'renew', 1, $context(), new \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness(86400, 86400))->perYear, 'Known annual zero survives');
$wire->data['ProductPrice'] = str_replace('</ProductPrice>', '<Currency>CAD</Currency></ProductPrice>', $wire->data['ProductPrice']);
same('unknown', $driver->productPrice('com', 'renew', 1, $context())->status, 'Currency contradiction cannot withdraw');
foreach ([['query' => new Query('other.com', Action::Register, 3)], ['currency' => 'CAD'], ['cost' => null], ['total' => '26.865001'],
    ['total' => '26.8651'], ['at' => $clock->now()->modify('+1 second')], ['until' => $clock->now()]] as $fault) {
    $q = $fault['query'] ?? $query; $at = $fault['at'] ?? $clock->now();
    $cost = array_key_exists('cost', $fault) ? null : new Money($fault['total'] ?? '26.8650', $fault['currency'] ?? 'USD');
    $bad = new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
        new Evidence(quote: new QuoteEvidence($q, $cost, CostUnit::TermTotal, 'enom_product_price', $at, $fault['until'] ?? null)));
    try { Pricing::catalogue($bad, $binding, $query, $clock->now()); throw new LogicException('Expected refusal'); } catch (DomainException) {}
}
try { Pricing::catalogue($quote, new AccountBinding('enom', 2, Environment::Test, 'offline'), $query, $clock->now()); throw new LogicException('Expected binding refusal'); } catch (InvalidArgumentException) {}
$info = '<GetDomainInfo><DomainName DomainNameID="456">example.com</DomainName><Status><Belongs-To>offline</Belongs-To><Expiration>10/12/2028 11:14:00 PM</Expiration></Status></GetDomainInfo>';
$extend = '<GetExtendInfo><Expiration>10/12/2028</Expiration><Price>8.95</Price><RegistrarHold>False</RegistrarHold><MaxExtension>8</MaxExtension></GetExtendInfo>';
$check = '<Domains><Domain><Name>example.com</Name><RRPCode>211</RRPCode><IsPremium>0</IsPremium><IsPlatinum>0</IsPlatinum><IsEAP>0</IsEAP><Prices><Currency/><Renewal>8.95</Renewal></Prices></Domain></Domains>';
$wire->data += ['Info' => $info, 'RenewalInfo' => $extend, 'Check' => $check];
$renew = new Query('example.com', Action::Renew, 2);
$result = $driver->renewalAssessment($renew, $context(3));
same(RenewalStatus::Ordinary, $result->evidence()->renewal, 'Owned precise future expiry and limits establish ordinary provider state');
same('17.9000', $result->evidence()->quote?->cost?->amount, 'Documented annual renewal price gives exact selected total');
same('2028-10-13T06:14:00+00:00', $result->evidence()->domain?->expiry?->value, 'Pacific date reconciles against authenticated precise UTC next day');
same(ExpiryPrecision::Instant, $result->evidence()->domain?->expiry?->precision, 'No date precision lost');
foreach (['foreign' => str_replace('offline', 'other', $info), 'date' => str_replace('10/12/2028 11:14:00 PM', '10/12/2028', $info)] as $fault => $payload) {
    $wire->data['Info'] = $payload; $before = count($wire->calls);
    same(RenewalStatus::Unknown, $driver->renewalAssessment($renew, $context())->evidence()->renewal, 'Unproved owner/date precision cannot establish renewal eligibility');
    if ($fault === 'foreign') { same($before + 1, count($wire->calls), 'No private follow-up for foreign ownership'); }
}
foreach (['2028-10-13T00:00:00Z', '2028-10-13T06:14:00Z', '2028-10-13T06:59:59.999999Z'] as $instant) {
    $wire->data['Info'] = str_replace('<Expiration>', '<Expiration utc="'.$instant.'">', $info);
    $result = $driver->renewalAssessment($renew, $context());
    same(RenewalStatus::Unknown, $result->evidence()->renewal, 'Unobserved UTC attribute cannot pass scalar-only expiry reconciliation');
    same(null, $result->evidence()->quote, 'Unproved expiry shape cannot establish complete renewal quote');
}
$wire->data['Info'] = str_replace('10/12/2028 11:14:00 PM', '2028-10-13T06:14:00.000001Z', $info);
same(RenewalStatus::Unknown, $driver->renewalAssessment($renew, $context())->evidence()->renewal, 'Unsupported fractional expiry cannot be silently truncated');
$wire->data['Info'] = $info;
foreach ([str_replace('10/12/2028', '10/13/2028', $extend), str_replace('False', 'unknown', $extend),
    str_replace('</GetExtendInfo>', '</GetExtendInfo><RegistryExpDate>2028-10-12T08:00:00Z</RegistryExpDate>', $extend)] as $bad) {
    $wire->data['RenewalInfo'] = $bad;
    same(RenewalStatus::Unknown, $driver->renewalAssessment($renew, $context())->evidence()->renewal, 'Conflicting expiry/unknown hold never ordinary');
}
$wire->data['RenewalInfo'] = $extend;
$wire->data['Check'] = str_replace('<Currency/>', '<Currency>CAD</Currency>', $check);
same(null, $driver->renewalAssessment($renew, $context())->evidence()->quote, 'Foreign currency is not USD quote');
$wire->data['Check'] = str_replace('<Renewal>8.95', '<Renewal>9.95', $check);
same(null, $driver->renewalAssessment($renew, $context())->evidence()->quote, 'Contradictory annual prices remain unknown');
$wire->data['Check'] = $check;
$failed = $driver->quote($renew, $context(1));
same(Disposition::Unknown, $failed->disposition, 'Required renewal read budget failure is Unknown');
same(RenewalStatus::Unknown, $failed->evidence()->renewal, 'Budget exhaustion cannot authorize renewal');
foreach ([['8', '1', RenewalStatus::Ineligible], ['False', 'True', RenewalStatus::Ineligible]] as [$from, $to, $status]) {
    $wire->data['RenewalInfo'] = str_replace($from, $to, $extend);
    same($status, $driver->renewalAssessment($renew, $context())->evidence()->renewal, 'Term and unexpired hold refusals retain source admission semantics');
}
$wire->data['RenewalInfo'] = str_replace('8.95', '100000000.00', $extend);
$wire->data['Check'] = str_replace('8.95', '100000000.00', $check);
same(null, $driver->renewalAssessment($renew, $context())->evidence()->quote, 'Oversized price is unknown cost without arithmetic exception');
$wire->data['RenewalInfo'] = $extend; $wire->data['Check'] = $check;
$clock->at = new DateTimeImmutable('2028-10-14T12:00:00Z');
$wire->data['Expired'] = '<DomainCount>1</DomainCount><DomainDetail><DomainName>example.com</DomainName><DomainNameID>456</DomainNameID><Status>Expired</Status><Expiration-Date>10/13/2028</Expiration-Date></DomainDetail>';
same(RenewalStatus::Ineligible, $driver->renewalAssessment($renew, $context())->evidence()->renewal, 'Grace is one year only');
$annual = new Query('example.com', Action::Renew, 1);
same(RenewalStatus::Grace, $driver->renewalAssessment($annual, $context(4))->evidence()->renewal, 'Exact expired listing corroborates grace');
foreach ([str_replace('456', '457', $wire->data['Expired']), str_replace('Expired', 'Restorable', $wire->data['Expired']), str_replace('Expired', 'Redemption', $wire->data['Expired']), ''] as $bad) {
    $saved = $wire->data['Expired']; $wire->data['Expired'] = $bad;
    same(RenewalStatus::Unknown, $driver->renewalAssessment($annual, $context())->evidence()->renewal, 'Unknown/restorable/foreign listing never grace');
    $wire->data['Expired'] = $saved;
}
$wire->data['RenewalInfo'] = str_replace('False', 'True', $extend);
same(RenewalStatus::Grace, $driver->renewalAssessment($annual, $context())->evidence()->renewal, 'Expired grace can retain hold as in source admission');
$wire->data['RenewalInfo'] = $extend;
foreach (['2028-10-12T23:30:00-07:00', '2028-10-13T06:30:00Z'] as $instant) {
    $clock->at = new DateTimeImmutable($instant);
    same(RenewalStatus::Grace, $driver->renewalAssessment($annual, $context())->evidence()->renewal, 'Same expired instant reads inventory in local and UTC clock zones');
}
$saved = $wire->data['Expired'];
$wire->data['Expired'] = '<DomainCount>201</DomainCount>'.str_repeat('<DomainDetail><DomainName>example.com</DomainName></DomainDetail>', 201);
same(RenewalStatus::Unknown, $driver->renewalAssessment($annual, $context())->evidence()->renewal, 'Over-budget expired inventory never establishes grace');
$wire->data['Expired'] = $saved;
foreach ([ReadCommand::RenewalInfo, ReadCommand::Check, ReadCommand::Expired] as $command) {
    $wire->failCommand = $command;
    same(Disposition::Unknown, $driver->quote($annual, $context())->disposition, 'Every required renewal read failure reports Unknown');
}
$wire->failCommand = null;
$execution = new ProtocolResult('success', data: ['command' => 'Purchase', 'orderid' => '9001']);
$attempt = fn (Action $action = Action::Register, ?Money $expected = null, ExecutionRecord $record = ExecutionRecord::Recorded) => new AttemptEvidence(
    $binding, new Query('example.com', $action, 1), 'original', 1, $record, SendState::Sent, $clock->now(), $clock->now(),
    expectedCharge: $expected, seal: DispatchSeal::Sealed, providerReference: '9001');
$header = ['orderid' => '9001', 'result' => 'true', 'orderbillamount' => '$8.95', 'billdate' => '11/7/2026 5:40:05 AM',
    'orderdetail' => ['description' => 'example.com', 'producttype' => 'Register', 'quantity' => '1', 'status' => 'Successful', 'orderstatus' => 'Closed', 'amountpaid' => '8.95', 'expirydatetime' => '11/7/2027 5:37:00 AM']];
$order = fn (array $h) => new ProtocolResult('success', data: ['order' => $h]);
$candidate = OrderCharge::read($attempt(), $execution, $order($header));
same('8.95', $candidate?->charge?->amount, 'Single exact closed item proves actual USD charge');
same('2026-11-07T13:40:05+00:00', $candidate?->processedAt?->value, 'BillDate retains provider Pacific time semantics');
foreach (['orderid' => '9002', 'result' => 'false', 'orderbillamount' => '$9.95', 'currency' => 'EUR', 'orderdetail' => [$header['orderdetail'], $header['orderdetail']]] as $field => $value) {
    same(null, OrderCharge::read($attempt(), $execution, $order(array_replace($header, [$field => $value]))), 'Ambiguous header/split order has unknown charge');
}
foreach ([['description', 'other.com'], ['producttype', 'Domain'], ['quantity', '2'], ['status', 'Failed'], ['orderstatus', 'Open'],
    ['amountpaid', '8.950'], ['currency', ''], ['amountpaid', '$8.95']] as [$field, $value]) {
    $bad = $header; $bad['orderdetail'][$field] = $value;
    same(null, OrderCharge::read($attempt(), $execution, $order($bad)), 'Unbound/ambiguous item never charge proof');
}
foreach ([ExecutionRecord::Absent, ExecutionRecord::RecordedNull] as $record) { same(null, OrderCharge::read($attempt(record: $record), $execution, $order($header)), 'Absent/null execution cannot confirm charge'); }
$renewHeader = $header; $renewHeader['orderdetail']['producttype'] = 'Renew';
same('8.95', OrderCharge::read($attempt(Action::Renew), new ProtocolResult('success', data: ['command' => 'Extend', 'orderid' => '9001']), $order($renewHeader))?->charge?->amount, 'Exact Extend/Renew pair accepted');
same(null, OrderCharge::read($attempt(Action::Renew), new ProtocolResult('success', data: ['command' => 'UpdateExpiredDomains', 'orderid' => '9001']), $order($renewHeader)), 'Expired command has no invented item contract');
$wire->data['Order'] = '<Order><OrderID>9001</OrderID><Result>true</Result><OrderBillAmount>$8.95</OrderBillAmount><OrderDetail><Description>example.com</Description><ProductType>Register</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>8.95</AmountPaid></OrderDetail></Order>';
$result = $driver->orderCharge($attempt(expected: new Money('8.9400', 'USD')), $execution, $context(1));
same(Disposition::AcceptedWithDiscrepancy, $result->disposition, 'Actual versus authorized cost discrepancy retained');
same([Discrepancy::Charge], $result->discrepancies, 'Charge discrepancy is explicit');
same('8.95', $result->evidence()->charge?->amount, 'Actual charge never replaced by quote');
same(['OrderID' => '9001'], end($wire->calls)[1], 'Only named read for captured order');
$summary = json_encode($result).print_r($result, true).var_export((array) $result, true);
foreach (['example.com', '8.95', '9001', 'private-token'] as $private) { expect(!str_contains($summary, $private), 'Private charge evidence excluded from summaries'); }
echo "Enom money: exact terms, no-price versus failure, renewal evidence and bound actual charges passed.\n";
