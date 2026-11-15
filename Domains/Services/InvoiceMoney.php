<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;

/** Read-only invoice projection. Amounts are signed integer minor units; no balance is persisted. */
final class InvoiceMoney
{
    /** HoldingLocks must already own the user, credit, invoice and native transaction rows. */
    public static function load(array $l, int $invoiceId): array
    {
        if (DB::transactionLevel() < 1 || ($l['advisory'] ?? true)) { throw new \LogicException('Money reads require holding locks.'); }
        $invoice = $l['invoices']->firstWhere('id', $invoiceId);
        if (!$invoice || (int)$invoice->user_id !== (int)$l['user']->id) { throw new \DomainException('Invoice owner changed.'); }
        $peers = Operation::where('invoice_id', $invoiceId)->orderBy('id')->lockForUpdate()->get();
        $ownerHold = DB::table('domains_obligation_alerts')->join('domains_domains', 'domains_domains.id', '=', 'domains_obligation_alerts.domain_id')
            ->where('domains_domains.user_id', $l['user']->id)->where('code', 'account_money_hold')->whereNull('resolved_at')
            ->orderBy('domains_obligation_alerts.id')->lockForUpdate()->get(['domains_obligation_alerts.id'])->isNotEmpty();
        $rows = []; $ledger = []; $gateways = [];
        foreach ($l['transactions']->where('invoice_id', $invoiceId) as $row) {
            $gateway = $row->gateway;
            $readable = $gateway && ExtensionHelper::hasFunction($gateway, 'receiptLedger');
            $capable = $readable && ReceiptReturns::capable($gateway);
            $rows[] = ['id'=>(int)$row->id, 'amount'=>(string)$row->amount, 'status'=>$row->status->value,
                'gateway_id'=>(int)$row->gateway_id, 'transaction_id'=>$row->transaction_id, 'cardOk'=>$capable];
            if (!$readable || $row->status !== InvoiceTransactionStatus::Succeeded || PricingRule::minor($row->amount) <= 0 || isset($gateways[$gateway->id])) { continue; }
            $gateways[$gateway->id] = true;
            foreach (ExtensionHelper::call($gateway, 'receiptLedger', [$invoice]) as $entry) {
                $entry = (array)$entry;
                if ((int)($entry['invoice_id'] ?? 0) !== $invoiceId || (int)($entry['user_id'] ?? 0) !== (int)$invoice->user_id
                    || strtoupper($entry['currency'] ?? '') !== $invoice->currency_code) { throw new \DomainException('Receipt ledger identity changed.'); }
                $ledger[] = [...$entry, 'gateway_id'=>(int)$gateway->id];
            }
        }
        return self::record($rows, $peers->map(fn($op)=>['funding'=>$op->funding ?? []])->all(), $ledger, $ownerHold);
    }

    /** Plain native rows (cardOk supplied by load), peer funding arrays and raw gateway ledger rows. No queries or I/O. */
    public static function record(iterable $rows, iterable $peers, array $ledger, bool $ownerHold = false): array
    {
        $rows = [...$rows]; $peers = [...$peers];
        $receipts = []; $negative = []; $pending = false; $used = []; $allocations = []; $refunds = []; $recoveries = []; $U = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'processing') { $pending = true; }
            if ($row['status'] !== 'succeeded') { continue; }
            $minor = PricingRule::minor($row['amount']);
            if ($minor < 0) { $negative[$row['id']] = $row; continue; }
            if ($minor === 0) { continue; }
            $receipts[$row['id']] = ['gross'=>$minor, 'completed'=>0, 'reserved'=>0, 'credit'=>0, 'external'=>0,
                'recovered'=>0, 'hold'=>false, 'cardOk'=>(bool)($row['cardOk'] ?? false), 'gateway_id'=>$row['gateway_id'], 'receipt'=>$row['transaction_id']];
        }
        ksort($receipts, SORT_NUMERIC);
        $identity = static fn($gateway, $receipt, $key): string => json_encode([$gateway, $receipt, $key], JSON_THROW_ON_ERROR);
        $entries = [];
        foreach ($ledger as $entry) {
            if (!isset($entry['gateway_id'], $entry['payment_intent_id'], $entry['allocation_key'], $entry['state'], $entry['amount_minor'])
                || !ctype_digit((string)$entry['amount_minor'])) { throw new \DomainException('Unreadable receipt ledger row.'); }
            $key = $identity($entry['gateway_id'], $entry['payment_intent_id'], $entry['allocation_key']);
            if (isset($entries[$key]) && $entries[$key] !== $entry) { throw new \DomainException('Conflicting receipt ledger rows.'); }
            $entries[$key] = $entry;
        }
        foreach ($peers as $peer) {
            $funding = $peer['funding'] ?? [];
            foreach ([...(isset($funding['refund']) ? [$funding['refund']] : []), ...($funding['late_credits'] ?? [])] as $plan) {
                if (ReceiptReturns::amount($plan) === null) { throw new \DomainException('Retained return plan needs review.'); }
                $split = [];
                foreach ($plan['payments'] as $p) { if ($p['amount_minor'] > 0) { $split[$p['id']] = ($split[$p['id']] ?? 0) + $p['amount_minor']; } }
                foreach ($plan['allocations'] ?? [] as $a) {
                    $id = $a['transaction_id']; $r = $receipts[$id] ?? null;
                    if (!$r || $r['gateway_id'] !== $a['gateway_id'] || $r['receipt'] !== $a['receipt']) { throw new \DomainException('Return receipt identity changed.'); }
                    $key = $identity($a['gateway_id'], $a['receipt'], $a['gateway_key'] ?? $a['key']);
                    if (isset($allocations[$key])) { throw new \DomainException('Duplicate receipt allocation.'); }
                    $result = $funding['receipt_return_results'][$a['key']] ?? [];
                    if (isset($result['amount_minor']) && $result['amount_minor'] !== $a['amount_minor']) { throw new \DomainException('Return result amount changed.'); }
                    $entry = $entries[$key] ?? null;
                    if ($entry && (int)$entry['amount_minor'] !== $a['amount_minor']) { throw new \DomainException('Receipt allocation amount changed.'); }
                    $allocations[$key] = [$id, $a['amount_minor'], $entry ?? $result];
                    $split[$id] = ($split[$id] ?? 0) - $a['amount_minor'];
                }
                $minor = ($plan['to'] ?? null) === 'original_payment' ? ReceiptReturns::bridge($plan) : PricingRule::minor($plan['amount']);
                $split = array_filter($split, fn($v)=>$v > 0);
                if (count($split) === 1 && reset($split) >= $minor) { $split = [array_key_first($split)=>$minor]; }
                if (array_sum($split) !== $minor || array_diff_key($split, $receipts)) { $U += $minor; }
                else { foreach ($split as $id=>$amount) { $receipts[$id]['credit'] += $amount; } }
            }
            foreach ($funding['dispute_holds'] ?? [] as $recovery) {
                $id = $recovery['payment_id'];
                if (!isset($receipts[$id]) || !is_int($recovery['removed_minor']) || $recovery['removed_minor'] < 0
                    || isset($recoveries[$recovery['dispute_id']])) { throw new \DomainException('Credit recovery needs review.'); }
                $recoveries[$recovery['dispute_id']] = true;
                $receipts[$id]['recovered'] += $recovery['removed_minor'];
            }
        }
        // Gateway rows survive a crash before Domains saves a result, including gateway-owned excess returns.
        foreach ($entries as $key=>$entry) {
            if (str_starts_with($entry['allocation_key'], 'stripe:') || isset($allocations[$key])) { continue; }
            $ids = array_keys(array_filter($receipts, fn($r)=>$r['gateway_id'] === $entry['gateway_id'] && $r['receipt'] === $entry['payment_intent_id']));
            if (count($ids) !== 1) { throw new \DomainException('Gateway receipt is missing or ambiguous.'); }
            $allocations[$key] = [$ids[0], (int)$entry['amount_minor'], $entry];
        }
        foreach ($allocations as [$id, $minor, $result]) {
            $state = $result['state'] ?? 'reserved';
            if ($state === 'released') {
                if (!is_int($result['credit_minor'] ?? null) || $result['credit_minor'] < 0 || $result['credit_minor'] > $minor) { throw new \DomainException('Released credit needs review.'); }
                $receipts[$id]['credit'] += $result['credit_minor']; continue;
            }
            $key = $result['return_key'] ?? $result['key'] ?? null;
            $matches = $key === null ? [] : array_filter($negative, fn($r)=>$r['transaction_id'] === $key);
            if (count($matches) > 1) { throw new \DomainException('Duplicate native return identity.'); }
            if (in_array($state, ['refunded', 'credited'], true)) {
                if (count($matches) !== 1) { throw new \DomainException('Completed return reversal needs review.'); }
                $nativeId = array_key_first($matches);
                if (isset($used[$nativeId]) || PricingRule::minor($matches[$nativeId]['amount']) !== -$minor) { throw new \DomainException('Completed return amount changed.'); }
                $used[$nativeId] = true; $receipts[$id]['completed'] += $minor;
                if (!empty($result['refund_id'])) { $refunds[$identity($receipts[$id]['gateway_id'], $receipts[$id]['receipt'], $result['refund_id'])] = true; }
            } else {
                $receipts[$id]['reserved'] += $minor;
                // A known sending key may already have its native row while a local result is stale.
                foreach ($matches as $nativeId=>$native) { if (PricingRule::minor($native['amount']) !== -$minor || isset($used[$nativeId])) { throw new \DomainException('Sending return reversal needs review.'); } $used[$nativeId] = true; }
                if ($state === 'review') { $receipts[$id]['hold'] = true; }
            }
        }
        foreach ($entries as $entry) {
            if (!preg_match('/^stripe:(refund|dispute):(.+)$/D', $entry['allocation_key'], $parts)) { continue; }
            $ids = array_keys(array_filter($receipts, fn($r)=>$r['gateway_id'] === $entry['gateway_id'] && $r['receipt'] === $entry['payment_intent_id']));
            if (count($ids) !== 1) { throw new \DomainException('Reversal receipt is missing or ambiguous.'); }
            $id = $ids[0]; $state = $entry['state'];
            if ($parts[1] === 'refund' && isset($refunds[$identity($entry['gateway_id'], $entry['payment_intent_id'], $parts[2])])) { continue; }
            $receipts[$id]['cardOk'] = false;
            if ($parts[1] === 'dispute' ? $state === 'lost' : $state === 'refunded') { $receipts[$id]['external'] += (int)$entry['amount_minor']; }
            if ($parts[1] === 'dispute' ? !in_array($state, ['won', 'lost', 'cleared'], true) : !in_array($state, ['refunded', 'failed'], true)) { $receipts[$id]['hold'] = true; }
        }
        $F = 0;
        foreach ($negative as $id=>$row) { if (!isset($used[$id])) { $F -= PricingRule::minor($row['amount']); } }
        $deficit = $U + $F; $positive = 0; $reserved = 0; $hold = false;
        foreach ($receipts as &$r) {
            $r['net'] = $r['gross'] - $r['completed'] - $r['reserved'] - $r['credit'] - $r['external'] + $r['recovered'];
            $deficit += max(0, -$r['net']); $positive += max(0, $r['net']); $reserved += $r['reserved']; $hold = $hold || $r['hold'];
        }
        unset($r);
        $pool = $positive - $deficit; $overdrawn = $pool < 0;
        $held = ($overdrawn || $hold || $ownerHold) ? 0 : $pool;
        $settled = $pool === 0 && $reserved === 0 && !$pending && !$hold && !$overdrawn;
        $fingerprint = hash('sha256', json_encode([$rows, $peers, $entries, $ownerHold], JSON_THROW_ON_ERROR));
        return compact('receipts', 'U', 'F', 'pending', 'ownerHold', 'deficit', 'pool', 'overdrawn', 'held', 'settled', 'fingerprint');
    }
    public static function held(array $record): int { return $record['held']; }
    /** Deterministic receipt-id order pays any invoice deficit before allowing another receipt out. */
    public static function heldFor(array $record, int $receiptId): array
    {
        $left = self::held($record);
        foreach ($record['receipts'] as $id=>$r) {
            $minor = min(max(0, $r['net']), $left); $left -= $minor;
            if ($id === $receiptId) { return ['card'=>$r['cardOk'] ? $minor : 0, 'credit'=>$r['cardOk'] ? 0 : $minor]; }
        }
        return ['card'=>0, 'credit'=>0];
    }
    public static function settled(array $record): bool { return $record['settled']; }
}
