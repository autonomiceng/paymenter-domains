<?php
// Native fresh + retained lifecycle on a disposable MariaDB, no registrar calls.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/mariadb-bootstrap.php';
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Artisan,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema,EvidenceDatesSchema,Installation,PslReviewSchema,SchemaVerifier,WatchSchema};

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
DB::statement('SET SESSION explicit_defaults_for_timestamp = OFF');
same(0, (int) DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS value')->value, 'Exercise implicit timestamp behavior');
Artisan::call('migrate', ['--force' => true]);
// Upstream's invoice-number migration uses literal unprefixed SQL. Build native
// tables normally, then prepare their prefixed fixture names before Domains runs.
$requestedPrefix = (string) getenv('SCHEMA_PREFIX');
if ($requestedPrefix !== '') {
    foreach (Schema::getTableListing($database, false) as $name) { Schema::rename($name, $requestedPrefix.$name); }
    config(['database.connections.mysql.prefix' => $requestedPrefix]);
    DB::purge('mysql'); Schema::clearResolvedInstance('db.schema');
    DB::statement('SET SESSION explicit_defaults_for_timestamp = OFF');
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
Schema::table('domains_adoptions', static fn ($t) => $t->dropColumn('unexpected'));
$guardServer=\App\Models\Server::create(['name'=>'Freshness guard','type'=>'server','extension'=>'Domains','enabled'=>true]);
Schema::create('domains_freshness_guard',static function($table):void {$table->id();$table->unsignedBigInteger('server_id');});
DB::table('domains_freshness_guard')->insert(['server_id'=>$guardServer->id]);
try{(new \ReflectionMethod(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,'freshSandbox'))
    ->invoke(app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class),(int)$guardServer->id);
    throw new LogicException('Prefixed retained Domains table passed the fresh-server guard');
}catch(DomainException $error){same('Sandbox enrollment requires a fresh server.',$error->getMessage(),'Prefixed retained table blocks sandbox enrollment');}
Schema::drop('domains_freshness_guard');$guardServer->newQuery()->whereKey($guardServer->id)->delete();
$evidenceDatesBootstrapped = true;
require dirname(__DIR__).'/evidence-dates-upgrade-test.php';
$nativeSuccessful = false;
// Upgrade the shipped suffix-review schema before watch storage was installed.
$migrator = app(Migrator::class);
$files = $migrator->getMigrationFiles([dirname(__DIR__, 2).'/Domains/database/migrations']);
$reviewMigration = '2026_10_19_000008_create_psl_reviews';
$watchMigration = '2026_10_19_000009_create_watch_storage';
$earlyFiles = array_filter($files, static fn ($name) => $name <= $reviewMigration, ARRAY_FILTER_USE_KEY);
foreach ([0, 1] as $defaults) {
    foreach (array_reverse($owned) as $physical) { Schema::drop(substr($physical, strlen($prefix))); }
    DB::table('migrations')->whereIn('migration', array_keys($files))->delete();
    DB::statement('SET SESSION explicit_defaults_for_timestamp = '.$defaults);
    $migrator->run(array_values($earlyFiles), ['force' => true]);
    Schema::drop('domains_watch_audit');
    Schema::create('domains_watch_audit', static function (Blueprint $t): void {
        (new ReflectionMethod(PslReviewSchema::class, 'audit'))->invoke(null, $t, true);
    });
    $audit = ['subject_type' => 'synthetic', 'subject_id' => 800, 'event' => 'review',
        'after_revision' => str_repeat('e', 64), 'data' => 'retained ciphertext', 'created_at' => '2026-10-18 01:02:03'];
    DB::table('domains_watch_audit')->insert($audit);
    $auditBefore = DB::table('domains_watch_audit')->get()->toJson();
    $history = DB::table('migrations')->orderBy('id')->get()->toJson();
    expect(DB::table('migrations')->where('migration', $reviewMigration)->exists(), 'Suffix review migration is recorded');
    foreach ([$watchMigration, EvidenceDatesSchema::MIGRATION] as $pending) {
        expect(!DB::table('migrations')->where('migration', $pending)->exists(), 'Later migration is not recorded: '.$pending);
    }
    foreach (WatchSchema::tables() as $table) { expect(!Schema::hasTable($table), 'Watch storage is not installed: '.$table); }
    $columns = array_column(Schema::getColumns('domains_watch_audit'), null, 'name');
    same('timestamp', $columns['created_at']['type_name'], 'Early audit has the real legacy MariaDB type');
    DB::statement('SET SESSION explicit_defaults_for_timestamp = '.(1 - $defaults));
    $entry->upgraded('0.0.1');
    (new Installation)->verify();
    same($history, DB::table('migrations')->whereNotIn('migration', array_diff(array_keys($files), array_keys($earlyFiles)))->orderBy('id')->get()->toJson(), 'Early upgrade preserves prior migration records');
    same($auditBefore, DB::table('domains_watch_audit')->get()->toJson(), 'Early upgrade preserves suffix review evidence');
    foreach (array_keys($files) as $name) {
        expect(DB::table('migrations')->where('migration', $name)->exists(), 'Early upgrade runs every current migration: '.$name);
    }
    $columns = array_column(Schema::getColumns('domains_watch_audit'), null, 'name');
    same('datetime', $columns['created_at']['type_name'], 'Early upgrade converts the audit to explicit datetime');
    same(1 - $defaults, (int) DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS value')->value, 'Early upgrade restores session defaults');
    // A recorded date migration must never accept the legacy audit again.
    Schema::drop('domains_watch_audit');
    Schema::create('domains_watch_audit', static function (Blueprint $t): void {
        (new ReflectionMethod(PslReviewSchema::class, 'audit'))->invoke(null, $t, true);
    });
    try { WatchSchema::install(); throw new LogicException('Recorded upgrade accepted a legacy watch audit'); }
    catch (RuntimeException $error) { same('Retained Domains schema mismatch: domains_watch_audit', $error->getMessage(), 'Recorded upgrade keeps strict audit verification'); }
}
$version = DB::selectOne('SELECT VERSION() AS version')->version;
expect(str_starts_with($version, '11.4.5-MariaDB'), 'Pinned real MariaDB version');
echo json_encode(['complete' => true, 'server_version' => $version, 'prefix' => $prefix,
    'retained_tables' => count($owned), 'verified_tables' => count($verified), 'reference_creates' => count($references),
    'implicit_timestamp_defaults_off' => true, 'evidence_dates_upgrade' => true, 'psl_only_upgrade_defaults' => [0, 1],
    'fresh' => true, 'reinstall' => true, 'upgrade' => true, 'cleanup_success_and_failure' => true])."\n";
$nativeSuccessful = true;
