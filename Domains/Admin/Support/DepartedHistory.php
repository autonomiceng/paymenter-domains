<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\HoldingDeparture;
/** A confirmed departure's History rows, read from its saved record and the confirmation's history entry only (mock 07b,
 * 07c): Departure confirmed, each closed invoice, each Credit added with its saved amount, Name released and Monitoring. */
final class DepartedHistory
{
    public const CANCELLED = 'Domain transferred away.';
    private const WATCH = ['active' => 'Monitored as an external domain.', 'paused' => 'Monitored domain stays paused.', 'retired' => 'Not monitored. Its sources are off.'];
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
        $count = count($rows);
        return array_map(fn(array $row, int $i) => ['__key' => 'departed-'.$i, '__order' => $count - $i, 'time' => $at, ...$row], $rows, array_keys($rows));
    }
}
