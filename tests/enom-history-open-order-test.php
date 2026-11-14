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
echo "Only an unfinished order that could be the attempt blocks its history scan\n";
