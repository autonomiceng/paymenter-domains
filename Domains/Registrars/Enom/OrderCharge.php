<?php
// Adapted from Enom 444f31c Services/RegistrarMoney.php::actualCost; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DateEvidence, Environment, Money, OrderEvidence, PersistedExecution, CanonicalName, HistoryAcceptance, ExpiryPrecision, Query};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;

/** Single exact item only. A quote, acknowledgement or order ID alone never proves a charge. */
final class OrderCharge
{
    public static function read(AttemptEvidence $attempt, PersistedExecution $execution, ProtocolResult $order): ?OrderEvidence
    {
        try { $execution->requireAttempt($attempt); } catch (\InvalidArgumentException) { return null; }
        return self::candidate($attempt, $order);
    }
    /** Discovery candidates need a complete accepted history contract at the engine, never fabricated execution. */
    public static function candidate(AttemptEvidence $attempt, ProtocolResult $order): ?OrderEvidence
    {
        $id = $order->value('order.orderid');
        if ($attempt->providerReference !== null && $id !== $attempt->providerReference) { return null; }
        return self::normalize($attempt->binding, $attempt->query, $order,
            $attempt->executionFacts !== null && $attempt->providerReference !== null && $id === $attempt->providerReference);
    }
    /** Validate an observed order on its own facts before comparing it with a lost attempt. */
    public static function fromHistory(AccountBinding $binding, ProtocolResult $order): ?OrderEvidence
    {
        $item = $order->value('order.orderdetail');
        if (!is_array($item) || array_is_list($item) || !is_string($item['description'] ?? null)
            || !is_string($item['producttype'] ?? null) || !is_string($item['quantity'] ?? null)
            || !preg_match('/^(?:[1-9]|10)$/D', $item['quantity'])) { return null; }
        $action = Action::tryFrom(strtolower($item['producttype']));
        if (!in_array($action, [Action::Register, Action::Renew, Action::Transfer], true)) { return null; }
        try { $query = new Query(strtolower($item['description']), $action, (int)$item['quantity']); }
        catch (\InvalidArgumentException) { return null; }
        return self::normalize($binding, $query, $order, false);
    }
    private static function normalize(AccountBinding $binding, Query $query, ProtocolResult $order, bool $captured): ?OrderEvidence
    {
        $header = $order->value('order');
        $id = is_array($header) ? ($header['orderid'] ?? null) : null;
        if (!in_array($query->action, [Action::Register, Action::Renew, Action::Transfer], true) || $binding->registrarKey !== 'enom'
            || !is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)
            || $order->status !== 'success' || !is_array($header) || strtolower($header['result'] ?? '') !== 'true') { return null; }
        $items = $header['orderdetail'] ?? null;
        if (!is_array($items)) { return null; }
        $items = array_is_list($items) ? $items : [$items];
        if (count($items) !== 1 || !is_array($items[0])) { return null; }
        $item = $items[0];
        foreach (['description', 'status', 'orderstatus', 'producttype'] as $field) { if (!is_string($item[$field] ?? null)) { return null; } }
        if (strtolower($item['description']) !== $query->name || ($item['quantity'] ?? null) !== (string) $query->years
            || strtolower($item['status']) !== 'successful' || strtolower($item['orderstatus']) !== 'closed'
            || strtolower($item['producttype']) !== $query->action->value) { return null; }
        $total = self::orderAmount($header['orderbillamount'] ?? null);
        $amount = self::orderAmount($item['amountpaid'] ?? null);
        if ($amount === null || $total !== $amount) { return null; }
        foreach ([$header, $item] as $row) {
            if (array_key_exists('currency', $row) && (!is_string($row['currency']) || strtoupper($row['currency']) !== 'USD')) { return null; }
        }
        $processed = self::date($header['billdate'] ?? null, 'enom_order_bill_date',
            $captured, self::billZone($binding->environment));
        return new OrderEvidence($binding, $query, new Money($amount, 'USD'), $id, $processed, Expiry::read($order));
    }
    /** Order amounts may carry one leading dollar sign; quote parsing stays strict. */
    private static function orderAmount(mixed $value): ?string
    {
        return Pricing::cents(is_string($value) && str_starts_with($value, '$') ? substr($value, 1) : $value);
    }
    /** Every parsed item must positively name a different canonical domain. */
    public static function unrelated(AttemptEvidence $attempt, ProtocolResult $order): bool
    {
        $items = $order->value('order.orderdetail');
        if ($order->status !== 'success' || strtolower($order->value('order.result', '')) !== 'true' || !is_array($items) || !$items) { return false; }
        $items = array_is_list($items) ? $items : [$items];
        foreach ($items as $item) {
            try {
                if (!is_array($item) || !is_string($item['description'] ?? null)
                    || CanonicalName::normalize($item['description']) === $attempt->query->name
                    || !in_array(strtolower($item['producttype'] ?? ''), ['register', 'renew', 'transfer'], true)
                    || strtolower($item['status'] ?? '') !== 'successful' || strtolower($item['orderstatus'] ?? '') !== 'closed') { return false; }
            } catch (\InvalidArgumentException|\TypeError) { return false; }
        }
        return true;
    }
    /** Enom TEST bill dates are US Mountain time: 87 captured TEST orders read UTC-6 until 08:00 UTC on Nov 1, 2026 and UTC-7 after.
     * Live is not measured and keeps Pacific. */
    private static function billZone(Environment $environment): string
    { return $environment === Environment::Test ? 'America/Denver' : 'America/Los_Angeles'; }
    private static function date(mixed $value, string $source, bool $captured, string $zone): ?DateEvidence
    {
        if (!is_string($value)) { return null; }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4}) (\d{1,2}):(\d{2}):(\d{2}) (AM|PM)$/D', $value, $m)) {
            if ((int) $m[4] < 1 || (int) $m[4] > 12) { return null; }
            $value = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[1], $m[2], (int) $m[4] % 12 + ($m[7] === 'PM' ? 12 : 0), $m[5], $m[6]);
        }
        // UTC here validates calendar fields only; captured evidence retains no invented offset.
        if ($captured) {
            $local = HistoryAcceptance::localDate($value, 'UTC', $source);
            return $local ? new DateEvidence(substr($local->value, 0, 19), ExpiryPrecision::Local, $source) : null;
        }
        $date = HistoryAcceptance::localDate($value, $zone, $source);
        return $date ? new DateEvidence((new \DateTimeImmutable($date->value))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM), ExpiryPrecision::Instant, $source, 'UTC') : null;
    }
}
