<?php
// Synthetic complete-history and full-term source regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DateEvidence, DispatchSeal, DomainSnapshot, Environment, Evidence, ExecutionRecord, ExpiryPrecision, HistoryAcceptance, OrderEvidence, Query, RequestBudget, RequestContext, RequestOrigin, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\History;
use Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence;
$clock = new class implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-18T13:00:00Z'); } };
$binding = new AccountBinding('enom', 1, Environment::Test, 'offline'); $start = new DateTimeImmutable('2026-10-18T12:00:00Z');
$policy = new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1');
$query = new Query('example.com', Action::Register, 3);
$attempt = new AttemptEvidence($binding, $query, 'original', 1, ExecutionRecord::Absent, SendState::PossiblySent,
    $start, $start->modify('+300 seconds'), seal: DispatchSeal::Sealed, historyAcceptance: $policy);
$wire = new class implements Transport {
    public string $fault = ''; public int $calls = 0;
    public function send(Environment $e, ReadCommand $command, array $p, string $uid, string $token, int $ms): string {
        $this->calls++;
        if ($command === ReadCommand::Orders) {
            $competing = in_array($this->fault, ['undated', 'date', 'duplicate', 'foreign', 'case'], true);
            $data = '<OrderCount>'.($competing ? '2' : '1').'</OrderCount><OrderList><Order><OrderID>901</OrderID></Order>'.($competing ? '<Order><OrderID>902</OrderID></Order>' : '').'</OrderList>';
            if ($this->fault === 'row_pagination') { $data = str_replace('</OrderID>', '</OrderID><NextPage>2</NextPage>', $data); }
            if ($this->fault === 'pagination') { $data .= '<NextPage>2</NextPage>'; }
            if ($this->fault === 'unknown') { $data .= '<UnknownCompleteness>1</UnknownCompleteness>'; }
        } else {
            $date = ($p['OrderID'] === '902' && $this->fault === 'undated') ? '' : ($this->fault === 'date' && $p['OrderID'] === '902' ? '<BillDate>10/18/2026</BillDate>' : '<BillDate>10/18/2026 5:00:01 AM</BillDate>');
            $name = $p['OrderID'] === '902' && $this->fault === 'foreign' ? 'unrelated.com' : ($p['OrderID'] === '902' && $this->fault === 'case' ? 'Example.com' : 'example.com');
            $data = '<Order><OrderID>'.$p['OrderID'].'</OrderID><Result>true</Result><OrderBillAmount>$8.95</OrderBillAmount>'.$date.'<OrderDetail><Description>'.$name.'</Description><ProductType>Register</ProductType><Quantity>3</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>8.95</AmountPaid><ExpiryDatetime>2029-10-18</ExpiryDatetime></OrderDetail></Order>';
        }
        if ($command === ReadCommand::Order) {
            if ($this->fault === 'detail_root_pagination') { $data .= '<NextPage>2</NextPage>'; }
            if ($this->fault === 'header_pagination') { $data = str_replace('<OrderDetail>', '<NextPage>2</NextPage><OrderDetail>', $data); }
            if ($this->fault === 'item_metadata') { $data = str_replace('</OrderDetail>', '<UnknownCompleteness>1</UnknownCompleteness></OrderDetail>', $data); }
        }
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$data.'</interface-response>';
    }
};
$history = new History(new Client($binding, 'offline', 'token', $wire));
$domain = fn ($expiry) => new DomainSnapshot($binding, 'example.com', true, new DateEvidence($expiry, ExpiryPrecision::Date, 'synthetic'), false, providerReference: '456');
$read = function () use ($history, $attempt, $clock, $binding) { return $history->inspect($attempt, new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget(25), $clock)); };
$before = $wire->calls;
$limited = $history->inspect($attempt, new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget(1), $clock));
same(false, $limited->historyComplete, 'Insufficient remaining detail budget cannot certify completeness');
same(1, $wire->calls - $before, 'Refuse oversized detail list before wasting remaining provider reads');
$facts = $read(); same(true, $facts->historyComplete, 'One complete precise history candidate');
$complete = fn ($d, $f) => ReconciliationEvidence::completion($attempt, new Evidence(domain: $d, candidates: $f->candidates, historyComplete: $f->historyComplete), $clock->now());
same('8.95', $complete($domain('2029-10-18'), $facts)['charge']['amount'], 'Provider-only recovery of full three-year original attempt');
same(null, $complete($domain('2027-10-18'), $facts), 'Short provider expiry cannot complete purchased three-year term');
foreach (['undated', 'date', 'duplicate', 'pagination', 'unknown', 'case', 'row_pagination', 'detail_root_pagination', 'header_pagination', 'item_metadata'] as $fault) {
    $wire->fault = $fault; $facts = $read();
    same(null, $complete($domain('2029-10-18'), $facts), 'Undated/date-only/multiple competing matching orders cannot establish uniqueness');
    if (!in_array($fault, ['duplicate', 'case'], true)) { same(false, $facts->historyComplete, 'Unknown competing timestamp poisons completeness'); }
}
$wire->fault = 'foreign'; $facts = $read(); same(true, $facts->historyComplete, 'Parsed unrelated account order preserves complete history');
same('8.95', $complete($domain('2029-10-18'), $facts)['charge']['amount'], 'Mixed-domain account still proves unique original charge');
$wire->fault = ''; $facts = $read(); $candidate = $facts->candidates[0];
$at = fn ($date) => new DateEvidence($date, ExpiryPrecision::Instant, 'synthetic', 'UTC');
$precise = new OrderEvidence($binding, $query, $candidate->charge, '901', $candidate->processedAt, $at('2029-10-18T12:00:00+00:00'));
$unequal = new DomainSnapshot($binding, 'example.com', true, $at('2029-10-18T13:00:00+00:00'), false);
same(null, ReconciliationEvidence::completion($attempt, new Evidence(domain: $unequal, candidates: [$precise], historyComplete: true), $clock->now()), 'Precise expiry instants must agree, not just calendar day');
foreach (['2029-10-20' => true, '2029-10-21' => false] as $expiry => $accepted) {
    $dated = new OrderEvidence($binding, $query, $candidate->charge, '901', $candidate->processedAt, new DateEvidence($expiry, ExpiryPrecision::Date, 'synthetic'));
    same($accepted, ReconciliationEvidence::completion($attempt, new Evidence(domain: $domain($expiry), candidates: [$dated], historyComplete: true), $clock->now()) !== null, 'Frozen source two-day calendar tolerance');
}
echo "Enom complete history, competing unknown timestamps and full-term expiry checks passed\n";
