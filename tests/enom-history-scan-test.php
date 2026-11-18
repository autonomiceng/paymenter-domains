<?php
// Dense Enom order history scans that continue across reads; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/enom-history.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DateEvidence, DispatchSeal, DomainSnapshot, Environment, Evidence, ExecutionRecord, ExpiryPrecision, HistoryAcceptance, HistoryScan, Money, Query, RequestBudget, RequestContext, RequestOrigin, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\History;
use Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence;
// Sanitized from the Nov 14 TEST list for request 359: the same receipt times, page split and product mix; fictional IDs and names.
// Rows 0-1 fall after the scanned days, 2-24 fill page one, 25 is alone on page two. Row 19 is the lost renewal, row 21 its registration.
$times = ['11/14/2026 1:48:16 PM', '11/14/2026 8:09:22 AM', '11/13/2026 2:20:20 PM', '11/13/2026 5:33:16 AM', '11/13/2026 4:59:36 AM',
    '11/13/2026 4:46:25 AM', '11/13/2026 4:21:17 AM', '11/13/2026 3:55:19 AM', '11/13/2026 3:54:16 AM', '11/12/2026 11:47:33 PM',
    '11/12/2026 10:23:23 PM', '11/12/2026 10:20:19 PM', '11/12/2026 9:54:33 PM', '11/12/2026 9:42:32 PM', '11/12/2026 9:18:17 PM',
    '11/12/2026 8:40:21 PM', '11/12/2026 7:55:34 PM', '11/12/2026 7:37:22 PM', '11/12/2026 7:35:17 PM', '11/12/2026 8:06:53 AM',
    '11/12/2026 7:57:13 AM', '11/12/2026 7:51:51 AM', '11/12/2026 7:51:19 AM', '11/12/2026 7:49:14 AM', '11/12/2026 7:36:23 AM', '11/12/2026 7:34:15 AM'];
$clock = new class implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-11-14T22:00:00Z'); } };
$binding = new AccountBinding('enom', 1, Environment::Test, 'offline');
$wire = new class($times) implements Transport {
    public string $fault = ''; public array $requests = [];
    public function __construct(private array $times) {}
    public function rows(): array
    {
        $rows = [];
        foreach ($this->times as $i=>$time) { $rows[] = ['id'=>(string)(9001 + $i), 'date'=>$time]; }
        if (str_contains($this->fault, 'changed')) {
            array_splice($rows, 2, 0, [['id'=>'9100', 'date'=>'11/13/2026 3:00:00 PM']]);
            $rows[6]['date'] = '11/13/2026 4:46:26 AM';
        }
        return $rows;
    }
    public function send(Environment $e, ReadCommand $command, array $p, string $uid, string $token, int $ms): string {
        $this->requests[] = [$command, $p]; $rows = $this->rows();
        if ($command === ReadCommand::Orders) {
            same(['11/12/2026', '11/14/2026'], [$p['BeginDate'], $p['EndDate']], 'The receipt days around the sealed window');
            return enomHistoryEnvelope(enomHistoryPage(array_slice($rows, $p['Start'] - 1, 25), $p['Start'], count($rows), '11/12/2026 12:00:00 AM', '11/14/2026 12:00:00 AM'));
        }
        $row = array_values(array_filter($rows, fn($r)=>$r['id'] === $p['OrderID']))[0];
        $i = (int)$p['OrderID'] - 9001; $mine = in_array($i, [19, 21], true);
        $bill = (new DateTimeImmutable($row['date'], new DateTimeZone('UTC')))->modify('+3 minutes')->format('n/j/Y g:i:s A');
        if ($i === 21 && $this->fault === 'competing') { $bill = '11/12/2026 8:08:00 AM'; }
        $body = enomHistoryDetail($p['OrderID'], $mine ? 'renew-359-placeholder.com' : 'scan-order-'.$i.'.com', $bill, '11/12/2028 2:51:00 PM', '8.95', 1, $row['date']);
        if (!($i === 19 || ($i === 21 && $this->fault === 'competing') || $i % 7 === 3)) { $body = str_replace('<ProductType>Renew', '<ProductType>Register', $body); }
        if ($i === 23 && $this->fault === 'unsupported') { $body = preg_replace('~<AdjustToAccount\s*/>~', '<AdjustToAccount>1.00</AdjustToAccount>', $body); }
        return $body;
    }
};
$history = new History(new Client($binding, 'offline', 'token', $wire));
$start = new DateTimeImmutable('2026-11-12T15:06:18Z');
$attempt = fn (?HistoryScan $scan, int $generation = 1) => new AttemptEvidence($binding, new Query('renew-359-placeholder.com', Action::Renew, 1), 'renew:140:g1:2027-11-12', $generation,
    ExecutionRecord::RecordedNull, SendState::PossiblySent, $start, $start->modify('+300 seconds'), new DateEvidence('2027-11-12', ExpiryPrecision::Date, 'acquisition_base'),
    new Money('8.95', 'USD'), DispatchSeal::Sealed, historyAcceptance: new HistoryAcceptance($binding, 'America/Los_Angeles', 60, 2592000, 'enom_renewal_v1'), historyScan: $scan);
// The renewal driver's domain reads leave 21 of the 25 reads in a turn.
$turn = function (?Evidence $previous, int $budget = 21, int $generation = 1) use ($history, $attempt, $clock, $binding, $wire): array {
    $wire->requests = []; $scan = $previous ? HistoryScan::load(json_decode(json_encode($previous->historyScan->saved()), true)) : null;
    $facts = $history->inspect($attempt($scan, $generation), new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget($budget), $clock));
    return [$facts, count($wire->requests)];
};
$complete = function (Evidence $facts) use ($attempt, $binding, $clock): ?array {
    $expiry = $facts->candidates[0]->afterExpiry ?? null;
    return ReconciliationEvidence::completion($attempt(null), new Evidence(domain: new DomainSnapshot($binding, 'renew-359-placeholder.com', true, $expiry, false, providerReference: '382'),
        candidates: $facts->candidates, historyComplete: $facts->historyComplete), $clock->now());
};

[$first, $reads] = $turn(null);
same(21, $reads, 'One read uses its whole budget: both list pages and 19 order details');
same(false, $first->historyComplete, 'A partly read dense day is never complete');
same(null, $complete($first), 'No completion before the last order detail');
same(true, $first->historyScan->progressed, 'Resolving more orders is progress, not a failed read');
same(['listed'=>26, 'in_window'=>24, 'resolved'=>19, 'refusals'=>['read_budget'=>1]], array_intersect_key($first->historyScan->state, array_flip(['listed', 'in_window', 'resolved', 'refusals'])), 'Saved progress keeps counts and the sanitized refusal');
expect(!str_contains(json_encode($first->historyScan->saved()), 'placeholder'), 'Saved progress holds no domain names or replies');

[$second, $reads] = $turn($first);
same(8, $reads, 'The next read lists both pages again, reads the five remaining orders and re-reads the saved match by its ID');
same([ReadCommand::Order, ['OrderID'=>'9020']], $wire->requests[7], 'The saved match is read again by its exact ID last');
same([true, 1, '9020'], [$second->historyComplete, count($second->candidates), $second->candidates[0]->providerReference], 'A full scan finds exactly the lost renewal');
same('8.95', $complete($second)['charge']['amount'], 'The existing completion checks pass on the full scan');
same('accepted_history', $complete($second)['source'], 'Completion source stays accepted history');

[$moved, $reads] = $turn($first, 21, 2);
same([21, 19, 1], [$reads, $moved->historyScan->state['resolved'], $moved->historyScan->state['reads']], 'Progress saved for another generation is not reused');
$tampered = new Evidence(historyScan: new HistoryScan($first->historyScan->key, ['orders'=>['9003'=>'c:not-a-signature']]));
[, $reads] = $turn($tampered); same(21, $reads, 'Malformed saved progress starts the scan again');

$wire->fault = 'changed';
[$changed, $reads] = $turn($first);
same(10, $reads, 'A new listed order and a changed receipt date are read; unchanged saved outcomes are kept');
same([true, 1, 25], [$changed->historyComplete, count($changed->candidates), $changed->historyScan->state['resolved']], 'The changed listing is validated in full before completing');

$wire->fault = 'competing';
[$a, $reads] = $turn(null, 25); same([22, ['detail_ceiling'=>1]], [$reads, $a->historyScan->state['refusals']], 'A read stops at the per-read detail ceiling');
[$b] = $turn($a, 25);
same([true, 2], [$b->historyComplete, count($b->candidates)], 'The scan reads every order instead of stopping at the first match');
same(null, $complete($b), 'Two matching orders stay unresolved');
same(1, $b->historyScan->state['refusals']['multiple_matches'] ?? null, 'Competing matches are recorded for diagnosis');

$wire->fault = 'unsupported';
[$c] = $turn(null); [$d] = $turn($c);
same([false, true, ['detail_unsupported'=>1]], [$d->historyComplete, $d->historyScan->progressed, $d->historyScan->state['refusals']], 'An unsupported order detail keeps the scan unresolved');
[$e, $reads] = $turn($d);
same([false, false, 3], [$e->historyComplete, $e->historyScan->progressed, $reads], 'Rereading only the unsupported order is no progress, so it counts toward the read limit');
same(2, $e->historyScan->state['refusal_totals']['detail_unsupported'], 'Refusal totals add up across reads');

// A valid detail can leave less than one millisecond for the next claim, before the deadline itself.
$wire->fault = '';
$deadline = $clock->now()->modify('+1 second');
$raceClock = new class($clock->now()) implements Clock {
    public function __construct(public DateTimeImmutable $time) {}
    public function now(): DateTimeImmutable { return $this->time; }
};
$raceWire = new class($wire, $raceClock, $deadline) implements Transport {
    public function __construct(private Transport $wire, private Clock $clock, private DateTimeImmutable $deadline) {}
    public function send(Environment $e, ReadCommand $command, array $p, string $uid, string $token, int $ms): string {
        $reply = $this->wire->send($e, $command, $p, $uid, $token, $ms);
        if ($command === ReadCommand::Order) { $this->clock->time = $this->deadline->modify('-500 microseconds'); }
        return $reply;
    }
};
$wire->requests = [];
$raceContext = new RequestContext($binding, RequestOrigin::Background, $deadline, new RequestBudget(21), $raceClock);
$race = (new History(new Client($binding, 'offline', 'token', $raceWire)))->inspect($attempt(null), $raceContext);
same([false, true, 1, ['read_budget'=>1]], [$race->historyComplete, $race->historyScan->progressed,
    $race->historyScan->state['resolved'], $race->historyScan->state['refusals']], 'A refused deadline claim retains the validated outcome as incomplete progress');
same(['9003'], array_map('strval', array_keys($race->historyScan->state['orders'])), 'Only the validated order is saved');
same([3, 3], [count($wire->requests), $raceContext->exchanges()], 'The refused claim never enters the transport');
same(null, $complete($race), 'Deadline progress cannot authorize completion');
// The lost renewal's valid detail arrives as the deadline expires: its charge still reaches the renewal proof sink, so
// Not processed is refused later, but the scan stays incomplete and completes nothing.
$raceClock->time = $clock->now();
$lateWire = new class($wire, $raceClock, $deadline) implements Transport {
    public function __construct(private Transport $wire, private Clock $clock, private DateTimeImmutable $deadline) {}
    public function send(Environment $e, ReadCommand $command, array $p, string $uid, string $token, int $ms): string {
        $reply = $this->wire->send($e, $command, $p, $uid, $token, $ms);
        if ($command === ReadCommand::Order && ($p['OrderID'] ?? null) === '9020') { $this->clock->time = $this->deadline; }
        return $reply;
    }
};
$lateContext = new RequestContext($binding, RequestOrigin::Background, $deadline, new RequestBudget(40), $raceClock);
$late = (new History(new Client($binding, 'offline', 'token', $lateWire)))->inspect($attempt(null), $lateContext);
same([false, ['9020']], [$late->historyComplete, array_map(static fn ($c) => $c->providerReference, $lateContext->seen()?->candidates ?? [])],
    'A matching charge read as the deadline expires still reaches the proof sink');
same(null, $complete($late), 'A deadline-late charge cannot authorize completion');
echo "Dense Enom history scans continue across reads and complete only after every order is resolved\n";
