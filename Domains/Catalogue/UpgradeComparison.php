<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Services\OfferSchema;

/** Digest-only evidence: never exports customer data, encrypted settings or frozen spending inputs. */
final class UpgradeComparison
{
    public const TABLES = ['domains_accounts', 'domains_tlds', 'domains_prices', 'domains_sales_policies',
        'domains_sell_rows', 'domains_sell_fills', 'domains_observation_queues', 'domains_observation_work',
        'domains_domains', 'domains_operations', 'extensions', 'categories', 'products', 'plans', 'prices', 'settings',
        'services', 'orders', 'invoices', 'invoice_items', 'invoice_transactions', 'credits',
        'jobs', 'failed_jobs', 'users', 'carts', 'cart_items'];
    private static bool $locking = false;
    public static function locking(): bool { return self::$locking; }
    public static function locked(\Closure $body): mixed
    {
        if (DB::transactionLevel() !== 1 || self::$locking) { throw new \LogicException('Outer comparison locks required.'); }
        self::$locking = true;
        try { return $body(); } finally { self::$locking = false; }
    }
    public static function read(Builder $query): Builder { return self::$locking ? $query->lockForUpdate() : $query; }
    private const MONEY = [
        'domains_prices' => ['wholesale', 'retail'],
        'domains_tlds' => ['markup', 'fixed_register', 'fixed_renew', 'fixed_transfer', 'renew_price', 'cost_renew', 'cost_transfer', 'catalogue_retained_annual_retail'],
        'domains_sell_rows' => ['register_cost', 'renew_cost', 'transfer_cost', 'high_cost'],
    ];

    /** Cast in SQL, before PDO can turn fixed decimals into floating-point values (SQLite). */
    public static function query(string $table): Builder
    {
        $query = DB::table($table)->select('*');
        $grammar = DB::connection()->getQueryGrammar();
        foreach (self::MONEY[$table] ?? [] as $column) {
            if ($column === 'catalogue_retained_annual_retail' && !Schema::hasColumn($table, $column)) { continue; }
            $name = $grammar->wrap($column);
            $query->selectRaw('CAST('.$name.' AS CHAR) AS '.$name);
        }
        return self::read($query);
    }

    public static function retained(string $table): Builder
    {
        $query = self::query($table);
        $marker = $table === 'domains_observation_queues' ? 'offer_queue_created' : 'catalogue_created';
        if (isset(OfferSchema::COLUMNS[$table][$marker]) && Schema::hasColumn($table, $marker)) {
            $query->where($marker, false);
        }
        return self::read($query);
    }

    /** Stable bounded traversal; only counts/digests leave the database boundary. */
    public static function digest(string $table, ?Builder $query = null): array
    {
        $hash = hash_init('sha256'); $count = 0;
        $columns = Schema::getColumnListing($table);
        $order = in_array('id', $columns, true) ? ['id'] : ['server_id', 'account_fingerprint'];
        $query ??= self::retained($table);
        foreach ($order as $column) { $query->orderBy($column); }
        $query->chunk(250, static function ($rows) use ($hash, &$count, $table): void {
            foreach ($rows as $row) {
                $value = (array) $row;
                foreach (OfferSchema::COLUMNS[$table] ?? [] as $column => $_) { unset($value[$column]); }
                ksort($value);
                hash_update($hash, json_encode($value, JSON_THROW_ON_ERROR)."\n"); $count++;
            }
        });
        return ['count' => $count, 'sha256' => hash_final($hash)];
    }

    public static function capture(): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) { $snapshot[$table] = self::digest($table); }
        }
        return $snapshot;
    }

    public static function compare(array $before): array
    {
        $after = self::capture(); $changed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $table) {
            if (($before[$table] ?? null) !== ($after[$table] ?? null)) { $changed[] = $table; }
        }
        return ['compatible' => $changed === [], 'changed' => $changed, 'before' => $before, 'after' => $after];
    }
}
