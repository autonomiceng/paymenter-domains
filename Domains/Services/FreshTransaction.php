<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\DB;

/** A Domains-owned writer transaction at READ COMMITTED. Each plain read then sees every row committed before it,
 * and locking reads take no gap locks. Core inserts or settles a payment only while it holds that invoice's row lock
 * (ExtensionHelper::addPayment), so a plain read of an invoice's payments taken under that lock is current. */
final class FreshTransaction
{
    private static ?\WeakMap $open = null;

    /** Opens the outer transaction at READ COMMITTED; inside another transaction it joins it unchanged. */
    public static function run(\Closure $work): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0) { return $connection->transaction($work); }
        self::$open ??= new \WeakMap;
        // Applies to the next transaction only, so a later core transaction on this connection keeps its own level.
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) { $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
        self::$open[$connection] = true;
        try { return $connection->transaction($work); } finally { unset(self::$open[$connection]); }
    }

    /** True inside a transaction this class opened. */
    public static function active(): bool
    {
        $connection = DB::connection();
        return $connection->transactionLevel() > 0 && isset(self::$open[$connection]);
    }
}
