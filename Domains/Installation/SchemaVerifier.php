<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Installation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

final class SchemaVerifier
{
    /** Expected shapes only, for one verification run. Actual tables are always inspected again. */
    private static ?array $references = null;
    public static function batch(\Closure $verify): void
    {
        $previous = self::$references; self::$references = [];
        try { $verify(); } finally { self::$references = $previous; }
    }

    /** Read current integrity metadata without building reference tables or reading customer rows. */
    public static function fingerprint(): string
    {
        $connection=DB::connection();$prefix=$connection->getTablePrefix().'domains_';
        if($connection->getDriverName()==='sqlite'){
            $rows=$connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master WHERE substr(tbl_name,1,?)=? ORDER BY type,name', [strlen($prefix),$prefix]);
        }else{
            // Exclude mutable statistics and AUTO_INCREMENT counters; retain full columns, keys and engines.
            $sources=[
                ['TABLES',"'table'","''",'JSON_ARRAY(ENGINE,TABLE_COLLATION,CREATE_OPTIONS)'],
                ['COLUMNS',"'column'",'COLUMN_NAME','JSON_ARRAY(ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION)'],
                ['STATISTICS',"'index'","CONCAT(INDEX_NAME,':',SEQ_IN_INDEX)",'JSON_ARRAY(NON_UNIQUE,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE,NULLABLE)'],
                ['TABLE_CONSTRAINTS',"'constraint'",'CONSTRAINT_NAME','JSON_ARRAY(CONSTRAINT_TYPE)'],
                ['KEY_COLUMN_USAGE',"'key'","CONCAT(CONSTRAINT_NAME,':',ORDINAL_POSITION)",'JSON_ARRAY(COLUMN_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME)'],
            ];
            $sql=[];$bindings=[];
            foreach($sources as [$source,$kind,$item,$shape]){
                $sql[]="SELECT $kind AS kind,TABLE_NAME AS tbl,$item AS item,$shape AS shape FROM information_schema.$source WHERE TABLE_SCHEMA=? AND LEFT(TABLE_NAME,?)=?";
                array_push($bindings,$connection->getDatabaseName(),strlen($prefix),$prefix);
            }
            // Referential actions live in their own view, keyed by constraint schema.
            $sql[]="SELECT 'reference' AS kind,TABLE_NAME AS tbl,CONSTRAINT_NAME AS item,JSON_ARRAY(REFERENCED_TABLE_NAME,UNIQUE_CONSTRAINT_NAME,MATCH_OPTION,UPDATE_RULE,DELETE_RULE) AS shape FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND LEFT(TABLE_NAME,?)=?";
            array_push($bindings,$connection->getDatabaseName(),strlen($prefix),$prefix);
            $rows=$connection->select(implode(' UNION ALL ',$sql).' ORDER BY kind,tbl,item',$bindings);
        }
        return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    }

    public static function verify(string $table, \Closure $definition): void
    {
        // No longer than the retained logical name, so the connection prefix and
        // implicit (or table-derived explicit) index names cannot grow either.
        $reference = substr(bin2hex(random_bytes(16)), 0, strlen($table));
        $connection = Schema::getConnection();
        $blueprint = new Blueprint($connection, $reference);
        $blueprint->create();
        $definition($blueprint);
        $statements = $blueprint->toSql();
        $key = hash('sha256', serialize([spl_object_id($connection), str_replace($reference, $table, $statements)]));
        // Fresh-install recovery may add missing indexes and still needs its physical reference.
        $reuse = self::$references !== null && !Installer::recovering();
        if ($reuse && isset(self::$references[$key])) {
            [$columns, $indexes, $references] = self::$references[$key];
            if (self::columns($table) !== $columns || self::indexes($table) !== $indexes || self::references($table) !== $references) {
                throw new \RuntimeException('Retained Domains schema mismatch: '.$table);
            }
            return;
        }
        $created = false;
        try {
            // Like Schema::create, CREATE precedes the separate index statements.
            // Only a successful CREATE grants cleanup ownership; a concurrent
            // name collision must never drop the other invocation's reference.
            foreach ($statements as $statement) {
                $connection->statement($statement);
                $created = true;
            }
            if (self::columns($table) === self::columns($reference) && str_starts_with($table, 'domains_') && Installer::recovering()) {
                self::resumeIndexes($table, $reference);
            }
            if (self::columns($table) !== self::columns($reference)
                || self::indexes($table) !== self::indexes($reference)
                || self::references($table) !== self::references($reference)) {
                throw new \RuntimeException('Retained Domains schema mismatch: '.$table);
            }
            if ($reuse) { self::$references[$key] = [self::columns($reference), self::indexes($reference), self::references($reference)]; }
        } finally { if ($created) { Schema::dropIfExists($reference); } }
    }

    /** CREATE may commit before its indexes and references. Only an owned, still-empty new install may add missing ones. */
    private static function resumeIndexes(string $table, string $reference): void
    {
        $actual = self::indexes($table); $expected = self::indexes($reference);
        foreach ($actual as $index) { if (!in_array($index, $expected, true)) { return; } }
        $actualReferences = self::references($table); $expectedReferences = self::references($reference);
        foreach ($actualReferences as $key) { if (!in_array($key, $expectedReferences, true)) { return; } }
        foreach (Schema::getIndexes($reference) as $index) {
            $shape = $index; unset($shape['name']);
            if (in_array($shape, $actual, true)) { continue; }
            if ($index['primary']) { return; } // A malformed CREATE requires restoration, never a table rebuild.
            Schema::table($table, static function (Blueprint $blueprint) use ($index, $table, $reference): void {
                $method = $index['unique'] ? 'unique' : 'index';
                $blueprint->$method($index['columns'], str_replace($reference, $table, $index['name']));
            });
        }
        $prefix = DB::connection()->getTablePrefix();
        foreach (Schema::getForeignKeys($reference) as $key) {
            if (in_array(self::reference($key), $actualReferences, true)) { continue; }
            Schema::table($table, static function (Blueprint $blueprint) use ($key, $table, $reference, $prefix): void {
                $blueprint->foreign($key['columns'], str_replace($reference, $table, $key['name']))
                    ->references($key['foreign_columns'])->on(substr($key['foreign_table'], strlen($prefix)))
                    ->onUpdate($key['on_update'])->onDelete($key['on_delete']);
            });
        }
    }

    /** References by columns, schema-qualified target and actions. Names differ between a table and its reference copy. */
    private static function references(string $table): array
    {
        $references = array_map(self::reference(...), Schema::getForeignKeys($table));
        sort($references);
        return $references;
    }

    private static function reference(array $key): string
    {
        // A restored database can point a key at a same-named table in another schema, so the schema is part of the target.
        // Identifiers keep the server's case: with case-sensitive names, a differently cased schema or table is another target.
        return json_encode([$key['columns'], (string) ($key['foreign_schema'] ?? ''), (string) $key['foreign_table'], $key['foreign_columns'],
            strtolower((string) $key['on_update']), strtolower((string) $key['on_delete'])], JSON_THROW_ON_ERROR);
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
