<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** Sanitized TEST list shape. Only pagination, dates and fictional IDs vary. */
function enomHistoryPage(array $rows, int $start = 1, ?int $count = null, string $begin = '1/1/2026 12:00:00 AM', string $end = '1/1/2030 12:00:00 AM'): string
{
    $doc = new DOMDocument;
    $doc->loadXML(file_get_contents(__DIR__.'/../fixtures/enom-order-list.xml'));
    $list = $doc->getElementsByTagName('OrderList')->item(0);
    $template = $list->getElementsByTagName('OrderDetail')->item(0)->cloneNode(true);
    while ($list->firstChild) { $list->removeChild($list->firstChild); }
    foreach (['BeginDate'=>$begin, 'EndDate'=>$end] as $key=>$value) { $list->appendChild($doc->createElement($key, $value)); }
    foreach ($rows as $row) {
        $item = $template->cloneNode(true);
        foreach (['OrderID'=>$row['id'], 'OrderDate'=>$row['date']] as $key=>$value) { $item->getElementsByTagName($key)->item(0)->textContent = (string)$value; }
        $list->appendChild($item);
    }
    $count ??= count($rows); $last = $rows ? $start + count($rows) - 1 : 0;
    $fields = ['ShowPreviousAndNext'=>'Yes', 'ShowPreviousLink'=>$start > 1 ? 'Yes' : 'No'];
    if ($start > 1) { $fields['PreviousStartPosition'] = (string)max(1, $start - 25); }
    $fields += ['ShowStart'=>(string)($rows ? $start : 0), 'ShowEnd'=>(string)$last, 'ShowNextLink'=>$last < $count ? 'Yes' : 'No'];
    if ($last < $count) { $fields['NextStartPosition'] = (string)($last + 1); }
    $fields['RecordCount'] = (string)$count;
    foreach ($fields as $key=>$value) { $list->appendChild($doc->createElement($key, $value)); }
    return $doc->saveXML($list);
}

function enomHistoryList(array $ids, string $date): string
{
    return enomHistoryPage(array_map(static fn($id)=>['id'=>$id, 'date'=>$date], $ids));
}

function enomHistoryEnvelope(string $payload, string $command = 'GETORDERLIST'): string
{
    $doc = new DOMDocument;
    $doc->loadXML(file_get_contents(__DIR__.'/../fixtures/enom-order-list.xml'));
    $root = $doc->documentElement;
    $root->removeChild($doc->getElementsByTagName('OrderList')->item(0));
    $doc->getElementsByTagName('Command')->item(0)->textContent = $command;
    $fragment = $doc->createDocumentFragment(); $fragment->appendXML($payload); $root->appendChild($fragment);
    return $doc->saveXML($root);
}

/** The observed renewal detail, with fictional identity and explicit scenario facts. */
function enomHistoryDetail(string $id = '901', string $name = 'example.com', string $bill = '11/6/2026 11:55:00 PM', string $expiry = '11/2/2029 4:35:00 AM', string $amount = '17.90', int $years = 2): string
{
    $doc = new DOMDocument;
    $doc->loadXML(file_get_contents(__DIR__.'/../fixtures/enom-renewal-order.xml'));
    foreach (['OrderID'=>$id, 'Description'=>$name, 'BillDate'=>$bill, 'ExpiryDatetime'=>$expiry, 'OrderBillAmount'=>'$'.$amount, 'AmountPaid'=>$amount, 'Quantity'=>(string)$years] as $key=>$value) {
        $doc->getElementsByTagName($key)->item(0)->textContent = $value;
    }
    return $doc->saveXML($doc->documentElement);
}
