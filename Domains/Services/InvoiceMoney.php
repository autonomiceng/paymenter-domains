<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Invoice;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Money\Returns\{DisputeRecoveries, ReceiptReturns};

/** Signed invoice amounts, derived from retained evidence. Locked loads recover newly observed lost credit; display reads use project().
 * settled is invoice-wide: a kept peer can retain money after an operation's return finishes.
 * Operation returns keep their own completion checks; returnBlocked only vetoes completion on unsafe invoice evidence. */
final class InvoiceMoney
{
    /** HoldingLocks must already own the user, credit, invoice and native transaction rows. */
    public static function load(array $l, int $invoiceId, ?string $exclude = null): array
    {
        if (DB::transactionLevel() < 1 || ($l['advisory'] ?? true)) { throw new \LogicException('Money reads require holding locks.'); }
        InvoiceScope::cover($l, $invoiceId);
        $invoice = $l['invoices']->firstWhere('id', $invoiceId);
        if (!$invoice || (int)$invoice->user_id !== (int)$l['user']->id) { throw new \DomainException('Invoice owner changed.'); }
        $peers = Operation::where('invoice_id', $invoiceId)->orderBy('id')->lockForUpdate()->get();
        $record = self::lockedRecord($l, $invoiceId, $peers, $exclude);
        foreach ($record['ledger'] as $entry) {
            $id = DisputeRecoveries::dispute($entry);
            if ($id === null) { continue; }
            foreach ($record['receipts'] as $pid => $r) {
                if ($r['receipt'] !== $entry['payment_intent_id'] || $r['gateway_id'] !== $entry['gateway_id']) { continue; }
                // A later completed return can raise what a dispute owes after its first recovery was recorded.
                [$owed, $recorded] = DisputeRecoveries::obligation($peers, $id, (int)$pid, $r, (int)$entry['amount_minor']);
                if ($owed > $recorded) {
                    DisputeRecoveries::locked($l,$invoiceId,$entry,app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now(),$record);
                    $peers=Operation::where('invoice_id',$invoiceId)->orderBy('id')->lockForUpdate()->get();
                    // Callers save funding from their locked rows, so those rows must keep the recovery just written.
                    foreach($l['operations']??[] as $held){
                        $fresh=$peers->firstWhere('id',$held->id);
                        if($fresh){$held->funding=$fresh->funding;$held->syncOriginalAttribute('funding');}
                    }
                    $record=self::lockedRecord($l,$invoiceId,$peers,$exclude);
                }
            }
        }
        return $record;
    }
    public static function ownerHeld(int $userId, bool $lock = false): bool
    {
        return DB::table('domains_obligation_alerts')->join('domains_domains','domains_domains.id','=','domains_obligation_alerts.domain_id')
            ->where('domains_domains.user_id',$userId)->where('code','account_money_hold')->whereNull('resolved_at')
            ->orderBy('domains_obligation_alerts.id')->when($lock,fn($q)=>$q->lockForUpdate())->get(['domains_obligation_alerts.id'])->isNotEmpty();
    }
    public static function lockedRecord(array $l,int $invoiceId,iterable $peers,?string $exclude=null): array
    {
        if(DB::transactionLevel()<1||($l['advisory']??true)){throw new \LogicException('Money reads require holding locks.');}
        InvoiceScope::cover($l,$invoiceId);
        return self::readRecord($l['invoices']->firstWhere('id',$invoiceId),$l['transactions']->where('invoice_id',$invoiceId),$peers,self::ownerHeld((int)$l['user']->id,true),$exclude);
    }
    /** Display and attention only. No recovery or payout authority. Gateway reads use their read transaction contract. */
    public static function project(int|Invoice $invoiceId,?iterable $rows=null,?iterable $peers=null,?bool $ownerHold=null): array
    {
        $invoice=$invoiceId instanceof Invoice?$invoiceId:Invoice::findOrFail($invoiceId);
        $rows??=\App\Models\InvoiceTransaction::with('gateway')->where('invoice_id',$invoice->id)->orderBy('id')->get();
        $peers??=self::peers([(int)$invoice->id]);
        return self::readRecord($invoice,$rows,$peers,$ownerHold??self::ownerHeld((int)$invoice->user_id));
    }
    /** Bounded peer reads shared by display batches and advisory replacement proofs. */
    public static function peers(array $invoiceIds): \Illuminate\Support\Collection
    {
        $peers=collect();
        Operation::whereIn('invoice_id',$invoiceIds)->chunkById(100,function($rows)use(&$peers):void{$peers=$peers->concat($rows);});
        return $peers;
    }
    /** Reuse one invoice/payment/peer batch. Unreadable invoices stay unknown without hiding the other invoices. */
    public static function projectBatch(iterable $invoices,iterable $rows,iterable $peers): array
    {
        $invoices=collect($invoices);if($invoices->isEmpty()){return [];}
        $rows=collect($rows)->groupBy('invoice_id');$peers=collect($peers)->groupBy('invoice_id');
        $held=DB::table('domains_obligation_alerts')->join('domains_domains','domains_domains.id','=','domains_obligation_alerts.domain_id')
            ->whereIn('domains_domains.user_id',$invoices->pluck('user_id')->unique())->where('code','account_money_hold')->whereNull('resolved_at')
            ->distinct()->pluck('domains_domains.user_id')->all();
        $out=[];
        foreach($invoices as $invoice){
            try{$out[$invoice->id]=self::project($invoice,$rows->get($invoice->id,collect()),$peers->get($invoice->id,collect()),in_array((int)$invoice->user_id,$held));}
            catch(\Throwable){$out[$invoice->id]=null;}
        }
        return $out;
    }
    private static function readRecord($invoice,iterable $transactions,iterable $peers,bool $ownerHold,?string $exclude=null): array
    {
        if(!$invoice){throw new \DomainException('Invoice missing.');}
        $invoiceId=(int)$invoice->id;
        $rows = []; $ledger = []; $gateways = [];
        foreach ($transactions as $row) {
            $gateway = $row->gateway;
            $readable = $gateway && ExtensionHelper::hasFunction($gateway, 'receiptLedger');
            $capable = $readable && ReceiptReturns::capable($gateway);
            $rows[] = ['id'=>(int)$row->id, 'amount'=>(string)$row->amount, 'status'=>$row->status->value,
                'gateway_id'=>(int)$row->gateway_id, 'transaction_id'=>$row->transaction_id, 'cardOk'=>$capable];
            if (!$readable || $row->status !== InvoiceTransactionStatus::Succeeded || PricingRule::minor($row->amount) <= 0 || isset($gateways[$gateway->id])) { continue; }
            $gateways[$gateway->id] = true;
            $read=static function()use($gateway,$invoice):array{
                $source=ExtensionHelper::call($gateway,'receiptLedger',[$invoice]);
                if(!is_iterable($source)){throw new \DomainException('Unreadable receipt ledger.');}
                $entries=[];foreach($source as $entry){$entries[]=(array)$entry;}return $entries;
            };
            $source = DB::transactionLevel()>0?$read():DB::transaction($read);
            foreach ($source as $entry) {
                $entry = (array)$entry;
                if ((int)($entry['invoice_id'] ?? 0) !== $invoiceId || (int)($entry['user_id'] ?? 0) !== (int)$invoice->user_id
                    || strtoupper($entry['currency'] ?? '') !== $invoice->currency_code) { throw new \DomainException('Receipt ledger identity changed.'); }
                $ledger[] = [...$entry, 'gateway_id'=>(int)$gateway->id];
            }
        }
        $funding = collect($peers)->map(function($op) use ($exclude): array {
            $f = $op->funding ?? [];
            if ($exclude !== null) { $f['receipt_return_results'][$exclude] = ['state'=>'released', 'credit_minor'=>0]; }
            return ['funding'=>$f];
        })->all();
        return [...self::record($rows, $funding, $ledger, $ownerHold), 'ledger'=>$ledger,
            'attentionFingerprint'=>hash('sha256',json_encode([$rows,$funding,$ledger],JSON_THROW_ON_ERROR))];
    }

    /** Plain native rows (cardOk supplied by load), peer funding arrays and raw gateway ledger rows. No queries or I/O. */
    public static function record(iterable $rows, iterable $peers, array $ledger, bool $ownerHold = false): array
    {
        $rows = [...$rows]; $peers = [...$peers];
        $receipts = []; $negative = []; $pending = false; $used = []; $allocations = []; $refunds = []; $U = 0;
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
            $observation = str_starts_with($entry['allocation_key'], 'stripe:');
            $states = $observation ? ['open','inquiry','warning','won','lost','cleared','refunded','failed'] : ['reserved','sending','pending','review','refunded','credited'];
            if (!in_array($entry['state'], $states, true) || $observation && !preg_match('/^stripe:(refund|dispute):.+$/D', $entry['allocation_key'])) { throw new \DomainException('Unreadable receipt state.'); }
            $key = $identity($entry['gateway_id'], $entry['payment_intent_id'], $entry['allocation_key']);
            if (isset($entries[$key]) && $entries[$key] !== $entry) { throw new \DomainException('Conflicting receipt ledger rows.'); }
            $entries[$key] = $entry;
        }
        foreach ($peers as $peer) {
            $funding = $peer['funding'] ?? []; $recoveries = [];
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
            // Each peer numbers its increments for one dispute from 0; entries written before numbering count as 0.
            foreach ($funding['dispute_holds'] ?? [] as $recovery) {
                $id = $recovery['payment_id']; $next = $recoveries[$recovery['dispute_id']] ?? 0;
                if (!isset($receipts[$id]) || !is_int($recovery['removed_minor']) || $recovery['removed_minor'] < 0
                    || ($recovery['increment'] ?? 0) !== $next) { throw new \DomainException('Credit recovery needs review.'); }
                $recoveries[$recovery['dispute_id']] = $next + 1;
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
                if (!empty($result['refund_id'])) { $refunds[$identity($receipts[$id]['gateway_id'], $receipts[$id]['receipt'], $result['refund_id'])] = $minor; }
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
            if ($parts[1] === 'refund' && isset($refunds[$key = $identity($entry['gateway_id'], $entry['payment_intent_id'], $parts[2])])) {
                if ($refunds[$key] !== (int)$entry['amount_minor']) { throw new \DomainException('Conflicting refund observation amount.'); }
                continue;
            }
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
    public static function returnBlocked(array $record): bool
    { return $record['overdrawn'] || (bool)array_filter($record['receipts'],fn($r)=>$r['hold']); }
}
