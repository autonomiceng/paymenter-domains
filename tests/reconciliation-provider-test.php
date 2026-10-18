<?php
// Synthetic history contracts, never live provider acceptance; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DispatchSeal, Environment, ExecutionRecord, HistoryAcceptance, Query, RequestBudget, RequestContext, RequestOrigin, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence;
$clock = new class implements Clock { public DateTimeImmutable $at; public function now(): DateTimeImmutable { return $this->at; } };
$start = new DateTimeImmutable('2026-10-18T12:00:00Z'); $clock->at = $start->modify('+1 hour');
$binding = new AccountBinding('namesilo', 1, Environment::Test, 'offline');
$wire = new class implements DeadlineTransport {
    public array $data; public array $calls = []; public array $parameters = [];
    public function __construct() { $this->data = json_decode(file_get_contents(__DIR__.'/fixtures/synthetic-success.json'), true); }
    public function send(WireEnvironment $e, WireContext $c, RegistrarRequest $r, string $key): TransportResponse { throw new LogicException; }
    public function sendWithin(WireEnvironment $e, WireContext $c, RegistrarRequest $r, string $key, int $ms): TransportResponse {
        $this->calls[] = $r->command; $this->parameters[$r->command] = $r->parameters();
        $reply = $r->command === 'orderDetails' ? ($this->data['by_id'][$r->parameters()['order_number']] ?? $this->data[$r->command]) : $this->data[$r->command];
        return new TransportResponse(200, json_encode($reply));
    }
};
$wire->data['getDomainInfo']['reply']['expires'] = '2027-10-18';
$wire->data['orderDetails']['reply']['order_date'] = '2026-10-18 05:00:01';
$driver = new Driver(new Client($binding, 'synthetic-private-key', $wire));
$policy = fn (int $lag = 0, int $retention = 86400) => new HistoryAcceptance($binding, 'America/Los_Angeles', $lag, $retention, 'namesilo_registration_v1');
$attempt = fn ($accepted = null) => new AttemptEvidence($binding, new Query('example.com', Action::Register, 1), 'original', 1,
    ExecutionRecord::RecordedNull, SendState::PossiblySent, $start, $start->modify('+300 seconds'), seal: DispatchSeal::Sealed, historyAcceptance: $accepted);
$read = function ($accepted = null, int $budget = 25) use ($attempt, $driver, $binding, $clock) {
    $a = $attempt($accepted); $ctx = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget($budget), $clock);
    return [$a, $driver->reconcile($a, $ctx)->result()->evidence()];
};
$complete = fn ($pair) => ReconciliationEvidence::completion($pair[0], $pair[1], $clock->now());
same(null, $complete($read()), 'No accepted account history contract, no proof');
$pair = $read($policy()); same(true, $pair[1]->historyComplete, 'Accepted flat complete response');
same('12.34', $complete($pair)['charge']['amount'], 'No-ID unique original attempt proves exact actual charge');
same('2026-10-18T05:00:01-07:00', $pair[1]->candidates[0]->processedAt->value, 'Accepted provider zone preserves precise original instant');
foreach ([86400, 4000] as $lag) { same(null, $complete($read($policy($lag))), 'History lag never proves completion'); }
$clock->at = $start->modify('+2 days'); same(null, $complete($read($policy())), 'History beyond accepted retention unresolved'); $clock->at = $start->modify('+1 hour');
$saved = $wire->data;
$east = new HistoryAcceptance($binding, 'Pacific/Kiritimati', 0, 86400, 'namesilo_registration_v1');
$wire->data['orderDetails']['reply']['order_date'] = '2026-10-19 02:00:01';
same('12.34', $complete($read($east))['charge']['amount'], 'Accepted eastern timezone binds both discovery and timestamp');
same('2026-10-18', $wire->parameters['listOrders']['date_from'], 'Discovery lower date uses accepted local window');
same('2026-10-20', $wire->parameters['listOrders']['date_to'], 'Discovery upper date covers exclusive local endpoint');
$wire->data = $saved;
foreach (['pager', 'next_page', 'truncated', 'unknown_pagination'] as $field) {
    $wire->data = $saved; $wire->data['listOrders']['reply'][$field] = '1'; same(null, $complete($read($policy())), 'Pagination/unknown envelope cannot establish completeness');
}
foreach (['pager', 'next_page', 'truncated', 'unknown_pagination'] as $field) {
    $wire->data = $saved; $wire->data['orderDetails']['reply'][$field] = '1'; same(null, $complete($read($policy())), 'Detail pagination cannot establish completeness');
}
foreach (['zero', 'multiple', 'credited', 'status', 'term', 'action', 'date', 'outside', 'expiry', 'wrong_id', 'missing_id'] as $fault) {
    $wire->data = $saved; $reply = &$wire->data['orderDetails']['reply']; $row = &$reply['order_details'][0];
    if ($fault === 'zero') { $wire->data['listOrders']['reply']['order'] = []; }
    if ($fault === 'multiple') { $reply['order_details'][] = $row; }
    if ($fault === 'credited') { $row['credited_amount'] = '12.34'; }
    if ($fault === 'status') { $row['status'] = 'New vocabulary'; }
    if ($fault === 'term') { $row['years_qty'] = '2'; }
    if ($fault === 'action') { $row['description'] = 'example.com - renewal'; }
    if ($fault === 'date') { $reply['order_date'] = '2026-10-18'; }
    if ($fault === 'outside') { $reply['order_date'] = '2026-10-18 05:06:00'; }
    if ($fault === 'expiry') { $wire->data['getDomainInfo']['reply']['expires'] = '2026-10-18'; }
    if ($fault === 'wrong_id') { $reply['order_number'] = '999'; }
    if ($fault === 'missing_id') { unset($reply['order_number']); }
    same(null, $complete($read($policy())), 'Zero/ambiguous/late/contradictory evidence stays unresolved: '.$fault);
    unset($reply, $row);
}
$wire->data = $saved; $wire->data['listOrders']['reply']['order'][] = ['order_number' => '902'];
$empty = $saved['orderDetails']; $empty['reply']['order_number'] = '902'; $empty['reply']['order_details'] = [];
$wire->data['by_id']['902'] = $empty;
same(null, $complete($read($policy())), 'Second positive-total order with empty details cannot disappear into unique completion');
foreach (['Example.com - registration', 'unrecognized-label'] as $description) {
    $wire->data = $saved; $other = $wire->data['orderDetails']['reply']['order_details'][0]; $other['description'] = $description;
    $wire->data['orderDetails']['reply']['order_details'][] = $other;
    same(null, $complete($read($policy())), 'Unrecognized or differently cased competing description cannot disappear');
}
$wire->data = $saved; $wire->data['orderDetails']['reply']['order_details'][0]['currency'] = 'EUR';
same(null, $complete($read($policy())), 'Contradictory/unknown detail vocabulary cannot be discarded into complete USD history');
$wire->data = $saved; $wire->data['getDomainInfo']['reply']['auto_renew'] = 'Yes';
same(['auto_renew'], $complete($read($policy()))['observed_corrections'], 'Financial proof retains separately authorized ancillary correction');
$wire->data = $saved; same(null, $complete($read($policy(), 1)), 'Exhausted shared budget cannot establish completion');
same(null, $policy()->date('2026-11-01 01:30:00'), 'Ambiguous fall-back wall time refused');
same(null, $policy()->date('2026-03-08 02:30:00'), 'Nonexistent spring-forward wall time refused');
same(null, $policy()->date('2026-02-30 12:00:00'), 'Invalid calendar date refused');
echo "No-ID histories: exact vocabulary, completeness, lag, retention, timezone and unique charge checks passed\n";
