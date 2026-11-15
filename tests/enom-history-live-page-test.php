<?php
// Enom Live counted single page without pagination fields; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/enom-live-history.php';
// Enom refuses a BeginDate more than six months back, so even a one-year contract covers an Enom attempt for 175 days only.
$year = new \Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance($live, 'America/Los_Angeles', 60, 31536000, 'enom_renewal_v1');
same([true, false], [$year->covers($liveAttempt($liveStart), $liveStart->modify('+174 days')), $year->covers($liveAttempt($liveStart), $liveStart->modify('+176 days'))], 'Enom history coverage stays inside the six-month list limit');
$pacific = $liveRead();
same([true, 1, 2, 1, 2], [$pacific->historyComplete, count($pacific->candidates), $pacific->historyScan->state['listed'], $pacific->historyScan->state['in_window'], count($liveWire->requests)], 'A counted Live single page with no pagination fields is read in full: one list read and the one order on the scanned days');
same(['8.95', 'accepted_history'], [$liveDone($pacific)['charge']['amount'] ?? null, $liveDone($pacific)['source'] ?? null], 'The observed Live shape completes a matching renewal');
$liveWire->rows = []; $empty = $liveRead();
same([true, 0, 0], [$empty->historyComplete, count($empty->candidates), $empty->historyScan->state['listed']], 'A counted empty Live page is a complete scan with no order');
$liveWire->rows = [['id'=>'5002', 'date'=>'7/4/2026 10:07:13 AM'], ['id'=>'5001', 'date'=>'6/8/2026 11:30:18 AM']];
foreach (['partial', 'contradictory'] as $shape) {
    $liveWire->paging = $shape; $refused = $liveRead();
    same([false, ['list_invalid'=>1]], [$refused->historyComplete, $refused->historyScan->state['refusals']], 'A '.$shape.' Live list is refused');
}
$liveWire->paging = 'absent'; $liveWire->rows = array_map(fn ($i) => ['id'=>(string)(6000 + $i), 'date'=>'7/4/2026 9:00:00 AM'], range(0, 25));
same(['list_invalid'=>1], $liveRead()->historyScan->state['refusals'], 'More than one page without pagination fields is refused');
// Only fewer than 25 orders: a full page without pagination fields cannot show there is no second page. Orders outside the scanned days.
foreach ([24=>[true, []], 25=>[false, ['list_invalid'=>1]]] as $rows=>$expected) {
    $liveWire->rows = array_map(fn ($i) => ['id'=>(string)(6100 + $i), 'date'=>'6/8/2026 9:00:00 AM'], range(1, $rows)); $facts = $liveRead();
    same($expected, [$facts->historyComplete, $facts->historyScan->state['refusals']], $rows.' orders on a page without pagination fields');
}
echo "Enom Live counted single pages are read; partial, contradictory and multi-page metadata stay strict\n";
