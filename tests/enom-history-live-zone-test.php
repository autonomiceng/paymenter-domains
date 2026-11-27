<?php
// Enom Live bill dates match only in the measured zone, or configured Pacific until measured; TEST is Mountain. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/enom-live-history.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ReadAdapter;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\History;
$liveWire->paging = 'kept';
// A lost renewal sent 17:13-17:18 UTC created no order. Staff then renewed the domain at Enom at 18:15:03 UTC: 11:15:03 AM Pacific.
// As Mountain that bill time would fit the lost send. Unmeasured, it is a candidate only: unresolved, never a match, never absence.
$liveWire->details['5002'] = $liveOrder('5002', 'live-renew-placeholder.com', '7/4/2026 11:15:03 AM', '7/4/2026 10:07:13 AM');
$staff = $liveRead();
same([false, 0, null, ['other_zone_candidate'=>1]], [$staff->historyComplete, count($staff->candidates), $liveDone($staff), $staff->historyScan->state['refusals']], 'An order that fits only an unmeasured zone keeps the attempt unresolved');
same([], $staff->historyScan->state['orders'], 'The other-zone candidate is never saved as an outcome');
// Unmeasured Live matches as configured Pacific: 10:15:03 AM PDT is 17:15:03 UTC.
$liveWire->details['5002'] = $liveOrder('5002', 'live-renew-placeholder.com', '7/4/2026 10:15:03 AM', '7/4/2026 10:07:13 AM');
$pacific = $liveRead();
same([true, 1, '2026-07-04T17:15:03+00:00'], [$pacific->historyComplete, count($pacific->candidates), $pacific->candidates[0]->processedAt->value ?? null], 'Unmeasured Live matches as configured Pacific');
same('8.95', $liveDone($pacific)['charge']['amount'] ?? null, 'A configured-zone match completes');
// Measured Mountain: a Mountain fit matches; a Pacific-only fit is a validated non-match.
$denver = $liveRead(null, 'America/Denver');
same([true, 0, []], [$denver->historyComplete, count($denver->candidates), $denver->historyScan->state['refusals']], 'Measured Mountain does not read a bill date as Pacific');
$liveWire->details['5002'] = $liveOrder('5002', 'live-renew-placeholder.com', '7/4/2026 11:15:03 AM', '7/4/2026 10:07:13 AM');
$mountain = $liveRead(null, 'America/Denver');
same([true, 1, '2026-07-04T17:15:03+00:00'], [$mountain->historyComplete, count($mountain->candidates), $mountain->candidates[0]->processedAt->value ?? null], 'A measured Mountain account matches as Mountain');
same('8.95', $liveDone($mountain)['charge']['amount'] ?? null, 'A measured-zone match completes');
// Measured Pacific: the same Mountain-only fit is a validated non-match.
$measuredPacific = $liveRead(null, 'America/Los_Angeles');
same([true, 0, []], [$measuredPacific->historyComplete, count($measuredPacific->candidates), $measuredPacific->historyScan->state['refusals']], 'Measured Pacific does not read a bill date as Mountain');
// Two orders matching in the measured zone stay unresolved.
$liveWire->rows = [['id'=>'5003', 'date'=>'7/4/2026 10:09:00 AM'], ...$liveWire->rows];
$liveWire->details['5003'] = $liveOrder('5003', 'live-renew-placeholder.com', '7/4/2026 11:16:00 AM', '7/4/2026 10:09:00 AM');
$both = $liveRead(null, 'America/Denver');
same([true, 2, null, 1], [$both->historyComplete, count($both->candidates), $liveDone($both), $both->historyScan->state['refusals']['multiple_matches'] ?? null], 'Two matching orders stay unresolved');
// TEST stays Mountain only, whatever a Live measurement says.
$test = new AccountBinding('enom', 3, Environment::Test, 'offline-test'); $testWire = clone $liveWire;
$testWire->rows = [['id'=>'5002', 'date'=>'7/4/2026 10:07:13 AM']];
$testRead = fn () => (new History(new ReadAdapter($test, 'offline-test', 'token', $testWire)))->inspect($liveAttempt($liveStart, $test), new RequestContext($test, RequestOrigin::Background, $liveNow->modify('+250 seconds'), new RequestBudget(25), $liveClock));
$testWire->details['5002'] = $liveOrder('5002', 'live-renew-placeholder.com', '7/4/2026 10:15:03 AM', '7/4/2026 10:07:13 AM');
same([true, 0], [$testRead()->historyComplete, count($testRead()->candidates)], 'TEST does not read a bill date as Pacific');
$testWire->details['5002'] = $liveOrder('5002', 'live-renew-placeholder.com', '7/4/2026 11:15:03 AM', '7/4/2026 10:07:13 AM');
same(1, count($testRead()->candidates), 'TEST reads bill dates as Mountain');
// A fall-back local time is ambiguous in a named zone and refuses the order.
$liveClock->n = new DateTimeImmutable('2026-11-02T00:00:00Z');
$liveWire->rows = [['id'=>'5004', 'date'=>'11/1/2026 1:20:00 AM']]; $liveWire->details = ['5004'=>$liveOrder('5004', 'live-renew-placeholder.com', '11/1/2026 1:31:00 AM', '11/1/2026 1:20:00 AM')];
$ambiguous = $liveRead(new DateTimeImmutable('2026-11-01T08:31:00Z'));
same([false, ['order_unreadable'=>1]], [$ambiguous->historyComplete, $ambiguous->historyScan->state['refusals']], 'An ambiguous fall-back bill time is refused');
echo "Enom Live history matches only in the measured or configured zone; another zone's fit stays unresolved; TEST stays Mountain\n";
