<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

final class SchemaVerifier
{
    public static function verify(string $table, \Closure $definition): void
    {
        $reference = 'domains_verify_'.bin2hex(random_bytes(6));
        try {
            Schema::create($reference, $definition);
            if (self::columns($table) !== self::columns($reference)
                || self::indexes($table) !== self::indexes($reference)
                || Schema::getForeignKeys($table) !== []) {
                throw new \RuntimeException('Retained Domains schema mismatch: '.$table);
            }
        } finally { Schema::dropIfExists($reference); }
    }

    private static function columns(string $table): array
    {
        $columns = Schema::getColumns($table);
        foreach ($columns as &$column) { unset($column['comment']); }
        unset($column);
        return $columns;
    }

    private static function indexes(string $table): array
    {
        $connection = DB::connection();
        // Laravel's portable metadata omits predicates/prefixes that can weaken uniqueness.
        if ($connection->getDriverName() === 'sqlite') {
            foreach ($connection->select('PRAGMA index_list("'.$table.'")') as $index) {
                if ((int) $index->partial !== 0) { throw new \RuntimeException('Retained Domains schema mismatch: partial index'); }
            }
        } elseif (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $metadata = $connection->selectOne('SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                [$connection->getDatabaseName(), $connection->getTablePrefix().$table]);
            if (!$metadata || strcasecmp((string) $metadata->engine, 'InnoDB') !== 0) {
                throw new \RuntimeException('Retained Domains schema requires InnoDB.');
            }
            foreach ($connection->select('SHOW INDEX FROM `'.$table.'`') as $index) {
                if ($index->Sub_part !== null) { throw new \RuntimeException('Retained Domains schema mismatch: prefix index'); }
            }
        } else { throw new \RuntimeException('Domains schema verification requires SQLite or MySQL/MariaDB.'); }
        $indexes = Schema::getIndexes($table);
        foreach ($indexes as &$index) { unset($index['name']); }
        unset($index);
        usort($indexes, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
        return $indexes;
    }
}
