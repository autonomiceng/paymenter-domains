<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Cache, DB};

/** Restore committed synthetic rows without wrapping provider calls in a test transaction. */
function nativeFixtureCheckpoint(): Closure
{
    same('sqlite', DB::connection()->getDriverName(), 'Checkpoint only supports the synthetic SQLite fixture');
    same(':memory:', config('database.connections.sqlite.database'), 'Checkpoint never restores a persistent database');
    same(0, DB::transactionLevel(), 'Checkpoint captures committed rows');
    same('array', config('cache.default'), 'Checkpoint only clears the isolated fixture cache');
    $pdo = DB::connection()->getPdo();
    $quote = static fn (string $name): string => '"'.str_replace('"', '""', $name).'"';
    static $sequence = 0;
    $prefix = 'fixture_checkpoint_'.++$sequence.'_';
    $tables = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND (name NOT LIKE 'sqlite_%' OR name = 'sqlite_sequence') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $table = $quote($name); $copy = $quote($prefix.$name);
        $pdo->exec('CREATE TEMP TABLE '.$copy.' AS SELECT * FROM main.'.$table);
        $tables[$table] = $copy;
    }
    $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
    return static function () use ($pdo, $tables, $foreignKeys): void {
        same($pdo, DB::connection()->getPdo(), 'Checkpoint stays on the original fixture connection');
        same(0, DB::transactionLevel(), 'Each scenario starts outside a transaction');
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            DB::transaction(static function () use ($pdo, $tables): void {
                foreach (array_keys($tables) as $table) { $pdo->exec('DELETE FROM main.'.$table); }
                foreach ($tables as $table => $copy) {
                    if ($table === '"sqlite_sequence"') { continue; }
                    $pdo->exec('INSERT INTO main.'.$table.' SELECT * FROM temp.'.$copy);
                }
                // Inserts can advance sequences; restore their original values last.
                $pdo->exec('DELETE FROM main.sqlite_sequence');
                $pdo->exec('INSERT INTO main.sqlite_sequence SELECT * FROM temp.'.$tables['"sqlite_sequence"']);
            });
        } finally { $pdo->exec('PRAGMA foreign_keys = '.$foreignKeys); }
        Cache::flush();
        Illuminate\Support\Once::flush();
    };
}
