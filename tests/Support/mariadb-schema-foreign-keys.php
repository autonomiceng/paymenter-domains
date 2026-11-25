<?php
// Foreign key verification on a disposable MariaDB with test-local tables, no registrar calls.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/mariadb-bootstrap.php';
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Installation\SchemaVerifier;

same(getenv('DB_SOCKET'), DB::connection()->getConfig('unix_socket'), 'Resolved socket belongs to this disposable fixture');
$database = DB::connection()->getDatabaseName();
same(getenv('DB_DATABASE'), $database, 'Resolved database belongs to this disposable fixture');
// With case-sensitive identifiers a differently cased schema or table is another object.
same(0, (int) DB::selectOne('SELECT @@lower_case_table_names AS value')->value, 'Case-sensitive identifiers');
config(['database.connections.mysql.prefix' => (string) getenv('SCHEMA_PREFIX')]);
DB::purge('mysql'); Schema::clearResolvedInstance('db.schema');
$prefix = DB::connection()->getTablePrefix();

$parentTable = 'domains_fk_parent'; $childTable = 'domains_fk_child';
// Constraint names are unique per database, so the name follows the table and its reference copy gets its own.
$child = static function (Blueprint $t) use ($parentTable): void {
    $t->id(); $t->unsignedBigInteger('parent_id');
    $t->foreign('parent_id', $t->getPrefix().$t->getTable().'_parent_f')->references('id')->on($parentTable)
        ->restrictOnDelete()->restrictOnUpdate();
};
Schema::create($parentTable, static fn (Blueprint $t) => $t->id());
Schema::create($childTable, $child);
$parent = $prefix.$parentTable; $physical = $prefix.$childTable;
$original = Schema::getForeignKeys($childTable);
same(1, count($original), 'One named reference');
$name = $original[0]['name'];
same([$physical.'_parent_f', $database, $parent, 'restrict', 'restrict'],
    [$name, $original[0]['foreign_schema'], $original[0]['foreign_table'], $original[0]['on_update'], $original[0]['on_delete']], 'Restrictive reference to this database');
DB::table($parentTable)->insert(['id' => 1]); DB::table($childTable)->insert(['id' => 1, 'parent_id' => 1]);
try { DB::table($parentTable)->delete(); throw new LogicException('Referenced parent deleted'); }
catch (Illuminate\Database\QueryException) { same(1, DB::table($parentTable)->count(), 'Restrictive reference keeps the parent'); }
DB::table($childTable)->delete(); DB::table($parentTable)->delete();

// Healthy reference on the fresh path and on a repeated, cached comparison.
SchemaVerifier::verify($childTable, $child);
SchemaVerifier::batch(static function () use ($childTable, $child): void {
    SchemaVerifier::verify($childTable, $child); SchemaVerifier::verify($childTable, $child);
});
$healthy = SchemaVerifier::fingerprint();

$target = static function (string $schema, string $table): void {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.$schema.'`');
    DB::statement('CREATE TABLE `'.$schema.'`.`'.$table.'` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
};
$point = static function (string $schema, string $table, string $action) use ($physical, $name): void {
    DB::statement('ALTER TABLE `'.$physical.'` DROP FOREIGN KEY `'.$name.'`');
    DB::statement('ALTER TABLE `'.$physical.'` ADD CONSTRAINT `'.$name.'` FOREIGN KEY (`parent_id`) REFERENCES `'
        .$schema.'`.`'.$table.'` (`id`) ON DELETE '.$action.' ON UPDATE RESTRICT');
};
$refuses = static function (string $case) use ($childTable, $child): void {
    try { SchemaVerifier::verify($childTable, $child); throw new LogicException($case.' accepted'); }
    catch (RuntimeException $error) { same('Retained Domains schema mismatch: '.$childTable, $error->getMessage(), $case.' refused'); }
};
// The owned fresh-install recovery step, as SchemaVerifier::verify runs it for the installer owner: build the reference,
// add what is missing, drop the reference. Test-local tables have no owner, so the step is called directly.
$recover = static function () use ($childTable, $child): void {
    $reference = substr(bin2hex(random_bytes(16)), 0, strlen($childTable));
    Schema::create($reference, $child);
    try { (new ReflectionMethod(SchemaVerifier::class, 'resumeIndexes'))->invoke(null, $childTable, $reference); }
    finally { Schema::dropIfExists($reference); }
};

$cases = [
    'foreign schema' => [$database.'_restored', $parent, 'RESTRICT'],
    'case-distinct schema' => [strtoupper($database), $parent, 'RESTRICT'],
    'case-distinct table' => [$database, strtoupper($parent), 'RESTRICT'],
    'changed action' => [$database, $parent, 'CASCADE'],
];
$proved = [];
foreach ($cases as $case => [$schema, $table, $action]) {
    if ([$schema, $table] !== [$database, $parent]) { $target($schema, $table); }
    // Cached path: the batch reuses its expected shapes and must still read the live reference.
    SchemaVerifier::batch(static function () use ($point, $refuses, $childTable, $child, $schema, $table, $action, $case): void {
        SchemaVerifier::verify($childTable, $child);
        $point($schema, $table, $action);
        $refuses($case.' on the cached path');
    });
    $live = Schema::getForeignKeys($childTable);
    same([$schema, $table, strtolower($action)], [$live[0]['foreign_schema'], $live[0]['foreign_table'], $live[0]['on_delete']], $case.' is the live reference');
    $refuses($case.' on the fresh path');
    expect(SchemaVerifier::fingerprint() !== $healthy, $case.' changes the fingerprint');
    // Recovery adds only what is missing and never replaces a key it did not create.
    $recover();
    same($live, Schema::getForeignKeys($childTable), $case.': recovery leaves the wrong key');
    $refuses($case.' after recovery');
    DB::statement('ALTER TABLE `'.$physical.'` DROP FOREIGN KEY `'.$name.'`');
    $refuses($case.' once removed');
    $recover();
    same($original, Schema::getForeignKeys($childTable), $case.': recovery restores the missing key');
    SchemaVerifier::verify($childTable, $child);
    // Drop the target first: a case-distinct name can match the fingerprint's table filter.
    if ([$schema, $table] !== [$database, $parent]) {
        DB::statement('DROP TABLE `'.$schema.'`.`'.$table.'`');
        if ($schema !== $database) { DB::statement('DROP DATABASE `'.$schema.'`'); }
    }
    same($healthy, SchemaVerifier::fingerprint(), $case.': restored fingerprint');
    $proved[] = $case;
}
Schema::drop($childTable); Schema::drop($parentTable);
$version = DB::selectOne('SELECT VERSION() AS version')->version;
expect(str_starts_with($version, '11.4.5-MariaDB'), 'Pinned real MariaDB version');
echo json_encode(['complete' => true, 'server_version' => $version, 'prefix' => $prefix, 'refused_and_recovered' => $proved])."\n";
$nativeSuccessful = true;
