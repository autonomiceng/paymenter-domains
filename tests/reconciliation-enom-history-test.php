<?php
// Captured TEST history shape and full-term source regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/enom-history.php';
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
    public string $fault = ''; public int $calls = 0; public array $requests = [];
    public function send(Environment $e, ReadCommand $command, array $p, string $uid, string $token, int $ms): string {
        $this->calls++; $this->requests[] = [$command, $p];
        if ($command === ReadCommand::Orders) {
            $rows = [];
            for ($i = 0; $i < 81; $i++) {
                $matching = $i === 0 || ($i === 1 && in_array($this->fault, ['undated', 'date', 'duplicate', 'foreign', 'case'], true))
                    || ($i < 21 && $this->fault === 'dense');
                $rows[] = ['id'=>(string)(901 + $i), 'date'=>$matching ? '10/18/2026 6:00:00 AM' : '10/16/2026 6:00:00 AM'];
            }
            if ($this->fault === 'none') { $rows = []; }
            $start = $p['Start'];
            $body = enomHistoryPage(array_slice($rows, $start - 1, 25), $start, count($rows));
            if ($this->fault === 'row_pagination') { $body = str_replace('</OrderID>', '</OrderID><NextPage>2</NextPage>', $body); }
            if ($this->fault === 'pagination') { $body .= '<NextPage>2</NextPage>'; }
            if ($this->fault === 'missing_page' && $start === 26) { return '<broken>'; }
            if ($this->fault === 'loop') { $body = str_replace('<NextStartPosition>26', '<NextStartPosition>1', $body); }
            if ($this->fault === 'changed_count' && $start === 26) { $body = str_replace('<RecordCount>81', '<RecordCount>82', $body); }
            if ($this->fault === 'repeated_id' && $start === 26) { $body = str_replace('<OrderID>926', '<OrderID>901', $body); }
            if ($this->fault === 'truncated') { $body = preg_replace('~<OrderDetail>.*?</OrderDetail>~s', '', $body, 1); }
            $body = enomHistoryEnvelope($body);
            if ($this->fault === 'unknown') { $body = str_replace('</interface-response>', '<UnknownCompleteness>1</UnknownCompleteness></interface-response>', $body); }
            if ($this->fault === 'nested_metadata') { $body = preg_replace('~<Server\s*/>~', '<Server><NextPage>2</NextPage></Server>', $body); }
            return $body;
        }
        $date = ($p['OrderID'] === '902' && $this->fault === 'undated') ? '' : ($this->fault === 'date' && $p['OrderID'] === '902' ? '10/18/2026' : '10/18/2026 6:00:01 AM');
        $name = $p['OrderID'] === '902' && $this->fault === 'foreign' ? 'unrelated-placeholder.com' : ($p['OrderID'] === '902' && $this->fault === 'case' ? 'Example.com' : 'example.com');
        $body = enomHistoryDetail($p['OrderID'], $name, $date, '2029-10-18', '8.95', 3, '10/18/2026 6:00:00 AM');
        $body = str_replace('<ProductType>Renew', '<ProductType>Register', $body);
        if ($this->fault === 'detail_root_pagination') { $body = str_replace('</interface-response>', '<NextPage>2</NextPage></interface-response>', $body); }
        if ($this->fault === 'header_pagination') { $body = str_replace('<OrderDetail>', '<NextPage>2</NextPage><OrderDetail>', $body); }
        if ($this->fault === 'item_metadata') { $body = str_replace('</OrderDetail>', '<UnknownCompleteness>1</UnknownCompleteness></OrderDetail>', $body); }
        if ($this->fault === 'adjustment') { $body = preg_replace('~<AdjustToAccount\s*/>~', '<AdjustToAccount>1.00</AdjustToAccount>', $body); }
        if ($this->fault === 'nested_result') { $body = str_replace('<Result>True</Result>', '<Result><Value>True</Value></Result>', $body); }
        if ($this->fault === 'nested_product') { $body = str_replace('<ProductType>Register</ProductType>', '<ProductType><Value>Register</Value></ProductType>', $body); }
        if ($this->fault === 'changed_order_date') { $body = str_replace('<OrderDate>10/18/2026', '<OrderDate>10/17/2026', $body); }
        return $body;
    }
};
$history = new History(new Client($binding, 'offline', 'token', $wire));
$domain = fn ($expiry) => new DomainSnapshot($binding, 'example.com', true, new DateEvidence($expiry, ExpiryPrecision::Date, 'synthetic'), false, providerReference: '456');
$read = function () use ($history, $attempt, $clock, $binding) { return $history->inspect($attempt, new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget(25), $clock)); };
$before = $wire->calls;
$limited = $history->inspect($attempt, new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget(1), $clock));
same(false, $limited->historyComplete, 'Insufficient remaining detail budget cannot certify completeness');
same(1, $wire->calls - $before, 'Refuse oversized detail list before wasting remaining provider reads');
foreach ([4=>false, 5=>true] as $budget=>$accepted) {
    $facts = $history->inspect($attempt, new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget($budget), $clock));
    same($accepted, $facts->historyComplete, 'Page and detail reads share the exact remaining budget');
}
foreach ([1, 26, 9999] as $startPosition) { ReadCommand::Orders->validate(['BeginDate'=>'10/18/2026', 'EndDate'=>'10/20/2026', 'Start'=>$startPosition]); }
foreach ([0, -1, 10000, '26', 1.0] as $startPosition) {
    try { ReadCommand::Orders->validate(['BeginDate'=>'10/18/2026', 'EndDate'=>'10/20/2026', 'Start'=>$startPosition]); throw new LogicException('Expected position refusal'); }
    catch (InvalidArgumentException) {}
}
$wire->requests = [];
$facts = $read(); same(true, $facts->historyComplete, 'One complete precise history candidate');
same([1, 26, 51, 76], array_column(array_column(array_values(array_filter($wire->requests, fn($r)=>$r[0] === ReadCommand::Orders)), 1), 'Start'), 'Every counted page is read contiguously');
same(5, count($wire->requests), '81 listed orders need only four list reads and one detail');
// Enom TEST bills in Mountain time: 6:00:01 AM MDT is one second after the 12:00 UTC send.
same('2026-10-18T12:00:01+00:00', $facts->candidates[0]->processedAt->value, 'Enom TEST bill date read as Mountain time');
$complete = fn ($d, $f) => ReconciliationEvidence::completion($attempt, new Evidence(domain: $d, candidates: $f->candidates, historyComplete: $f->historyComplete), $clock->now());
same('8.95', $complete($domain('2029-10-18'), $facts)['charge']['amount'], 'Provider-only recovery of full three-year original attempt');
same(null, $complete($domain('2027-10-18'), $facts), 'Short provider expiry cannot complete purchased three-year term');
foreach (['undated', 'date', 'duplicate', 'pagination', 'unknown', 'case', 'row_pagination', 'detail_root_pagination', 'header_pagination', 'item_metadata', 'missing_page', 'loop', 'changed_count', 'repeated_id', 'truncated', 'nested_metadata', 'adjustment', 'nested_result', 'nested_product', 'changed_order_date', 'dense', 'none'] as $fault) {
    $wire->fault = $fault; $facts = $read();
    same(null, $complete($domain('2029-10-18'), $facts), 'Undated/date-only/multiple competing matching orders cannot establish uniqueness');
    if (!in_array($fault, ['duplicate', 'case', 'none'], true)) { same(false, $facts->historyComplete, 'Unknown competing timestamp poisons completeness'); }
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
