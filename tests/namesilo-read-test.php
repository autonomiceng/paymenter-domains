<?php
// Synthetic public-contract shapes only; invented prices, identities and domains. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
ini_set('zend.exception_ignore_args', '0');

use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock, AdmittedRegistrarDriver};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, CostUnit, Disposition, DriverResult, Environment,
    Evidence, ExecutionRecord, ExpiryPrecision, Money, Query, QuoteEvidence, RequestBudget, RequestContext, RequestOrigin, ResultReason,
    SendState, Support, RenewalStatus};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver, Prices};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, PurchaseMapping,
    RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;

final class SyntheticNameSiloWire implements DeadlineTransport
{
    public array $calls = [];
    public array $fixtures;
    public bool $fail = false;
    public function __construct() { $this->fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true, 32, JSON_THROW_ON_ERROR); }
    public function send(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey): TransportResponse
    { throw new LogicException('Neutral reads must use their deadline.'); }
    public function sendWithin(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey, int $timeoutMs): TransportResponse
    {
        $this->calls[] = [$environment, $context, $request->command, $request->parameters(), $timeoutMs];
        if ($this->fail) { throw new RuntimeException('synthetic-private-key https://invalid.example/?key='.$apiKey); }
        return new TransportResponse(200, json_encode($this->fixtures[$request->command]));
    }
}
$clock = new class implements Clock {
    public DateTimeImmutable $time;
    public function __construct() { $this->time = new DateTimeImmutable('2026-10-18T12:00:00Z'); }
    public function now(): DateTimeImmutable { return $this->time; }
};
$wire = new SyntheticNameSiloWire;
$registry = new RegistrarRegistry;
$verifier = new class implements AccountVerifier {
    public function verify(Environment $environment, #[SensitiveParameter] array $credentials): string { return 'synthetic-stable-id'; }
};
$registry->register('namesilo', ['key'], $verifier, fn (AccountBinding $binding, array $credentials) => new Driver(
    new Client($binding, $credentials['key'], $wire), ['register', 'renew', 'transfer'], $clock));
[$binding] = $registry->verify('namesilo', 13, Environment::Test, ['key' => 'synthetic-private-key']);
$driver = $registry->reader($binding, ['key' => 'synthetic-private-key']);
expect(!$driver instanceof AdmittedRegistrarDriver, 'Reader never exposes writes');
$context = static fn (int $budget = 10, int $milliseconds = 30000, RequestOrigin $origin = RequestOrigin::Background): RequestContext =>
    new RequestContext($binding, $origin, $clock->now()->modify('+'.$milliseconds.' milliseconds'), new RequestBudget($budget), $clock);
$query = new Query('example.com', Action::Register, 1);
$reject = static function (callable $call): void {
    try { $call(); } catch (DomainException|InvalidArgumentException|LogicException|TypeError) { return; }
    throw new RuntimeException('Expected refusal.');
};
$caps = $driver->accountCapabilities($context());
same(Support::Unknown, $caps->get('register')->support, 'Price acceptance does not enable acquisition');
same(Support::Unknown, $caps->get('dns')->support, 'Unsupported/unverified management never enabled');
same('free', $caps->privacy->value, 'Free privacy semantics, separate from TLD eligibility');
$result = $driver->balance($context(1, 1750, RequestOrigin::Interactive));
same('123.45', $result->evidence()->balance?->amount, 'Exact balance');
same([WireEnvironment::Sandbox, WireContext::Interactive, 'getAccountBalance', [], 1750], end($wire->calls), 'Bound origin/environment/deadline');
$before = count($wire->calls);
foreach ([$context(0), $context(1, 0)] as $refused) { same(Disposition::Unknown, $driver->balance($refused)->disposition, 'Context refusal unknown'); }
$wrong = new RequestContext(new AccountBinding('namesilo', 14, Environment::Test, 'other'), RequestOrigin::Background,
    $clock->now()->modify('+1 minute'), new RequestBudget(2), $clock);
$reject(fn () => $driver->balance($wrong)); same(2, $wrong->budget->remaining(), 'Wrong binding consumes no token');
same($before, count($wire->calls), 'Refused context never dispatched');
$wire->fixtures['checkRegisterAvailability']['reply'] = ['code' => 300, 'detail' => 'synthetic', 'available' => [['domain' => 'example.com', 'price' => '12.34', 'duration' => 1]]];
same(null, $driver->availability($query, $context())->evidence()->premium, 'Absent premium remains null');
foreach ([0 => false, 1 => true] as $flag => $expected) {
    $wire->fixtures['checkRegisterAvailability']['reply']['available'][0]['premium'] = $flag;
    $facts = $driver->availability($query, $context())->evidence(); same($expected, $facts->premium, 'Known premium retained'); same(true, $facts->available, 'Available is independent of premium');
}
$wire->fixtures['checkRegisterAvailability']['reply']['available'][0]['domain'] = 'other.com';
same(Disposition::Unknown, $driver->availability($query, $context())->disposition, 'Cross-name response refused');
$wire->fixtures['checkTransferAvailability']['reply'] = ['code' => 300, 'detail' => 'synthetic', 'available' => [['domain' => 'example.com', 'price' => '12.34']]];
same(null, $driver->availability(new Query('example.com', Action::Transfer, 1), $context())->evidence()->premium, 'Transfer premium absent is unknown');
$quote = $driver->quote($query, $context()); $facts = $quote->evidence()->quote;
same('12.34', $facts?->cost?->amount, 'Account catalogue price exact'); same(CostUnit::Annual, $facts?->unit, 'Annual source unit');
same([], end($wire->calls)[3], 'No retail flag and no hypothetical quantity discount');
same('12.3400', Prices::rate($quote, $binding, $query, 'com', $clock)->perYear, 'Annual exact selected term maps into pricing lane');
$before = count($wire->calls);
$unsupported = $driver->quote(new Query('example.com', Action::Register, 3), $context());
same(Disposition::Rejected, $unsupported->disposition, 'Unsupported quote is a local read refusal');
same(SendState::DefinitelyUnsent, $unsupported->sendState, 'Unsupported quote was never sent');
same(ResultReason::Unsupported, $unsupported->reason, 'Never extrapolate catalogue into multiyear');
foreach ([$driver->availability(new Query('example.com', Action::Renew, 1), $context()), $driver->renewalAssessment($query, $context())] as $unsupported) {
    same(SendState::DefinitelyUnsent, $unsupported->sendState, 'Unsupported read never sent');
    same(ResultReason::Unsupported, $unsupported->reason, 'Unsupported read explicit');
}
$unaccepted = new Driver(new Client($binding, 'synthetic-private-key', $wire), clock: $clock);
$unverified = $unaccepted->quote($query, $context());
same(SendState::DefinitelyUnsent, $unverified->sendState, 'Unaccepted annual quote never sent');
same(ResultReason::Unverified, $unverified->reason, 'Account annual acceptance remains unverified');
same($before, count($wire->calls), 'Unverified terms make no read');
foreach ([Action::Renew, Action::Transfer] as $action) {
    $q = new Query('example.com', $action, 1); $r = $driver->quote($q, $context());
    same($action->value, Prices::rate($r, $binding, $q, 'com', $clock)->action, 'Exact annual action mapping');
}
$wire->fixtures['getPrices']['reply']['com']['registration'] = '0';
$zero = $driver->quote($query, $context());
same(Disposition::Unknown, $zero->disposition, 'Zero catalogue row is unavailable, never complimentary authority');
$reject(fn () => Prices::rate($zero, $binding, $query, 'com', $clock));
same(null, $zero->evidence()->quote, 'Unavailable catalogue cost is never zero');
// One malformed ending cannot poison valid rows, nor become a sale quote itself.
$wire->fixtures['getPrices']['reply']['com']['registration'] = '12.34';
$wire->fixtures['getPrices']['reply']['feedback'] = ['renew' => '12.34', 'transfer' => '12.34'];
same('12.34', $driver->quote($query, $context())->evidence()->quote?->cost?->amount, 'Valid ending survives a missing unrelated registration');
same(Disposition::Unknown, $driver->quote(new Query('example.feedback', Action::Register, 1), $context())->disposition, 'Quarantined ending refuses quote');
unset($wire->fixtures['getPrices']['reply']['com']['registration']);
same(Disposition::Unknown, $driver->quote($query, $context())->disposition, 'Malformed sold ending still refuses');
$wire->fixtures['getPrices']['reply']['com']['registration'] = '1e3';
$unknown = $driver->quote($query, $context()); $reject(fn () => Prices::rate($unknown, $binding, $query, 'com', $clock));
same(null, $unknown->evidence()->quote, 'Bad cost never null withdrawal');
$domain = $driver->readDomain('example.com', $context())->evidence()->domain;
same(true, $domain?->owned, 'Account-scoped domain observation'); same(null, $domain?->providerReference, 'No invented domain/order identifier');
same(ExpiryPrecision::Date, $domain?->expiry?->precision, 'Date-only precision retained'); same(null, $domain?->expiry?->timezone, 'No invented midnight/zone');
same(false, $domain?->providerAutoRenew, 'Known false auto-renew'); same(false, $domain?->privacy, 'Known false privacy');
$wire->fixtures['getDomainInfo']['reply']['expires'] = '2027-02-30';
same(null, $driver->readDomain('example.com', $context())->evidence()->domain?->expiry, 'Bad expiry stays unknown');
$wire->fixtures['getDomainInfo']['reply']['auto_renew'] = 'maybe';
same(Disposition::Unknown, $driver->readDomain('example.com', $context())->disposition, 'Unrecognized flags never become false');
$wire->fixtures['getDomainInfo']['reply']['auto_renew'] = 'No';
$assessment = $driver->renewalAssessment(new Query('example.com', Action::Renew, 1), $context());
same(Disposition::Unknown, $assessment->disposition, 'No ordinary/grace/restore claim'); same(RenewalStatus::Unknown, $assessment->evidence()->renewal, 'Renewal safety unverified');

$attempt = new AttemptEvidence($binding, $query, 'synthetic-operation', 1, ExecutionRecord::RecordedNull, SendState::PossiblySent,
    $clock->now()->modify('-1 hour'), $clock->now());
$reconcile = static fn (int $budget = 10) => $driver->reconcile($attempt, $context($budget));
$rec = $reconcile(); $rec->requireAttempt($attempt);
$list = array_values(array_filter($wire->calls, fn ($call) => $call[2] === 'listOrders')); $list = end($list);
same(['date_from' => '2026-10-17', 'date_to' => '2026-10-19'], $list[3], 'Unknown provider timezone padded on both sides');
same(Disposition::Unknown, $rec->disposition, 'One history candidate never resolves purchase');
$candidates = $rec->result()->evidence()->candidates;
same(1, count($candidates), 'Strict synthetic description matches'); same('123', $candidates[0]->providerReference, 'Observed history reference only');
same('12.34', $candidates[0]->charge?->amount, 'Candidate subtotal, never whole order total'); same(null, $candidates[0]->processedAt, 'Naive timestamp never assigned a zone');
same(null, $rec->result()->evidence()->charge, 'Candidate never direct charge proof');
same([], $reconcile(1)->result()->evidence()->candidates, 'List consumes one token before detail');
$wire->fixtures['orderDetails']['reply']['order_date'] = '2026-10-18';
same(ExpiryPrecision::Date, $reconcile()->result()->evidence()->candidates[0]->processedAt?->precision, 'Date-only history keeps precision');
$row = $wire->fixtures['orderDetails']['reply']['order_details'][0];
$wire->fixtures['orderDetails']['reply']['order_details'][] = $row;
same(2, count($reconcile()->result()->evidence()->candidates), 'Multiple candidates retained unresolved');
foreach ([['description' => 'other.com - registration'], ['years_qty' => '2'], ['description' => 'example.com - renewal']] as $mismatch) {
    $wire->fixtures['orderDetails']['reply']['order_details'] = [array_replace($row, $mismatch)];
    same([], $reconcile()->result()->evidence()->candidates, 'Cross-name/action/term never candidate');
}
$wire->fixtures['orderDetails']['reply']['order_details'] = [$row + ['credited_amount' => '12.34']];
same(null, $reconcile()->result()->evidence()->candidates[0]->charge, 'Credit never positive charge proof');
$wire->fixtures['orderDetails']['reply']['order_details'] = [array_replace($row, ['status' => 'New status'])];
same(null, $reconcile()->result()->evidence()->candidates[0]->charge, 'Unknown status never charge proof');
$wire->fixtures['listOrders']['reply']['order'] = [];
same([], $reconcile()->result()->evidence()->candidates, 'Zero candidates unresolved, not unsent');
$wire->fixtures['listOrders']['reply']['order'] = array_map(fn ($id) => ['order_number' => (string) $id], range(1, 21));
$before = count($wire->calls); same([], $reconcile()->result()->evidence()->candidates, 'Oversize discovery refuses whole search');
same($before + 2, count($wire->calls), 'Discovery bound makes no detail reads');
// Compound suffixes work through all read paths, independent of purchase mapping restrictions.
$wire->fixtures['getPrices']['reply']['co.uk'] = ['registration' => '8.25', 'renew' => '10.00', 'transfer' => '9.00'];
$wire->fixtures['getPrices']['reply']['com']['registration'] = '12.34';
$compoundQuery = new Query('example.co.uk', Action::Register, 1);
same('8.25', $driver->quote($compoundQuery, $context())->evidence()->quote?->cost?->amount, 'Compound quote uses literal suffix price');
$wire->fixtures['checkRegisterAvailability']['reply']['available'][0]['domain'] = 'example.co.uk';
same(true, $driver->availability($compoundQuery, $context())->evidence()->available, 'Compound availability read');
same(['domains' => 'example.co.uk'], end($wire->calls)[3], 'Compound availability mapping');
same(true, $driver->readDomain('example.co.uk', $context())->evidence()->domain?->owned, 'Compound holder read');
same(['domain' => 'example.co.uk'], end($wire->calls)[3], 'Compound holder mapping');
$wire->fail = true;
$result = $driver->balance($context()); same(Disposition::Unknown, $result->disposition, 'Throwing transport stays unknown');
$diagnostic = json_encode($result).print_r($result, true).var_export((array) $driver, true).print_r($driver, true).print_r($rec, true);
foreach (['synthetic-private-key', 'example.com', '12.34', 'synthetic-stable-id'] as $private) { expect(!str_contains($diagnostic, $private), 'Private evidence absent from diagnostics'); }
$reject(fn () => serialize($result)); $reject(fn () => serialize($driver));
$reject(fn () => (new Client($binding, 'synthetic-private-key', $wire))->read(PurchaseMapping::renew('example.com', 1, false), $context()));

// Deliberately constructed DTOs test bridge guards, not provider acceptance.
$quoteResult = static fn (Query $q, ?Money $cost, CostUnit $unit, DateTimeImmutable $observed, ?DateTimeImmutable $until = null): DriverResult =>
    new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
        new Evidence(quote: new QuoteEvidence($q, $cost, $unit, 'namesilo_exact_term', $observed, $until)));
$three = new Query('example.com', Action::Register, 3);
same('4.1234', Prices::rate($quoteResult($three, new Money('12.370200', 'USD'), CostUnit::TermTotal, $clock->now()), $binding, $three, 'com', $clock)->perYear, 'Exact division trims only zero excess digits');
foreach ([new Money('12.340000', 'USD'), new Money('12.370201', 'USD'), new Money('12.370200', 'EUR'), null] as $cost) {
    $reject(fn () => Prices::rate($quoteResult($three, $cost, CostUnit::TermTotal, $clock->now()), $binding, $three, 'com', $clock));
}
$reject(fn () => Prices::rate($quote, $binding, $three, 'com', $clock));
$reject(fn () => Prices::rate($quote, $wrong->binding, $query, 'com', $clock));
$reject(fn () => Prices::rate($quoteResult($query, new Money('1.00', 'USD'), CostUnit::Annual, $clock->now()->modify('+1 second')), $binding, $query, 'com', $clock));
$clock->time = $clock->now()->modify('+1 hour'); $reject(fn () => Prices::rate($quote, $binding, $query, 'com', $clock));

$annualImpersonation = new DriverResult($binding, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
    new Evidence(quote: new QuoteEvidence($three, new Money('12.00', 'USD'), CostUnit::TermTotal, 'namesilo_account_annual', $clock->now())));
$reject(fn () => Prices::rate($annualImpersonation, $binding, $three, 'com', $clock));

$reject(fn () => Prices::rate($quoteResult($query, new Money('1.00', 'USD'), CostUnit::Annual, $clock->now()), $binding, $query, 'net', $clock));
echo "NameSilo: bound deadline reads, annual account quotes, null premium, date precision and unresolved bounded candidates passed.\n";
