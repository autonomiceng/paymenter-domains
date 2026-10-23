<?php
// Native fresh + retained lifecycle on a disposable MariaDB, no registrar calls.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/mariadb-bootstrap.php';
use Illuminate\Support\Facades\{Artisan,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema,Installation,SchemaVerifier};

same(getenv('DB_SOCKET'), DB::connection()->getConfig('unix_socket'), 'Resolved socket belongs to this disposable fixture');
same(getenv('DB_DATABASE'), DB::connection()->getDatabaseName(), 'Resolved database belongs to this disposable fixture');
$database = DB::connection()->getDatabaseName();
$references = $verified = [];
DB::listen(static function ($query) use (&$references, &$verified): void {
    if (!preg_match('/^create table `([^`]+)`/i', $query->sql, $match)) { return; }
    foreach (debug_backtrace() as $frame) {
        if (($frame['class'] ?? '') === SchemaVerifier::class && $frame['function'] === 'verify') {
            $references[$match[1]] = true; $verified[$frame['args'][0]] = true;
        }
    }
});
Artisan::call('migrate', ['--force' => true]);
// Upstream's invoice-number migration uses literal unprefixed SQL. Build native
// tables normally, then prepare their prefixed fixture names before Domains runs.
$requestedPrefix = (string) getenv('SCHEMA_PREFIX');
if ($requestedPrefix !== '') {
    foreach (Schema::getTableListing($database, false) as $name) { Schema::rename($name, $requestedPrefix.$name); }
    config(['database.connections.mysql.prefix' => $requestedPrefix]);
    DB::purge('mysql'); Schema::clearResolvedInstance('db.schema');
}
$native = Schema::getTableListing($database, false); sort($native);
expect(!array_filter($native, static fn ($name) => str_contains($name, 'domains_')), 'Fresh database has no Domains tables');
$entry = new Domains([]);
$entry->installed();
$tables = Schema::getTableListing($database, false); sort($tables);
$owned = array_values(array_diff($tables, $native));
expect(count($owned) > 30, 'Fresh native install created the complete Domains schema');
$prefix = DB::connection()->getTablePrefix();
foreach ($owned as $table) {
    expect(str_starts_with($table, $prefix.'domains_'), 'Fresh install left no reference table: '.$table);
    expect(isset($verified[substr($table, strlen($prefix))]), 'Fresh install verified every retained definition: '.$table);
}
DB::table('domains_psl_status')->where('id', 1)->update(['consecutive_failures' => 3]);
$snapshot = static function () use ($owned, $prefix): array {
    $result = [];
    foreach ($owned as $physical) {
        $table = substr($physical, strlen($prefix));
        $result[$physical] = [Schema::getColumns($table), Schema::getIndexes($table), DB::table($table)->get()->toJson()];
    }
    return $result;
};
$before = $snapshot();
$entry->uninstalled(); $entry->installed(); $entry->upgraded('0.0.1');
(new Installation)->verify();
same($before, $snapshot(), 'Reinstall/upgrade retains every table, index, column and row');
$after = Schema::getTableListing($database, false); sort($after);
same($tables, $after, 'No leftover reference tables after fresh/reinstall/upgrade');
foreach (array_keys($references) as $reference) {
    expect(!in_array($reference, $after, true), 'Every observed reference was removed');
}
// Exercise finally cleanup on a real metadata mismatch as well as successful verification.
Schema::table('domains_adoptions', static fn ($t) => $t->string('unexpected')->nullable());
try { AdoptionSchema::verify(); throw new LogicException('Schema drift accepted'); }
catch (RuntimeException $error) { same('Retained Domains schema mismatch: domains_adoptions', $error->getMessage(), 'Specific drift refusal'); }
$after = Schema::getTableListing($database, false); sort($after); same($tables, $after, 'Failed verification also removes reference tables');
$version = DB::selectOne('SELECT VERSION() AS version')->version;
expect(str_starts_with($version, '11.4.5-MariaDB'), 'Pinned real MariaDB version');
echo json_encode(['complete' => true, 'server_version' => $version, 'prefix' => $prefix,
    'retained_tables' => count($owned), 'verified_tables' => count($verified), 'reference_creates' => count($references),
    'fresh' => true, 'reinstall' => true, 'upgrade' => true, 'cleanup_success_and_failure' => true])."\n";
$nativeSuccessful = true;
