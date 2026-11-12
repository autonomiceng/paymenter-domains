<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** Serialize a freshly migrated, empty fixture for independent in-memory databases. */
function exportCoreDatabase(PDO $pdo, string $path, array $bootedModels = []): void
{
    $quote = static fn(string $name): string => '"'.str_replace('"', '""', $name).'"';
    $objects = $pdo->query("SELECT type,name,sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type='table' DESC,name")->fetchAll(PDO::FETCH_ASSOC);
    $sql = ['-- booted-models: '.json_encode($bootedModels, JSON_THROW_ON_ERROR), 'PRAGMA foreign_keys=OFF;', 'BEGIN;'];
    foreach ($objects as $object) {
        if ($object['type'] === 'table' && !str_starts_with($object['name'], 'sqlite_')) { $sql[] = $object['sql'].';'; }
    }
    foreach ($objects as $object) {
        if ($object['type'] !== 'table') { continue; }
        $table = $quote($object['name']);
        if ($object['name'] === 'sqlite_sequence') { $sql[] = 'DELETE FROM '.$table.';'; }
        foreach ($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values = array_map(static fn($value): string => $value === null ? 'NULL' : (is_int($value) || is_float($value) ? (string)$value : $pdo->quote($value)), array_values($row));
            $sql[] = 'INSERT INTO '.$table.' ('.implode(',', array_map($quote, array_keys($row))).') VALUES ('.implode(',', $values).');';
        }
    }
    foreach ($objects as $object) { if ($object['type'] !== 'table') { $sql[] = $object['sql'].';'; } }
    $sql[] = 'COMMIT;'; $sql[] = 'PRAGMA foreign_keys=ON;';
    expect(file_put_contents($path, implode("\n", $sql)) !== false, 'Write migrated fixture snapshot');
}
