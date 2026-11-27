<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Allocations;

use Paymenter\Extensions\Servers\Domains\Models\Operation;

/** Exact native postings frozen for one attempt against an immutable renewal debt. */
final readonly class RenewalReservation
{
    /** @var list<RenewalAllocation> */
    public array $allocations;

    /** @param list<RenewalAllocation> $allocations */
    private function __construct(public int $invoiceId, public int $invoiceItemId, public int $retryNumber, array $allocations)
    {
        usort($allocations, static fn (RenewalAllocation $a, RenewalAllocation $b) =>
            [$a->source->value, $a->postingId] <=> [$b->source->value, $b->postingId]);
        $this->allocations = $allocations;
    }

    /** @param list<RenewalAllocation> $allocations */
    public static function forOperation(Operation $op, array $allocations): self
    {
        if ($op->action !== 'renew' || $op->funding_basis !== 'paid_invoice' || !$op->invoice_id || !$op->invoice_item_id) {
            throw new \DomainException('A paid renewal reservation needs its native invoice and item.');
        }
        return new self((int) $op->invoice_id, (int) $op->invoice_item_id, $op->retry_number, $allocations);
    }

    /** @return array<int, int> Amount in minor units by native row key. */
    public function postings(PostingSource $source): array
    {
        $amounts = [];
        foreach ($this->allocations as $allocation) {
            if ($allocation->source === $source) { $amounts[$allocation->postingId] = $allocation->amountMinor; }
        }
        return $amounts;
    }

    public function invoiceTotalMinor(): int
    {
        return array_sum($this->postings(PostingSource::Item));
    }

    public function covers(int $itemAmountMinor): bool
    {
        $items = $this->postings(PostingSource::Item);
        $total = array_sum($items);
        return ($items[$this->invoiceItemId] ?? null) === $itemAmountMinor
            && $total > 0 && array_sum($this->postings(PostingSource::Payment)) >= $total;
    }
}
