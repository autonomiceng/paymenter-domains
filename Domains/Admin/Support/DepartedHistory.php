<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\HoldingDeparture;
/** A confirmed departure's History rows, read from its saved record and the confirmation's history entry only (mock 07b,
 * 07c): Departure confirmed, each closed invoice, each Credit added with its saved amount, Name released, Monitoring and
 * Email sent. */
final class DepartedHistory
{
    public const CANCELLED = 'Domain transferred away.';
    private const WATCH = ['active' => 'Monitored as an external domain.', 'paused' => 'Monitored domain stays paused.', 'retired' => 'Not monitored. Its sources are off.'];
    /** The departure email once its delivery was accepted, from the confirmation's history entry and its delivery row.
     * @return array{subject:string,to:string,at:int}|null */
    public static function email(Domain $d, array $entry): ?array
    {
        $fact = $entry['email'] ?? null;
        if (!is_array($fact) || !is_string($fact['delivery_key'] ?? null) || !is_string($fact['subject'] ?? null) || !is_string($fact['to'] ?? null)) { return null; }
        $row = DB::table('domains_reminder_deliveries')->where('delivery_key', $fact['delivery_key'])->where('domain_id', $d->id)->first();
        return $row && $row->state === 'accepted' && $row->accepted_at !== null ? ['subject' => $fact['subject'], 'to' => $fact['to'], 'at' => (int) $row->accepted_at] : null;
    }
    /** Requests a confirmed departure closed, by id. Its own invoice and credit rows say what happened to each, so History
     * does not add a separate ended row for them; the request keeps its own page and history.
     * @return list<int> */
    public static function closed(Domain $d): array
    {
        if ($d->status !== 'departed') { return []; }
        try { $record = $d->renewal_policy['departure'] ?? null; } catch (\Throwable) { return []; }
        $ids = [];
        foreach (is_array($record['invoices'] ?? null) ? $record['invoices'] : [] as $o) {
            if (is_array($o) && is_int($o['operation_id'] ?? null) && in_array($o['outcome'] ?? null, ['cancelled_unpaid', 'credited'], true)) { $ids[] = $o['operation_id']; }
        }
        return $ids;
    }
    /** The confirmation's staff_holdings entry. */
    public static function entry(Domain $d): array
    {
        foreach (array_reverse(is_array($d->renewal_policy['staff_holdings'] ?? null) ? $d->renewal_policy['staff_holdings'] : []) as $e) {
            if (is_array($e) && ($e['action'] ?? null) === HoldingDeparture::ACTION) { return $e; }
        }
        return [];
    }
    /** @param array $entry the confirmation's staff_holdings entry @return list<array> newest first by __order */
    public static function rows(Domain $d, array $entry, ?\DateTimeImmutable $at, string $by): array
    {
        try { $record = $d->renewal_policy['departure'] ?? null; } catch (\Throwable) { $record = null; }
        if (!is_array($record)) { return []; }
        $done = ['label' => 'Completed', 'tone' => 'success'];
        $reason = HoldingDeparture::REASONS[$record['reason'] ?? ''] ?? null;
        $note = is_string($record['note'] ?? null) && $record['note'] !== '' ? 'Note: '.StaffDomainStatus::sentence($record['note']) : null;
        $rows = [['event' => 'Departure confirmed', 'sub' => trim(($reason ?? '').' '.($note ?? '')) ?: null, 'status' => $done, 'by' => $by]];
        $credits = [];
        foreach (is_array($record['invoices'] ?? null) ? $record['invoices'] : [] as $outcome) {
            if (!is_array($outcome) || !is_int($outcome['invoice_id'] ?? null) || !in_array($outcome['outcome'] ?? null, ['cancelled_unpaid', 'credited'], true)) { continue; }
            $name = DepartureConfirm::invoice($outcome['invoice_id']);
            if ($outcome['outcome'] === 'credited') {
                $rows[] = ['event' => $name.' cancelled', 'sub' => self::CANCELLED.' Paid renewal, never sent to the registrar.', 'status' => ['label' => 'Cancelled', 'tone' => 'gray'], 'by' => 'System'];
                $credits[] = ['event' => 'Credit added', 'sub' => DepartureConfirm::money((int) ($outcome['credit_minor'] ?? 0)).' to account credit, from '.$name.'.', 'status' => $done, 'by' => 'System'];
            } else {
                $total = (string) \App\Models\Invoice::find($outcome['invoice_id'])?->formattedTotal;
                $rows[] = ['event' => $name.' cancelled', 'sub' => self::CANCELLED.' Unpaid renewal'.($total !== '' ? ', '.$total : '').'.', 'status' => ['label' => 'Cancelled', 'tone' => 'gray'], 'by' => 'System'];
            }
        }
        $rows = [...$rows, ...$credits, ['event' => 'Name released', 'sub' => 'The name is available to order.', 'status' => $done, 'by' => 'System']];
        if (isset(self::WATCH[$entry['watch']['state'] ?? ''])) { $rows[] = ['event' => 'Monitoring', 'sub' => self::WATCH[$entry['watch']['state']], 'status' => $done, 'by' => 'System']; }
        if ($email = self::email($d, $entry)) { $rows[] = ['time' => (new \DateTimeImmutable())->setTimestamp($email['at']), 'event' => 'Email sent', 'sub' => '“'.$email['subject'].'” to '.$email['to'].'.', 'status' => $done, 'by' => 'System']; }
        // Above any registrar read saved in the same second, in this order.
        $count = count($rows);
        return array_map(fn(array $row, int $i) => ['__key' => 'departed-'.$i, '__order' => 1000 + $count - $i, 'time' => $at, ...$row], $rows, array_keys($rows));
    }
}
