<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, Evidence, ExpiryPrecision, RequestContext};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, ProtocolResult};
/** Accepted, counted Enom history only. No partial scan can establish uniqueness. */
final class History
{
    public function __construct(private readonly Client $client) {}
    public function inspect(AttemptEvidence $attempt, RequestContext $context): Evidence
    {
        if (!$attempt->historyAcceptance?->covers($attempt, $context->now())) { return new Evidence; }
        // OrderDate is a database receipt clock, not BillDate. Keep whole days across every
        // possible UTC offset; never use it as precise charge evidence or invent absent domain/product fields.
        $utc = new \DateTimeZone('UTC');
        $begin = $attempt->windowStart->setTimezone($utc)->modify('-12 hours')->setTime(0, 0);
        $end = $attempt->windowEnd->setTimezone($utc)->modify('+14 hours')->setTime(0, 0)->modify('+1 day');
        $parameters = ['BeginDate'=>$begin->format('m/d/Y'), 'EndDate'=>$end->format('m/d/Y')];
        $start = 1; $total = null; $ids = []; $details = [];
        do {
            if ($context->budget->remaining() < 1) { return new Evidence; }
            $reply = $this->client->read(ReadCommand::Orders, $parameters + ['Start'=>$start], $context);
            $page = self::page($reply, $start, $begin, $end);
            if (!$page || ($total !== null && $total !== $page['count'])) { return new Evidence; }
            $total = $page['count'];
            foreach ($page['rows'] as $row) {
                $id = $row['orderid'];
                if (isset($ids[$id])) { return new Evidence; }
                $ids[$id] = true;
                $placed = self::localClock($row['orderdate']);
                // Each row was validated by page(). A different day cannot be this original send.
                if ($placed >= $begin && $placed < $end) { $details[] = $row; }
            }
            // Preserve the existing detail cap and reserve capacity for every remaining list page.
            $pagesLeft = (int)ceil(($total - count($ids)) / 25);
            if (count($details) > 20 || count($details) + $pagesLeft > $context->budget->remaining()) { return new Evidence; }
            $start = $page['next'];
        } while ($start !== null);
        if (count($ids) !== $total) { return new Evidence; }
        $candidates = []; $complete = true;
        foreach ($details as $row) {
            if ($row['statusdesc'] !== 'Order complete' || $row['orderprocessflag'] !== 'True') { $complete = false; continue; }
            $id = $row['orderid'];
            $order = $this->client->read(ReadCommand::Order, ['OrderID'=>$id], $context);
            if (!self::supported($order) || $order->value('order.orderid') !== $id) { $complete = false; continue; }
            if ($order->value('order.orderdate') !== null && self::localClock($order->value('order.orderdate')) != self::localClock($row['orderdate'])) { $complete = false; continue; }
            if (OrderCharge::unrelated($attempt, $order)) { continue; }
            $candidate = OrderCharge::fromHistory($attempt->binding, $order);
            if (!$candidate || $candidate->processedAt?->precision !== ExpiryPrecision::Instant) { $complete = false; continue; }
            $processed = new \DateTimeImmutable($candidate->processedAt->value);
            // A validated different action/term or bill time cannot be this original send.
            if (!$candidate->query->matches($attempt->query) || $processed < $attempt->windowStart || $processed > $attempt->windowEnd) { continue; }
            $candidates[] = $candidate;
        }
        return new Evidence(candidates:$candidates, historyComplete:$complete);
    }
    /** Fixed 25-record pages, stable total, contiguous positions, and an explicit terminal page. */
    private static function page(ProtocolResult $reply, int $start, \DateTimeImmutable $begin, \DateTimeImmutable $end): ?array
    {
        $list = $reply->value('orderlist');
        $fields = ['begindate', 'enddate', 'orderdetail', 'showpreviousandnext', 'showpreviouslink', 'previousstartposition',
            'showstart', 'showend', 'shownextlink', 'nextstartposition', 'recordcount'];
        if ($reply->status !== 'success' || $reply->value('history_complete') !== true || !is_array($list)
            || array_diff(array_keys($list), $fields)) { return null; }
        $from = self::localClock($list['begindate'] ?? null); $until = self::localClock($list['enddate'] ?? null);
        if (!$from || !$until || $from > $begin || $until < $end) { return null; }
        foreach (['recordcount', 'showstart', 'showend'] as $key) {
            if (!is_string($list[$key] ?? null) || !preg_match('/^(?:0|[1-9][0-9]{0,3})$/D', $list[$key])) { return null; }
        }
        foreach (['showpreviousandnext', 'showpreviouslink', 'shownextlink'] as $key) {
            if (!in_array($list[$key] ?? null, ['Yes', 'No'], true)) { return null; }
        }
        $count = (int)$list['recordcount']; $rows = $list['orderdetail'] ?? [];
        if (!is_array($rows)) { return null; }
        $rows = array_is_list($rows) ? $rows : [$rows];
        $size = $count === 0 ? 0 : min(25, $count - $start + 1);
        $last = $size ? $start + $size - 1 : 0;
        if ($size < 0 || count($rows) !== $size || ($count === 0 && $start !== 1)
            || (int)$list['showstart'] !== ($size ? $start : 0) || (int)$list['showend'] !== $last
            || $list['showpreviouslink'] !== ($start > 1 ? 'Yes' : 'No')
            || $list['shownextlink'] !== ($last < $count ? 'Yes' : 'No')
            || ($count > 25 && $list['showpreviousandnext'] !== 'Yes')) { return null; }
        foreach (['previousstartposition'=>$start > 1 ? max(1, $start - 25) : null, 'nextstartposition'=>$last < $count ? $last + 1 : null] as $key=>$expected) {
            if ($expected === null) { if (isset($list[$key]) && $list[$key] !== '') { return null; } }
            elseif (($list[$key] ?? null) !== (string)$expected) { return null; }
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['orderid', 'orderdate', 'statusdesc', 'orderprocessflag'])
                || !is_string($row['orderid'] ?? null) || !preg_match('/^[1-9][0-9]{0,19}$/D', $row['orderid'])
                || !self::localClock($row['orderdate'] ?? null)
                || !in_array($row['statusdesc'] ?? null, ['New', 'Authorization Succeeded', 'Authorization Failed', 'Processing', 'Ready for billing', 'Order complete', 'Order canceled'], true)
                || !in_array($row['orderprocessflag'] ?? null, ['True', 'False'], true)) { return null; }
        }
        return ['rows'=>$rows, 'count'=>$count, 'next'=>$last < $count ? $last + 1 : null];
    }
    /** Validate local calendar fields without assigning a provider timezone. */
    private static function localClock(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4}) (\d{1,2}):(\d{2}):(\d{2}) (AM|PM)$/D', $value, $m)
            || (int)$m[4] < 1 || (int)$m[4] > 12) { return null; }
        $text = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[1], $m[2], (int)$m[4] % 12 + ($m[7] === 'PM' ? 12 : 0), $m[5], $m[6]);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $text ? $date : null;
    }
    /** Observed detail metadata is scalar; bundles, adjustments and unknown nesting stay unsupported. */
    private static function supported(ProtocolResult $order): bool
    {
        $header = $order->value('order'); $items = $order->value('order.orderdetail');
        $metadata = ['ccrefnum', 'adjusttoaccount', 'certs', 'orderstatus', 'taxamount', 'statusdesc', 'orderdate'];
        if ($order->status !== 'success' || $order->value('history_complete') !== true || !is_array($header) || !is_array($items) || !$items
            || array_diff(array_keys($header), ['orderid', 'result', 'orderbillamount', 'billdate', 'orderdetail', 'currency', ...$metadata])) { return false; }
        foreach ($header as $key=>$value) {
            if ($key !== 'orderdetail' && (!is_string($value) || strlen($value) > 512)) { return false; }
        }
        foreach (['adjusttoaccount'=>[''], 'certs'=>['False'], 'orderstatus'=>['6'], 'taxamount'=>['$0.00', '0.00'], 'statusdesc'=>['Successful']] as $key=>$values) {
            if (isset($header[$key]) && !in_array($header[$key], $values, true)) { return false; }
        }
        if (isset($header['orderdate']) && !self::localClock($header['orderdate'])) { return false; }
        foreach (array_is_list($items) ? $items : [$items] as $item) {
            $extra = ['detailstatusid', 'domainpassword', 'bundleid', 'bundleinstanceid', 'discount', 'bundledescription', 'bundlename'];
            if (!is_array($item) || array_diff(array_keys($item), ['description', 'producttype', 'quantity', 'status', 'orderstatus', 'amountpaid', 'expirydatetime', 'currency', ...$extra])) { return false; }
            foreach ($item as $value) { if (!is_string($value) || strlen($value) > 512) { return false; } }
            foreach ($extra as $key) {
                if (isset($item[$key]) && (!is_string($item[$key]) || $item[$key] !== ($key === 'detailstatusid' ? '9' : ''))) { return false; }
            }
        }
        return true;
    }
}
