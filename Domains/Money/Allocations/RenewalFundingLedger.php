<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Allocations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** The native postings reserved by each paid renewal, in the same transaction as its attempt. */
final class RenewalFundingLedger
{
    private function __construct(private readonly Collection $byItem) {}

    public static function reserve(RenewalReservation $reservation): void
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Renewal reservations need the outer seal transaction.'); }
        $rows = array_map(static fn (RenewalAllocation $allocation) => [
            'invoice_item_id' => $reservation->invoiceItemId, 'retry_number' => $reservation->retryNumber,
            'source' => $allocation->source->value, 'source_id' => $allocation->postingId, 'amount_minor' => $allocation->amountMinor,
        ], $reservation->allocations);
        // Five bound columns per posting must fit the SQLite parameter limit.
        foreach (array_chunk($rows, 128) as $batch) {
            DB::table(RenewalAllocationSchema::TABLE)->insert($batch);
        }
    }

    /** Load reservations for one page of the read-only balance forecast. */
    public static function forInvoiceItems(array $ids): self
    {
        $rows = $ids ? DB::table(RenewalAllocationSchema::TABLE)->whereIntegerInRaw('invoice_item_id', $ids)->get() : collect();
        return new self($rows->groupBy('invoice_item_id'));
    }

    public static function reservation(Operation $op, ?self $ledger = null): ?RenewalReservation
    {
        if ($op->action !== 'renew' || $op->funding_basis !== 'paid_invoice' || !$op->invoice_id || !$op->invoice_item_id) { return null; }
        $rows = $ledger === null
            ? DB::table(RenewalAllocationSchema::TABLE)->where(['invoice_item_id' => $op->invoice_item_id, 'retry_number' => $op->retry_number])->get()
            : $ledger->byItem->get($op->invoice_item_id, collect())->where('retry_number', $op->retry_number);
        return $rows->isEmpty() ? null : RenewalReservation::forOperation($op, $rows->values()->map(static fn ($row) =>
            new RenewalAllocation(PostingSource::from($row->source), (int) $row->source_id, (int) $row->amount_minor))->all());
    }

    public static function funded(Operation $op, ?self $ledger = null): bool
    {
        return self::reservation($op, $ledger)?->covers(PricingRule::minor($op->amount)) ?? false;
    }
}
