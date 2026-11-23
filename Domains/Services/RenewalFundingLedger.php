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
                $rows[] = ['invoice_item_id' => $op->invoice_item_id, 'retry_number' => $op->retry_number,
                    'source' => $source, 'source_id' => $id, 'amount_minor' => $amount];
            }
        }
        // Five bound columns per posting must fit the SQLite parameter limit.
        foreach (array_chunk($rows, 128) as $batch) {
            DB::table(self::TABLE)->insert($batch);
        }
    }

    public static function entries(Operation $op): array
    {
        $entries = ['item' => [], 'payment' => []];
        foreach (DB::table(self::TABLE)->where(['invoice_item_id' => $op->invoice_item_id, 'retry_number' => $op->retry_number])->orderBy('source_id')->get() as $row) {
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
