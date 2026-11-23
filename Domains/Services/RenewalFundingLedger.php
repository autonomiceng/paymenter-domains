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

    public static function reserve(Operation $op, array $payments): void
    {
        $rows = [];
        foreach ($payments as $id => $amount) {
            $rows[] = ['operation_id' => $op->id, 'payment_id' => $id, 'amount_minor' => $amount];
        }
        // Bound each statement even for an invoice settled by many small payments.
        foreach (array_chunk($rows, 250) as $batch) {
            DB::table(self::TABLE)->insert($batch);
        }
    }

    public static function payments(Operation $op): array
    {
        return DB::table(self::TABLE)->where('operation_id', $op->id)->orderBy('payment_id')
            ->pluck('amount_minor', 'payment_id')->map(static fn ($amount) => (int) $amount)->all();
    }
}
