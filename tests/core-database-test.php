<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/core-database.php';
$source = Illuminate\Support\Facades\DB::connection()->getPdo();
$path = tempnam((string)getenv('TMPDIR'), 'core-database-');
try {
    exportCoreDatabase($source, $path);
    $sql = file_get_contents($path);
    $first = new PDO('sqlite::memory:'); $second = new PDO('sqlite::memory:');
    foreach ([$first, $second] as $copy) {
        $copy->exec($sql);
        same($source->query('SELECT type,name,sql FROM sqlite_master ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
            $copy->query('SELECT type,name,sql FROM sqlite_master ORDER BY name')->fetchAll(PDO::FETCH_ASSOC), 'Exact schema, indexes and triggers survive snapshot restoration');
        same($source->query('SELECT * FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $copy->query('SELECT * FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Migration records survive snapshot restoration');
        same(1, (int)$copy->query('PRAGMA foreign_keys')->fetchColumn(), 'Restored database enforces foreign keys');
    }
    $first->exec("INSERT INTO users (email,password,first_name,last_name) VALUES ('snapshot@example.invalid','synthetic','Test','User')");
    same(1, (int)$first->query('SELECT count(*) FROM users')->fetchColumn(), 'First copy accepts native writes');
    same(0, (int)$second->query('SELECT count(*) FROM users')->fetchColumn(), 'Second copy owns independent rows');
    $first->beginTransaction();
    $first->exec("UPDATE users SET first_name='Changed'"); $first->rollBack();
    same('Test', $first->query('SELECT first_name FROM users')->fetchColumn(), 'Native transactions retain rollback semantics');
} finally { unlink($path); }
echo "Core database snapshot preserves schema, history, constraints and isolation.\n";
$nativeSuccessful = true;
