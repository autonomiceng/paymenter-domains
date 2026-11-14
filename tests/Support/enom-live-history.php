<?php
// Redacted synthetic Enom Live history: fictional names and IDs in the observed Nov 14 2026 list and detail shapes. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/enom-history.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DateEvidence, DispatchSeal, DomainSnapshot, Environment, Evidence, ExecutionRecord, ExpiryPrecision, HistoryAcceptance, Money, Query, RequestBudget, RequestContext, RequestOrigin, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\History;
use Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence;
$live = new AccountBinding('enom', 2, Environment::Live, 'offline-live');
/** $paging: 'absent' is the observed Live single page; 'kept' adds full TEST pagination; 'partial' and 'contradictory' are refusals. */
$liveWire = new class implements Transport {
    public array $rows = []; public array $details = []; public string $paging = 'absent'; public array $requests = [];
    public function send(Environment $e, ReadCommand $command, array $p, string $uid, string $token, int $ms): string {
        $this->requests[] = [$command, $p];
        if ($command === ReadCommand::Order) { return $this->details[$p['OrderID']]; }
        $day = fn ($d) => DateTimeImmutable::createFromFormat('!m/d/Y', $d)->format('n/j/Y').' 12:00:00 AM';
        $body = enomHistoryPage($this->rows, 1, count($this->rows), $day($p['BeginDate']), $day($p['EndDate']));
        foreach ($this->rows as $row) {
            if (isset($row['status'])) { $body = preg_replace('~(<OrderID>'.$row['id'].'</OrderID>\s*<OrderDate>[^<]*</OrderDate>\s*<StatusDesc>)Order complete~', '${1}'.$row['status'], $body); }
        }
        if ($this->paging !== 'kept') { $body = preg_replace('~<(ShowPreviousAndNext|ShowPreviousLink|PreviousStartPosition|ShowStart|ShowEnd|ShowNextLink|NextStartPosition)>[^<]*</\1>~', '', $body); }
        if ($this->paging === 'partial') { $body = str_replace('<RecordCount>', '<ShowStart>1</ShowStart><RecordCount>', $body); }
        if ($this->paging === 'contradictory') { $body = preg_replace('~<RecordCount>\d+</RecordCount>~', '<RecordCount>3</RecordCount>', $body); }
        return enomHistoryEnvelope($body);
    }
};
$liveHistory = new History(new Client($live, 'offline-live', 'token', $liveWire));
// The renewal was sent 17:13-17:18 UTC on Jul 4: 10:13 AM Pacific, 11:13 AM Mountain.
$liveStart = new DateTimeImmutable('2026-07-04T17:13:00Z'); $liveNow = new DateTimeImmutable('2026-07-05T00:00:00Z');
$liveClock = new class($liveNow) implements Clock { public function __construct(public DateTimeImmutable $n) {} public function now(): DateTimeImmutable { return $this->n; } };
$liveAttempt = fn (DateTimeImmutable $at, ?AccountBinding $b = null) => new AttemptEvidence($b ?? $live, new Query('live-renew-placeholder.com', Action::Renew, 1), 'renew:live:g1:2026-09-07', 1,
    ExecutionRecord::RecordedNull, SendState::PossiblySent, $at, $at->modify('+300 seconds'), new DateEvidence('2026-09-07', ExpiryPrecision::Date, 'acquisition_base'),
    new Money('8.95', 'USD'), DispatchSeal::Sealed, historyAcceptance: new HistoryAcceptance($b ?? $live, 'America/Los_Angeles', 60, 2592000, 'enom_renewal_v1'));
$liveRead = function (?DateTimeImmutable $at = null) use ($liveHistory, $liveAttempt, $liveStart, $live, $liveClock): Evidence {
    return $liveHistory->inspect($liveAttempt($at ?? $liveStart), new RequestContext($live, RequestOrigin::Background, $liveClock->now()->modify('+250 seconds'), new RequestBudget(25), $liveClock));
};
$liveDone = fn (Evidence $f) => ReconciliationEvidence::completion($liveAttempt($liveStart), new Evidence(domain: new DomainSnapshot($live, 'live-renew-placeholder.com', true,
    $f->candidates[0]->afterExpiry ?? null, false, providerReference: '77'), candidates: $f->candidates, historyComplete: $f->historyComplete), $liveClock->now());
$liveOrder = fn (string $id, string $name, string $bill, string $placed) => enomHistoryDetail($id, $name, $bill, '9/7/2027 6:12:27 PM', '8.95', 1, $placed);
// The observed two-row shape: this renewal on the scanned days and an older renewal outside them.
$liveWire->rows = [['id'=>'5002', 'date'=>'7/4/2026 10:07:13 AM'], ['id'=>'5001', 'date'=>'6/8/2026 11:30:18 AM']];
$liveWire->details = ['5002'=>$liveOrder('5002', 'live-renew-placeholder.com', '7/4/2026 10:15:03 AM', '7/4/2026 10:07:13 AM')];
