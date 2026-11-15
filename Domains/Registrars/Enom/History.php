<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, CanonicalName, Evidence, ExpiryPrecision, HistoryScan, RequestContext};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, ProtocolResult};
/** Accepted, counted Enom history only. No partial scan can establish uniqueness. A dense day continues on later scheduled
 * reads: each read lists every page again, keeps validated order outcomes bound to their listed IDs and dates, and reads
 * the next orders within the read budget. Only a full scan with every order resolved is complete. */
final class History
{
    /** Order details per read, and listed orders per scan (ten pages). */
    private const DETAILS = 20, ORDERS = 250;
    /** Sanitized reasons a read could not finish the scan, saved with counts so a stuck scan can be diagnosed. */
    private const REFUSALS = ['read_budget', 'list_invalid', 'list_changed', 'list_too_large', 'list_duplicate', 'order_open', 'detail_ceiling',
        'detail_unavailable', 'detail_unsupported', 'order_date_changed', 'order_unreadable', 'multiple_matches', 'other_zone_candidate'];
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
        $key = HistoryScan::key($attempt); $saved = $attempt->historyScan?->key === $key ? $attempt->historyScan->state : [];
        $priorRefusals = self::counts($saved['refusals'] ?? null);
        $continueOpen = ($saved['complete'] ?? null) === false && $priorRefusals
            && !array_diff(array_keys($priorRefusals), ['detail_ceiling', 'read_budget']);
        $checked = $continueOpen ? self::outcomes($saved['open_checked'] ?? null) : [];
        $known = self::outcomes($saved['orders'] ?? null); $refusals = [];
        $refuse = function (string $category) use (&$refusals): void { $refusals[$category] = ($refusals[$category] ?? 0) + 1; };
        $result = function (bool $complete, array $orders, array $candidates, int $listed, int $window, bool $progressed) use (&$refusals, &$checked, $saved, $key): Evidence {
            $totals = self::counts($saved['refusal_totals'] ?? null);
            foreach ($refusals as $category=>$count) { $totals[$category] = min(1000000, ($totals[$category] ?? 0) + $count); }
            $encoded = [];
            foreach ($orders as $id=>[$outcome, $signature]) { $encoded[(string)$id] = $outcome.':'.$signature; }
            $validatedOpen = [];
            foreach ($checked as $id=>[$outcome, $signature]) { $validatedOpen[(string)$id] = $outcome.':'.$signature; }
            $state = ['orders'=>$encoded, 'open_checked'=>$validatedOpen, 'listed'=>$listed, 'in_window'=>$window, 'resolved'=>count($orders), 'complete'=>$complete,
                'reads'=>min(1000000, (int)($saved['reads'] ?? 0) + 1), 'refusals'=>$refusals, 'refusal_totals'=>$totals];
            return new Evidence(candidates: $candidates, historyComplete: $complete, historyScan: new HistoryScan($key, $state, $progressed && !$complete));
        };
        $stop = function (string $category) use ($refuse, $result, $known, $saved): Evidence {
            $refuse($category); return $result(false, $known, [], (int)($saved['listed'] ?? 0), (int)($saved['in_window'] ?? 0), false);
        };
        $start = 1; $total = null; $ids = []; $rows = [];
        do {
            if ($context->budget->remaining() < 1 || $context->now() >= $context->deadline) { return $stop('read_budget'); }
            $reply = $this->client->read(ReadCommand::Orders, $parameters + ['Start'=>$start], $context);
            if ($context->now() >= $context->deadline) { return $stop('read_budget'); }
            $page = self::page($reply, $start, $begin, $end);
            if (!$page) { return $stop('list_invalid'); }
            if ($total !== null && $total !== $page['count']) { return $stop('list_changed'); }
            if ($page['count'] > self::ORDERS) { return $stop('list_too_large'); }
            $total = $page['count'];
            foreach ($page['rows'] as $row) {
                if (isset($ids[$row['orderid']])) { return $stop('list_duplicate'); }
                $ids[$row['orderid']] = true;
                $placed = self::localClock($row['orderdate']);
                // Each row was validated by page(). A different day cannot be this original send.
                if ($placed >= $begin && $placed < $end) { $rows[] = $row; }
            }
            $start = $page['next'];
        } while ($start !== null);
        if (count($ids) !== $total) { return $stop('list_changed'); }
        // A saved outcome counts only for the same listed order and receipt date; a candidate is read again before it is used.
        $orders = []; $pending = []; $rechecks = []; $stale = []; $complete = true;
        $listing = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $priorChecked = $checked; $checked = [];
        foreach ($rows as $row) {
            $row['open'] = $row['statusdesc'] !== 'Order complete' || $row['orderprocessflag'] !== 'True';
            $id = $row['orderid']; $signature = substr(hash('sha256', $row['orderdate'].($row['open'] ? $listing : '')), 0, 12); $row['signature'] = $signature;
            // Open exclusions continue only an unchanged listing stopped by capacity. A finished or blocked scan re-reads them.
            if ($row['open'] && (!$continueOpen || ($known[$id][0] ?? null) !== 'n' || ($priorChecked[$id] ?? null) !== ['n', $signature])) {
                if (($known[$id] ?? null) === ['n', $signature]) { $orders[$id] = $known[$id]; $rechecks[] = $row; }
                else { $pending[] = $row; }
                continue;
            }
            if (($known[$id][1] ?? null) === $signature) {
                $orders[$id] = $known[$id];
                if ($row['open']) { $checked[$id] = $known[$id]; }
                if ($known[$id][0] === 'c') { $stale[] = $row; }
            }
            else { $pending[] = $row; }
        }
        // Resolve new or changed orders before revalidation; retain prior outcomes if capacity interrupts the rechecks.
        $pending = [...$pending, ...$rechecks];
        $candidates = []; $read = 0; $added = 0;
        foreach ($pending as $row) {
            if ($read >= self::DETAILS || $context->budget->remaining() <= count($stale) || $context->now() >= $context->deadline) { $complete = false; $refuse($read >= self::DETAILS ? 'detail_ceiling' : 'read_budget'); break; }
            $prior = $orders[$row['orderid']] ?? null;
            $read++; unset($orders[$row['orderid']]);
            if ($row['open'] ?? false) { $outcome = $this->otherDomain($attempt, $context, $row) ? 'n' : 'order_open'; $candidate = null; }
            else { [$outcome, $candidate] = $this->detail($attempt, $context, $row); }
            // Keep earlier validated outcomes, but never accept a reply that used up the deadline.
            if ($context->now() >= $context->deadline) {
                if ($prior !== null) { $orders[$row['orderid']] = $prior; }
                $complete = false; $refuse('read_budget'); break;
            }
            if (!in_array($outcome, ['n', 'c'], true)) { $complete = false; $refuse($outcome); continue; }
            $validated = [$outcome, $row['signature']];
            if (($known[$row['orderid']] ?? null) !== $validated) { $added++; }
            $orders[$row['orderid']] = $validated;
            if ($row['open']) { $checked[$row['orderid']] = $validated; }
            if ($candidate) { $candidates[] = $candidate; }
        }
        if ($complete && $context->budget->remaining() < count($stale)) { $complete = false; $refuse('read_budget'); }
        if ($complete) {
            foreach ($stale as $row) {
                if ($context->now() >= $context->deadline) { $complete = false; $refuse('read_budget'); break; }
                [$outcome, $candidate] = $this->detail($attempt, $context, $row);
                if ($context->now() >= $context->deadline) { $complete = false; $refuse('read_budget'); break; }
                if ($candidate) { $candidates[] = $candidate; }
                elseif ($outcome === 'n') { $orders[$row['orderid']] = ['n', $row['signature']]; }
                else { unset($orders[$row['orderid']]); $complete = false; $refuse($outcome); }
            }
            if ($complete && count($candidates) > 1) { $refuse('multiple_matches'); }
        }
        return $result($complete, $orders, $candidates, count($ids), count($rows), $added > 0);
    }
    /** One exact order: 'n' a validated non-match, 'c' a matching candidate, otherwise a refusal category. */
    private function detail(AttemptEvidence $attempt, RequestContext $context, array $row): array
    {
        $id = $row['orderid'];
        $order = $this->client->read(ReadCommand::Order, ['OrderID'=>$id], $context);
        if ($order->status !== 'success') { return ['detail_unavailable', null]; }
        if (!self::supported($order) || $order->value('order.orderid') !== $id) { return ['detail_unsupported', null]; }
        if ($order->value('order.orderdate') !== null && self::localClock($order->value('order.orderdate')) != self::localClock($row['orderdate'])) { return ['order_date_changed', null]; }
        if (OrderCharge::unrelated($attempt, $order)) { return ['n', null]; }
        // Every bill zone reading must be a valid instant (named-zone DST; ambiguous or missing local times refuse). A validated
        // different action/term, or a bill time outside the send window in every reading, cannot be this original send. An order
        // that fits only in an unmeasured zone is neither: it keeps the scan unresolved and is never saved.
        [$match, $watch] = OrderCharge::billZones($attempt->binding->environment, $attempt->billZone);
        $candidate = null; $other = false;
        foreach ([...$match, ...$watch] as $zone) {
            $reading = OrderCharge::fromHistory($attempt->binding, $order, $zone);
            if (!$reading || $reading->processedAt?->precision !== ExpiryPrecision::Instant) { return ['order_unreadable', null]; }
            $processed = new \DateTimeImmutable($reading->processedAt->value);
            if (!$reading->query->matches($attempt->query) || $processed < $attempt->windowStart || $processed > $attempt->windowEnd) { continue; }
            if (!in_array($zone, $match, true)) { $other = true; continue; }
            if ($candidate) { return ['order_unreadable', null]; }
            $candidate = $reading;
        }
        return $candidate ? ['c', $candidate] : [$other ? 'other_zone_candidate' : 'n', null];
    }
    /** True only when the exact order's detail positively names a different canonical domain on every item. */
    private function otherDomain(AttemptEvidence $attempt, RequestContext $context, array $row): bool
    {
        $id = $row['orderid'];
        $order = $this->client->read(ReadCommand::Order, ['OrderID'=>$id], $context);
        $items = $order->value('order.orderdetail');
        if ($order->status !== 'success' || $order->value('order.orderid') !== $id || !is_array($items) || !$items
            || self::localClock($order->value('order.orderdate')) != self::localClock($row['orderdate'])) { return false; }
        foreach (array_is_list($items) ? $items : [$items] as $item) {
            try { if (!is_array($item) || !is_string($item['description'] ?? null) || CanonicalName::normalize($item['description']) === $attempt->query->name) { return false; } }
            catch (\InvalidArgumentException|\TypeError) { return false; }
        }
        return true;
    }
    /** Saved outcomes keyed by order ID; anything malformed starts the scan again. */
    private static function outcomes(mixed $saved): array
    {
        if (!is_array($saved) || count($saved) > self::ORDERS) { return []; }
        $known = [];
        foreach ($saved as $id=>$value) {
            if (!preg_match('/^[1-9][0-9]{0,19}$/D', (string)$id) || !is_string($value) || !preg_match('/^([nc]):([a-f0-9]{12})$/D', $value, $m)) { return []; }
            $known[(string)$id] = [$m[1], $m[2]];
        }
        return $known;
    }
    private static function counts(mixed $saved): array
    {
        $counts = [];
        foreach (is_array($saved) ? $saved : [] as $category=>$count) {
            if (in_array($category, self::REFUSALS, true) && is_int($count) && $count >= 0) { $counts[$category] = $count; }
        }
        return $counts;
    }
    /** Fixed 25-record pages, stable total, contiguous positions, and an explicit terminal page. Live returns a counted single page
     * with no pagination fields at all (Nov 14 2026 sample); that is accepted only for Start=1, fewer than 25 orders and every listed row present. */
    private static function page(ProtocolResult $reply, int $start, \DateTimeImmutable $begin, \DateTimeImmutable $end): ?array
    {
        $list = $reply->value('orderlist');
        $fields = ['begindate', 'enddate', 'orderdetail', 'showpreviousandnext', 'showpreviouslink', 'previousstartposition',
            'showstart', 'showend', 'shownextlink', 'nextstartposition', 'recordcount'];
        if ($reply->status !== 'success' || $reply->value('history_complete') !== true || !is_array($list)
            || array_diff(array_keys($list), $fields)) { return null; }
        $from = self::localClock($list['begindate'] ?? null); $until = self::localClock($list['enddate'] ?? null);
        if (!$from || !$until || $from > $begin || $until < $end) { return null; }
        $paging = array_intersect_key($list, array_flip(array_slice($fields, 3, 7)));
        foreach ($paging ? ['recordcount', 'showstart', 'showend'] : ['recordcount'] as $key) {
            if (!is_string($list[$key] ?? null) || !preg_match('/^(?:0|[1-9][0-9]{0,3})$/D', $list[$key])) { return null; }
        }
        $count = (int)$list['recordcount']; $rows = $list['orderdetail'] ?? [];
        if (!is_array($rows)) { return null; }
        $rows = array_is_list($rows) ? $rows : [$rows];
        // A full 25-row page without pagination cannot show that no second page exists.
        if (!$paging && ($start !== 1 || $count >= 25 || count($rows) !== $count)) { return null; }
        foreach ($paging ? ['showpreviousandnext', 'showpreviouslink', 'shownextlink'] : [] as $key) {
            if (!in_array($list[$key] ?? null, ['Yes', 'No'], true)) { return null; }
        }
        $size = $count === 0 ? 0 : min(25, $count - $start + 1);
        $last = $size ? $start + $size - 1 : 0;
        if ($size < 0 || count($rows) !== $size || ($count === 0 && $start !== 1)) { return null; }
        if ($paging && ((int)$list['showstart'] !== ($size ? $start : 0) || (int)$list['showend'] !== $last
            || $list['showpreviouslink'] !== ($start > 1 ? 'Yes' : 'No')
            || $list['shownextlink'] !== ($last < $count ? 'Yes' : 'No')
            || ($count > 25 && $list['showpreviousandnext'] !== 'Yes'))) { return null; }
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
