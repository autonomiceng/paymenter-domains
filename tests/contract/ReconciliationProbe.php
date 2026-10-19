<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace OteContract;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, RegistrarRequest};

/** Lost-attempt diagnostics only. Inventory and order candidates never grant adoption or spending. */
final class ReconciliationProbe
{
    public static function window(array $receipt): array
    {
        $requests = $receipt['requests'] ?? null;
        if (($receipt['checks']['purchase_unresolved'] ?? null) !== true || !is_array($requests)) {
            throw new \RuntimeException('Unresolved attempt receipt required.');
        }
        $purchases = array_values(array_filter($requests, static fn ($r) => is_array($r)
            && in_array($r['command'] ?? null, ['registerDomain', 'renewDomain'], true)));
        if (count($purchases) !== 1 || $purchases[0]['command'] !== 'registerDomain') {
            throw new \RuntimeException('Single original registration required.');
        }
        $stamp = $purchases[0]['utc'] ?? null;
        if (!is_string($stamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $stamp)) {
            throw new \RuntimeException('Original UTC timestamp required.');
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $stamp);
        if (!$time || $time->format(DATE_ATOM) !== $stamp) { throw new \RuntimeException('Invalid original timestamp.'); }
        $start = $time->setTime((int) $time->format('H'), (int) $time->format('i'));
        return [$start->format('Y-m-d H:i:s'), $start->modify('+2 minutes')->format('Y-m-d H:i:s')];
    }
    private static function timestamp(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $value;
    }
    public static function inWindow(mixed $value, string $from, string $to): bool
    { return self::timestamp($value) && $value >= $from && $value < $to; }
    private static function amount(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) { return null; }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $result = (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
        return $result === '0.00' ? null : $result;
    }
    public static function rows(mixed $rows): array
    { return is_array($rows) ? (array_is_list($rows) ? $rows : [$rows]) : []; }
    /** Internal consistency is diagnostic, not a match to the lost original name or authorized price. */
    public static function consistent(array $header, array $detail, string $from, string $to): bool
    {
        if (!is_string($header['order_number'] ?? null) || !preg_match('/^[1-9][0-9]{0,19}$/D', $header['order_number'])
            || ($detail['order_number'] ?? null) !== $header['order_number']
            || !self::inWindow($header['order_date'] ?? null, $from, $to)
            || ($detail['order_date'] ?? null) !== $header['order_date']) { return false; }
        $items = self::rows($detail['order_details'] ?? null);
        if (count($items) !== 1 || !is_array($items[0])) { return false; }
        $item = $items[0]; $amount = self::amount($header['total'] ?? null);
        return $amount !== null && self::amount($detail['total'] ?? null) === $amount
            && self::amount($item['price'] ?? null) === $amount && self::amount($item['subtotal'] ?? null) === $amount
            && is_string($item['description'] ?? null)
            && preg_match('/^contract-ote-[0-9a-f]{12}\.(?:com|net) - registration$/D', $item['description']) === 1
            && ($item['years_qty'] ?? null) === '1' && ($item['status'] ?? null) === 'Processed'
            && !array_key_exists('credited_amount', $item) && !array_key_exists('credited_date', $item);
    }
    public static function execute(GuardedTransport $wire, \Closure $read, array $prior): int
    {
        [$from, $to] = self::window($prior);
        $facts = ['category' => 'ambiguous', 'adopted' => false, 'original_name_retained' => false,
            'original_amount_retained' => false, 'history_timezone_accepted' => false,
            'history_complete' => false, 'literal_window_headers' => 0, 'details_examined' => 0,
            'consistent_registration_rows' => 0, 'orders_read_success' => false, 'inventory_valid' => false, 'inventory_empty' => null];
        $wire->receipt['reconciliation'] = $facts;
        $wire->check('purchase_unresolved', true); $wire->save();
        try {
            self::inspect($wire, $read, $from, $to);
            $wire->check('completed', true);
        } catch (\Throwable) { $wire->check('aborted', true); }
        return 3;
    }
    private static function inspect(GuardedTransport $wire, \Closure $read, string $from, string $to): void
    {
        $facts = &$wire->receipt['reconciliation'];
        // Broad discovery avoids claiming a timezone for the provider's naive timestamps.
        $day = new \DateTimeImmutable(substr($from, 0, 10), new \DateTimeZone('UTC'));
        $orders = $read(RegistrarRequest::listOrders($day->modify('-1 day')->format('Y-m-d'), $day->modify('+1 day')->format('Y-m-d')));
        $wire->raw('https://ote.namesilo.com/apibatch', 'listDomains', ['pageSize' => 10, 'page' => 1]);
        $inventory = $wire->latest('listDomains');
        $facts['inventory_valid'] = !array_filter($wire->receipt['mismatches'], static fn ($r) => $r['command'] === 'listDomains');
        $facts['inventory_valid'] = $facts['inventory_valid']
            && in_array($inventory['pager']['page'] ?? null, [1, '1'], true)
            && in_array($inventory['pager']['pageSize'] ?? null, [10, '10'], true)
            && (is_int($inventory['pager']['total'] ?? null) || is_string($inventory['pager']['total'] ?? null))
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $inventory['pager']['total']) === 1;
        if ($facts['inventory_valid']) {
            $facts['inventory_empty'] = in_array($inventory['pager']['total'] ?? null, [0, '0'], true)
                && in_array($inventory['domains'] ?? null, [null, []], true);
        }
        $facts['orders_read_success'] = $orders->outcome === Outcome::Success;
        $headers = $facts['orders_read_success'] ? self::rows($orders->value('order')) : [];
        $seen = [];
        foreach ($headers as $header) {
            if (!is_array($header) || !self::inWindow($header['order_date'] ?? null, $from, $to)) { continue; }
            $facts['literal_window_headers']++;
            $id = $header['order_number'] ?? null;
            if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id) || isset($seen[$id]) || $wire->remaining() < 1) { continue; }
            $seen[$id] = true;
            $detail = $read(RegistrarRequest::orderDetails($id)); $facts['details_examined']++;
            if ($detail->outcome === Outcome::Success && self::consistent($header, $detail->catalogueRows(), $from, $to)) {
                $facts['consistent_registration_rows']++;
            }
        }
        // Neither zero, one nor multiple candidates can reconstruct missing original identity/price,
        // establish provider history lag/retention/timezone, or prove that the purchase never happened.
    }
}
