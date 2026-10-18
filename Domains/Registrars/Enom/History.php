<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, Evidence, ExpiryPrecision, RequestContext};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, ProtocolResult};
/** Opt-in flat OrderList/OrderCount contract. Unaccepted or paged variants remain unresolved. */
final class History
{
    public function __construct(private readonly Client $client) {}
    public function inspect(AttemptEvidence $attempt, RequestContext $context): Evidence
    {
        $policy = $attempt->historyAcceptance;
        if (!$policy?->covers($attempt, $context->now())) { return new Evidence; }
        $zone = new \DateTimeZone($policy->timezone);
        $reply = $this->client->read(ReadCommand::Orders, ['BeginDate' => $attempt->windowStart->setTimezone($zone)->modify('-1 day')->format('m/d/Y'),
            'EndDate' => $attempt->windowEnd->setTimezone($zone)->modify('+1 day')->format('m/d/Y'), 'Start' => 1], $context);
        $list = $reply->value('orderlist'); $count = $reply->value('ordercount');
        if ($reply->status !== 'success' || $reply->value('history_complete') !== true || !is_array($list) || array_diff(array_keys($list), ['order'])
            || !is_string($count) || !preg_match('/^(?:0|[1-9][0-9]?)$/D', $count)) { return new Evidence; }
        $rows = $list['order'] ?? []; $rows = array_is_list($rows) ? $rows : [$rows];
        if (count($rows) !== (int) $count || count($rows) > min(20, $context->budget->remaining())) { return new Evidence; }
        $candidates = []; $ids = []; $complete = true;
        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['orderid'] ?? null) : null;
            if (!is_array($row) || array_diff(array_keys($row), ['orderid']) || !is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id) || isset($ids[$id])) { return new Evidence; }
            $ids[$id] = true;
            $order = $this->client->read(ReadCommand::Order, ['OrderID' => $id], $context);
            if (!self::supported($order)) { $complete = false; continue; }
            if ($order->value('order.orderid') === $id && OrderCharge::unrelated($attempt, $order)) { continue; }
            $candidate = $order->value('order.orderid') === $id ? OrderCharge::candidate($attempt, $order) : null;
            // Failed/unsupported order details cannot certify that no competing order exists.
            if (!$candidate) { $complete = false; continue; }
            if (!$candidate->processedAt || $candidate->processedAt->precision !== ExpiryPrecision::Instant) { $complete = false; continue; }
            if (new \DateTimeImmutable($candidate->processedAt->value) < $attempt->windowStart
                || new \DateTimeImmutable($candidate->processedAt->value) > $attempt->windowEnd) { $complete = false; continue; }
            $candidates[] = $candidate;
        }
        return new Evidence(candidates: $candidates, historyComplete: $complete);
    }
    /** Unknown nesting/metadata may hide more items/pages; only the accepted flat profile is complete. */
    private static function supported(ProtocolResult $order): bool
    {
        $header = $order->value('order'); $items = $order->value('order.orderdetail');
        if ($order->value('history_complete') !== true || !is_array($header) || !is_array($items) || !$items
            || array_diff(array_keys($header), ['orderid', 'result', 'orderbillamount', 'billdate', 'orderdetail', 'currency'])) { return false; }
        foreach (array_is_list($items) ? $items : [$items] as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['description', 'producttype', 'quantity', 'status', 'orderstatus', 'amountpaid', 'expirydatetime', 'currency'])) { return false; }
        }
        return true;
    }
}
