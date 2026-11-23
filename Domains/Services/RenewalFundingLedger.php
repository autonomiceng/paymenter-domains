<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;

/** The signed native postings reserved by a paid renewal, in the same transaction as its attempt. */
final class RenewalFundingLedger
{
    public const TABLE = 'domains_renewal_funding';

    public static function reserve(Operation $op, array $entries): void
    {
        $rows = [];
        foreach ($entries as $source => $postings) {
            foreach ($postings as $id => $amount) {
                $rows[] = ['operation_id' => $op->id, 'source' => $source, 'source_id' => $id, 'amount_minor' => $amount];
            }
        }
        // Bound each statement even for an invoice settled by many small payments.
        foreach (array_chunk($rows, 250) as $batch) {
            DB::table(self::TABLE)->insert($batch);
        }
    }

    public static function entries(Operation $op): array
    {
        $entries = ['item' => [], 'payment' => []];
        foreach (DB::table(self::TABLE)->where('operation_id', $op->id)->orderBy('source_id')->get() as $row) {
            $entries[$row->source][(int) $row->source_id] = (int) $row->amount_minor;
        }
        return $entries;
    }

    public static function funded(Operation $op): bool
    {
        $entries = self::entries($op);
        $total = array_sum($entries['item']);
        return ($entries['item'][$op->invoice_item_id] ?? null) === PricingRule::minor($op->amount)
            && $total > 0 && array_sum($entries['payment']) >= $total;
    }
}
