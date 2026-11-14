<?php
// Enom Live bill dates read as Pacific or Mountain, TEST as Mountain; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/enom-live-history.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\History;
$liveWire->paging = 'kept';
// Pacific: 10:15:03 AM PDT is 17:15:03 UTC, inside the window; the Mountain reading is an hour early.
$pacific = $liveRead();
same([true, 1, '2026-07-04T17:15:03+00:00'], [$pacific->historyComplete, count($pacific->candidates), $pacific->candidates[0]->processedAt->value ?? null], 'A Live bill date read as Pacific matches');
same('8.95', $liveDone($pacific)['charge']['amount'] ?? null, 'A Live bill date read as Pacific completes');
// Mountain: 11:15:03 AM MDT is 17:15:03 UTC; the Pacific reading is an hour late.
$liveWire->details['5002'] = $liveOrder('5002', 'live-renew-placeholder.com', '7/4/2026 11:15:03 AM', '7/4/2026 10:07:13 AM');
$mountain = $liveRead();
same([true, 1, '2026-07-04T17:15:03+00:00'], [$mountain->historyComplete, count($mountain->candidates), $mountain->candidates[0]->processedAt->value ?? null], 'A Live bill date read as Mountain matches');
same('8.95', $liveDone($mountain)['charge']['amount'] ?? null, 'A Live bill date read as Mountain completes');
// One order fits as Pacific and another as Mountain: two candidates, no match.
$liveWire->rows = [['id'=>'5003', 'date'=>'7/4/2026 10:09:00 AM'], ...$liveWire->rows];
$liveWire->details['5003'] = $liveOrder('5003', 'live-renew-placeholder.com', '7/4/2026 10:16:00 AM', '7/4/2026 10:09:00 AM');
$both = $liveRead();
same([true, 2, null, 1], [$both->historyComplete, count($both->candidates), $liveDone($both), $both->historyScan->state['refusals']['multiple_matches'] ?? null], 'Orders matching under different zone readings stay unresolved');
// TEST stays Mountain only: a Pacific-only bill time is not this send, a Mountain one is.
$test = new AccountBinding('enom', 3, Environment::Test, 'offline-test'); $testWire = clone $liveWire;
$testWire->rows = [['id'=>'5002', 'date'=>'7/4/2026 10:07:13 AM']];
$testRead = fn () => (new History(new Client($test, 'offline-test', 'token', $testWire)))->inspect($liveAttempt($liveStart, $test), new RequestContext($test, RequestOrigin::Background, $liveNow->modify('+250 seconds'), new RequestBudget(25), $liveClock));
$testWire->details['5002'] = $liveOrder('5002', 'live-renew-placeholder.com', '7/4/2026 10:15:03 AM', '7/4/2026 10:07:13 AM');
same([true, 0], [$testRead()->historyComplete, count($testRead()->candidates)], 'TEST does not read a bill date as Pacific');
$testWire->details['5002'] = $liveOrder('5002', 'live-renew-placeholder.com', '7/4/2026 11:15:03 AM', '7/4/2026 10:07:13 AM');
same(1, count($testRead()->candidates), 'TEST reads bill dates as Mountain');
// A fall-back local time is ambiguous in a named zone and refuses the order.
$liveClock->n = new DateTimeImmutable('2026-11-02T00:00:00Z');
$liveWire->rows = [['id'=>'5004', 'date'=>'11/1/2026 1:20:00 AM']]; $liveWire->details = ['5004'=>$liveOrder('5004', 'live-renew-placeholder.com', '11/1/2026 1:31:00 AM', '11/1/2026 1:20:00 AM')];
$ambiguous = $liveRead(new DateTimeImmutable('2026-11-01T08:31:00Z'));
same([false, ['order_unreadable'=>1]], [$ambiguous->historyComplete, $ambiguous->historyScan->state['refusals']], 'An ambiguous fall-back bill time is refused');
echo "Enom Live history matches one order under Pacific or Mountain bill dates; TEST stays Mountain\n";
