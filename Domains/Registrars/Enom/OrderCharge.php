<?php
// Adapted from Enom 444f31c Services/RegistrarMoney.php::actualCost; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{Action, AttemptEvidence, DateEvidence, ExecutionRecord, Money, OrderEvidence, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;

/** Single exact item only. A quote, acknowledgement or order ID alone never proves a charge. */
final class OrderCharge
{
    public static function read(AttemptEvidence $attempt, ProtocolResult $execution, ProtocolResult $order): ?OrderEvidence
    {
        $query = $attempt->query; $id = $attempt->providerReference;
        $command = match ($query->action) { Action::Register => 'purchase', Action::Renew => 'extend', default => null };
        $header = $order->value('order'); $executed = $execution->value('command');
        if (!$command || $attempt->binding->registrarKey !== 'enom' || $attempt->execution !== ExecutionRecord::Recorded
            || $attempt->sendState === SendState::DefinitelyUnsent || !is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)
            || $execution->status !== 'success' || !is_string($executed) || strtolower($executed) !== $command
            || $execution->value('orderid') !== $id || $order->status !== 'success' || !is_array($header)
            || ($header['orderid'] ?? null) !== $id || !is_string($header['result'] ?? null) || strtolower($header['result']) !== 'true') { return null; }
        $items = $header['orderdetail'] ?? null;
        if (!is_array($items)) { return null; }
        $items = array_is_list($items) ? $items : [$items];
        if (count($items) !== 1 || !is_array($items[0])) { return null; }
        $item = $items[0];
        foreach (['description', 'status', 'orderstatus', 'producttype'] as $field) { if (!is_string($item[$field] ?? null)) { return null; } }
        if (strtolower($item['description']) !== $query->name || ($item['quantity'] ?? null) !== (string) $query->years
            || strtolower($item['status']) !== 'successful' || strtolower($item['orderstatus']) !== 'closed'
            || strtolower($item['producttype']) !== $query->action->value) { return null; }
        $bill = $header['orderbillamount'] ?? null;
        $total = Pricing::cents(is_string($bill) && str_starts_with($bill, '$') ? substr($bill, 1) : $bill);
        $amount = Pricing::cents($item['amountpaid'] ?? null);
        if ($amount === null || $total !== $amount) { return null; }
        foreach ([$header, $item] as $row) {
            if (array_key_exists('currency', $row) && (!is_string($row['currency']) || strtoupper($row['currency']) !== 'USD')) { return null; }
        }
        $processed = self::date($header['billdate'] ?? null, 'enom_order_bill_date');
        return new OrderEvidence($attempt->binding, $query, new Money($amount, 'USD'), $id, $processed, Expiry::read($order));
    }
    private static function date(mixed $value, string $source): ?DateEvidence
    {
        $date = Expiry::read(new ProtocolResult('success', data: ['expiration' => $value]));
        return $date ? new DateEvidence($date->value, $date->precision, $source, $date->timezone) : null;
    }
}
