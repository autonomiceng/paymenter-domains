<?php
// Offline MySQL grammar sweep over the real migration and verifier definitions.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\SQLiteBuilder;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\Installation;

final class IdentifierSweepBuilder extends SQLiteBuilder
{
    public array $creates = [];
    public array $sites = [];
    public array $failures = [];
    public int $maximum = 0;

    public function create($table, Closure $callback)
    {
        $reference = false;
        foreach (debug_backtrace() as $frame) {
            if (($frame['class'] ?? '') === Paymenter\Extensions\Servers\Domains\Services\SchemaVerifier::class) { $reference = true; }
        }
        if (!$reference) {
            $this->inspect($table, $callback, null);
            $this->creates['migration'][$table] = true;
        }
        parent::create($table, $callback);
    }

    public function getColumns($table)
    {
        foreach (debug_backtrace() as $frame) {
            if (($frame['class'] ?? '') === Paymenter\Extensions\Servers\Domains\Services\SchemaVerifier::class
                && $frame['function'] === 'verify' && $table !== $frame['args'][0]) {
                [$retained, $definition] = $frame['args'];
                $site = str_replace(dirname(__DIR__).'/', '', $frame['file']).':'.$frame['line'];
                $this->sites[$site][$retained] = true;
                $this->creates['reference'][$retained] = true;
                $this->inspect($table, $definition, $retained);
            }
        }
        return parent::getColumns($table);
    }

    public function table($table, Closure $callback)
    {
        $this->inspect($table, $callback, null, false);
        parent::table($table, $callback);
    }

    private function inspect(string $table, Closure $callback, ?string $retained, bool $create = true): void
    {
        // Compile with and without index prefixes, including the longest prefix
        // for which this retained definition's own identifiers still fit.
        foreach ([false, true] as $prefixIndexes) {
            $baseline = $this->identifiers($retained ?? $table, $callback, '', $prefixIndexes, $create);
            foreach ($baseline as $identifier) {
                expect(strlen($identifier) <= 64, 'Retained/migration identifier exceeds 64: '.$identifier);
            }
            $onePrefix = $this->identifiers($retained ?? $table, $callback, 'p', $prefixIndexes, $create);
            $room = 64;
            foreach ($baseline as $position => $identifier) {
                // Explicit names and prefix_indexes=false indexes do not grow.
                if ($onePrefix[$position] !== $identifier) { $room = min($room, 64 - strlen($identifier)); }
            }
            foreach (array_unique(['', 'p_', str_repeat('p', max(0, $room))]) as $prefix) {
                $original = $this->identifiers($retained ?? $table, $callback, $prefix, $prefixIndexes, $create);
                if (max(array_map('strlen', $original)) > 64) { continue; }
                foreach ($this->identifiers($table, $callback, $prefix, $prefixIndexes, $create) as $identifier) {
                    $this->maximum = max($this->maximum, strlen($identifier));
                    if (strlen($identifier) > 64) {
                        $this->failures[] = ($retained ?? $table).': '.strlen($identifier).' '.$identifier;
                    }
                }
            }
        }
    }

    private function identifiers(string $table, Closure $definition, string $prefix, bool $prefixIndexes, bool $create): array
    {
        $mysql = new class(static fn () => throw new LogicException('Offline grammar must not connect'), '', $prefix,
            ['version' => '8.0.32', 'engine' => 'InnoDB', 'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci', 'prefix_indexes' => $prefixIndexes]) extends MySqlConnection {
            public function getServerVersion(): string { return '8.0.32'; }
            public function isMaria() { return false; }
        };
        $mysql->useDefaultSchemaGrammar();
        $blueprint = new Blueprint($mysql, $table);
        if ($create) { $blueprint->create(); }
        $definition($blueprint);
        $sql = implode(";\n", $blueprint->toSql());
        // Every quoted identifier: tables, columns, indexes, unique/primary and FK
        // constraints, including explicit names and Laravel's implicit indexes.
        preg_match_all('/`((?:[^`]|``)+)`/', $sql, $matches);
        expect($matches[1] !== [], 'MySQL grammar produced identifiers');
        return array_map(static fn ($name) => str_replace('``', '`', $name), $matches[1]);
    }
}

$builder = new IdentifierSweepBuilder(DB::connection());
Schema::swap($builder);
app(Illuminate\Database\Migrations\Migrator::class)->run([dirname(__DIR__).'/Domains/database/migrations'], ['force' => true]);
(new Installation)->verify();
// Exercise both retained install() and verify() entry points (including reminder install).
foreach (Installation::SCHEMAS as $schema) { $schema::install(); }

$sites = [];
foreach (glob(dirname(__DIR__).'/Domains/Services/*.php') as $file) {
    foreach (file($file) as $line => $text) {
        $count = substr_count($text, 'SchemaVerifier::verify(');
        if ($count) { $sites[str_replace(dirname(__DIR__).'/', '', $file).':'.($line+1)] = $count; }
    }
}
foreach ($sites as $site => $calls) {
    expect(count($builder->sites[$site] ?? []) >= $calls, 'Every verifier call site exercised: '.$site);
}
foreach (array_keys($builder->creates['migration']) as $table) {
    expect(isset($builder->creates['reference'][$table]), 'Migration table verified: '.$table);
}
expect($builder->failures === [], "MySQL identifiers exceed 64 characters:\n".implode("\n", $builder->failures));
echo 'MySQL identifier sweep: '.array_sum($sites).' verifier calls, '.count($builder->creates['migration'])
    .' migration tables, '.count($builder->creates['reference']).' retained tables; maximum '.$builder->maximum." characters; no server.\n";
$nativeSuccessful = true;
