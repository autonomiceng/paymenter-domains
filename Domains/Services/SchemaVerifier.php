<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

final class SchemaVerifier
{
    public static function verify(string $table, \Closure $definition): void
    {
        // No longer than the retained logical name, so the connection prefix and
        // implicit (or table-derived explicit) index names cannot grow either.
        $reference = substr(bin2hex(random_bytes(16)), 0, strlen($table));
        $connection = Schema::getConnection();
        $blueprint = new Blueprint($connection, $reference);
        $blueprint->create();
        $definition($blueprint);
        $created = false;
        try {
            // Like Schema::create, CREATE precedes the separate index statements.
            // Only a successful CREATE grants cleanup ownership; a concurrent
            // name collision must never drop the other invocation's reference.
            foreach ($blueprint->toSql() as $statement) {
                $connection->statement($statement);
                $created = true;
            }
            if (self::columns($table) === self::columns($reference) && Schema::getForeignKeys($table) === []
                && str_starts_with($table, 'domains_') && FreshInstallation::recovering()) {
                self::resumeIndexes($table, $reference);
            }
            if (self::columns($table) !== self::columns($reference)
                || self::indexes($table) !== self::indexes($reference)
                || Schema::getForeignKeys($table) !== []) {
                throw new \RuntimeException('Retained Domains schema mismatch: '.$table);
            }
        } finally { if ($created) { Schema::dropIfExists($reference); } }
    }

    /** CREATE may commit before its indexes. Only an owned, still-empty new install may add missing indexes. */
    private static function resumeIndexes(string $table, string $reference): void
    {
        $actual = self::indexes($table); $expected = self::indexes($reference);
        foreach ($actual as $index) { if (!in_array($index, $expected, true)) { return; } }
        foreach (Schema::getIndexes($reference) as $index) {
            $shape = $index; unset($shape['name']);
            if (in_array($shape, $actual, true)) { continue; }
            if ($index['primary']) { return; } // A malformed CREATE requires restoration, never a table rebuild.
            Schema::table($table, static function (Blueprint $blueprint) use ($index, $table, $reference): void {
                $method = $index['unique'] ? 'unique' : 'index';
                $blueprint->$method($index['columns'], str_replace($reference, $table, $index['name']));
            });
        }
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
        $physical = $connection->getTablePrefix().$table;
        // Laravel's portable metadata omits predicates/prefixes that can weaken uniqueness.
        if ($connection->getDriverName() === 'sqlite') {
            foreach ($connection->select('PRAGMA index_list("'.$physical.'")') as $index) {
                if ((int) $index->partial !== 0) { throw new \RuntimeException('Retained Domains schema mismatch: partial index'); }
            }
        } elseif (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $metadata = $connection->selectOne('SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                [$connection->getDatabaseName(), $physical]);
            if (!$metadata || strcasecmp((string) $metadata->engine, 'InnoDB') !== 0) {
                throw new \RuntimeException('Retained Domains schema requires InnoDB.');
            }
            foreach ($connection->select('SHOW INDEX FROM `'.$physical.'`') as $index) {
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
