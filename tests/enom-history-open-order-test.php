<?php
// Unfinished or canceled Enom orders block a history scan only when they could be the attempt; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/enom-live-history.php';
$liveWire->paging = 'kept';
$liveWire->rows = [['id'=>'5005', 'date'=>'7/4/2026 10:20:00 AM', 'status'=>'Order canceled'], ...$liveWire->rows];
$canceled = fn (string $name) => str_replace(['<StatusDesc>Successful', '<OrderStatus>6'], ['<StatusDesc>Canceled', '<OrderStatus>7'], $liveOrder('5005', $name, '7/4/2026 10:21:00 AM', '7/4/2026 10:20:00 AM'));
$cases = ['for another domain'=>[$canceled('other-live-placeholder.com'), true, []],
    'for this domain'=>[$canceled('live-renew-placeholder.com'), false, ['order_open'=>1]],
    'with an unreadable detail'=>[str_replace('<OrderID>5005', '<OrderID>5999', $canceled('other-live-placeholder.com')), false, ['order_open'=>1]]];
foreach ($cases as $case=>[$detail, $expected, $refusals]) {
    $liveWire->details['5005'] = $detail; $facts = $liveRead();
    same([$expected, $refusals], [$facts->historyComplete, $facts->historyScan->state['refusals']], 'A canceled order '.$case.($expected ? ' does not block' : ' blocks'));
    same($expected ? '8.95' : null, $liveDone($facts)['charge']['amount'] ?? null, $expected ? 'The renewal completes beside another domain\'s canceled order' : 'A blocked scan completes nothing');
}
// A dense unfinished day advances beyond the first detail batch. Exclusions belong to this scan only.
$liveWire->rows = [];
foreach (range(1, 21) as $i) {
    $id = (string)(6000 + $i);
    $liveWire->rows[] = ['id'=>$id, 'date'=>'7/4/2026 10:20:00 AM', 'status'=>'Order canceled'];
    $liveWire->details[$id] = str_replace(['<StatusDesc>Successful', '<OrderStatus>6'], ['<StatusDesc>Canceled', '<OrderStatus>7'],
        $liveOrder($id, 'other-live-placeholder.com', '7/4/2026 10:21:00 AM', '7/4/2026 10:20:00 AM'));
}
$liveWire->rows[] = ['id'=>'5002', 'date'=>'7/4/2026 10:07:13 AM'];
$scanRead = function ($previous = null) use ($liveAttempt, $liveStart, $liveHistory, $live, $liveClock, $liveWire) {
    $a = $liveAttempt($liveStart); $args = get_object_vars($a);
    $args['historyScan'] = $previous?->historyScan;
    $liveWire->requests = [];
    return $liveHistory->inspect(new \Paymenter\Extensions\Servers\Domains\Data\AttemptEvidence(...$args),
        new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($live, \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,
            $liveClock->now()->modify('+250 seconds'), new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $liveClock));
};
$first = $scanRead();
same([false, true, 20], [$first->historyComplete, $first->historyScan->progressed, $first->historyScan->state['resolved']], 'Twenty unrelated open orders save continuation progress');
$second = $scanRead($first);
same([true, '5002', '8.95'], [$second->historyComplete, $second->candidates[0]->providerReference ?? null, $liveDone($second)['charge']['amount'] ?? null], 'The second turn reaches and completes the later matching renewal');
// A changed listed identity is read during continuation, even when its prior detail named another domain.
$liveWire->rows[0]['status'] = 'Processing';
$liveWire->details['6001'] = str_replace('other-live-placeholder.com', 'live-renew-placeholder.com', $liveWire->details['6001']);
$changed = $scanRead($first);
same([false, 1], [$changed->historyComplete, $changed->historyScan->state['refusals']['order_open'] ?? null], 'A changed open order for this domain blocks completion');
expect(in_array(['OrderID'=>'6001'], array_column($liveWire->requests, 1), true), 'The changed open order detail is read again');
// Even unchanged list metadata cannot preserve an open exclusion into a new scan.
$again = $scanRead($second);
same(false, $again->historyComplete, 'A fresh scan rechecks mutable open details before any financial conclusion');
expect(in_array(['OrderID'=>'6002'], array_column($liveWire->requests, 1), true), 'Unchanged unrelated open orders are re-read after the completed scan');
$blocked = $scanRead($changed);
expect(in_array(['OrderID'=>'6001'], array_column($liveWire->requests, 1), true), 'An open order for this domain is never cached as an exclusion');
same(null, $liveDone($blocked), 'A same-domain open order supplies no completion proof');
echo "Only an unfinished order that could be the attempt blocks its history scan\n";
