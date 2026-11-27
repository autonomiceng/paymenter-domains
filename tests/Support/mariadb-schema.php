<?php
// Native fresh + retained lifecycle on a disposable MariaDB, no registrar calls.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/mariadb-bootstrap.php';
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Artisan,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Installation\{Registry,SchemaVerifier,Verification};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionResultSchema,OperationSchema};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema,Installation,DomainAuditSchema};

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
same(realpath(getenv('DOMAINS_SCHEMA_PACKAGE').'/Domains.php'), (new ReflectionClass($entry))->getFileName(),
    'The MariaDB lifecycle loads the verified extension package');
$entry->installed();
$tables = Schema::getTableListing($database, false); sort($tables);
$owned = array_values(array_diff($tables, $native));
$prefix = DB::connection()->getTablePrefix();
$registered = array_map(static fn (string $table): string => $prefix.$table, Installation::expectedTables()); sort($registered);
same(46, count($registered), 'The registry lists the complete Domains schema');
same($registered, $owned, 'Fresh native install created exactly the registered Domains schema: '
    .json_encode(['missing' => array_values(array_diff($registered, $owned)), 'extra' => array_values(array_diff($owned, $registered))]));
foreach ($owned as $table) {
    expect(str_starts_with($table, $prefix.'domains_'), 'Fresh install left no reference table: '.$table);
    expect(isset($verified[substr($table, strlen($prefix))]), 'Fresh install verified every retained definition: '.$table);
}
DB::table('domains_public_dns_pool')->insert(['id'=>1,'active'=>'[]','used'=>3,'window_at'=>100]);
$snapshot = static function () use ($owned, $prefix): array {
    $result = [];
    foreach ($owned as $physical) {
        $table = substr($physical, strlen($prefix));
        $result[$physical] = [Schema::getColumns($table), Schema::getIndexes($table), Schema::getForeignKeys($table), DB::table($table)->get()->toJson()];
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
// Reproduce the baseline inventory and history before the targeted upgrade. The guard
// refuses a retained acquisition, then installs only after its disposable work is retired.
Schema::drop(AcquisitionResultSchema::TABLE);
DB::table('migrations')->where('migration', Registry::ACQUISITION_RESULTS)->delete();
$fixture = DB::table('domains_operations')->insertGetId([
    'server_id' => 1, 'registrar_key' => 'enom', 'domain_id' => 1, 'service_id' => 1,
    'operation_key' => 'old-demo-acquisition', 'action' => 'register', 'state' => 'queued', 'currency' => 'USD',
]);
try { $entry->upgraded('0.0.1'); throw new LogicException('Upgrade accepted a retained acquisition'); }
catch (DomainException $error) { expect(str_contains($error->getMessage(), 'retire the demo acquisition fixtures'), 'Retained acquisition stops upgrade'); }
expect(!Verification::current() && !Schema::hasTable(AcquisitionResultSchema::TABLE), 'Refusal leaves the baseline shape and no current receipt');
expect(DB::table('domains_operations')->where('id', $fixture)->exists(), 'The installer leaves the reset to the operator');
DB::table('domains_runtime_operations')->insert(['operation_id' => $fixture]);
DB::table('domains_operations')->where('id', $fixture)->delete();
try { $entry->upgraded('0.0.1'); throw new LogicException('Upgrade accepted stranded work'); }
catch (DomainException $error) { expect(str_contains($error->getMessage(), 'retire the demo acquisition fixtures'), 'Stranded work stops upgrade'); }
DB::table('domains_runtime_operations')->where('operation_id', $fixture)->delete();
$entry->upgraded('0.0.1');
expect(Schema::hasTable('domains_profile_operations'), 'The upgrade keeps the installed NameSilo contact ledger');
expect(Verification::current(), 'The retained installer verified the upgrade before returning');
same(1, DB::table('migrations')->where('migration', Registry::ACQUISITION_RESULTS)->count(), 'The targeted upgrade was recorded once');
same($before, $snapshot(), 'The baseline upgrade retains unrelated rows and reaches the fresh shape');

$server = \App\Models\Server::create(['name' => 'Result creation fixture', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
$account = Account::withVerifiedBindingWrite(static fn () => Account::create([
    'server_id' => $server->id, 'registrar_key' => 'enom', 'environment' => 'test', 'stable_identity' => 'result-fixture',
]));
$binding = $account->only(['server_id', 'registrar_key', 'account_fingerprint']);
$domain = Domain::create($binding + [
    'service_id' => 1, 'user_id' => 1, 'domain' => 'result-fixture.com',
    'sld' => 'result-fixture', 'tld' => 'com', 'status' => 'registration_pending',
]);
$beforeCreation = $snapshot();
DB::beginTransaction();
try {
    $acquisition = Operation::create($binding + [
        'domain_id' => $domain->id, 'service_id' => $domain->service_id, 'authority_generation' => $domain->authority_generation,
        'operation_key' => 'result-creation', 'action' => 'register', 'state' => 'awaiting_payment', 'currency' => 'USD',
    ]);
    same(1, DB::table(AcquisitionResultSchema::TABLE)->where('operation_id', $acquisition->id)->count(),
        'A never-claimed acquisition immediately owns one result');
} finally {
    DB::rollBack();
}
same($beforeCreation, $snapshot(), 'Operation and result creation roll back together');
// Exercise finally cleanup on a real metadata mismatch as well as successful verification.
Schema::table('domains_adoptions', static fn ($t) => $t->string('unexpected')->nullable());
try { AdoptionSchema::verify(); throw new LogicException('Schema drift accepted'); }
catch (RuntimeException $error) { same('Retained Domains schema mismatch: domains_adoptions', $error->getMessage(), 'Specific drift refusal'); }
$after = Schema::getTableListing($database, false); sort($after); same($tables, $after, 'Failed verification also removes reference tables');
Schema::table('domains_adoptions', static fn ($t) => $t->dropColumn('unexpected'));
// A drifted ON UPDATE is refused by the full check, which then leaves no fresh verification receipt.
Verification::run(); expect(Verification::current(), 'A clean full check records a fresh receipt');
$claimedAt = static fn (string $extra): bool => DB::statement('ALTER TABLE `'.$prefix.OperationSchema::ATTEMPTS.'` MODIFY `claimed_at` DATETIME NULL DEFAULT NULL'.$extra);
$claimedAt(' ON UPDATE CURRENT_TIMESTAMP');
expect(!Verification::current(), 'Drift makes the receipt stale');
try { Verification::run(); throw new LogicException('ON UPDATE drift accepted by the full check'); }
catch (RuntimeException $error) { same('Retained Domains schema mismatch: '.OperationSchema::ATTEMPTS, $error->getMessage(), 'Full check refuses ON UPDATE drift'); }
$claimedAt('');
expect(!Verification::current(), 'A refused check invalidates the old receipt even after the schema is restored');
Verification::run(); expect(Verification::current(), 'The restored definition verifies again');
// A dropped or weakened reference refuses retained verification; restoring the exact reference passes again.
$evidenceKeys = Schema::getForeignKeys(OperationSchema::EVIDENCE);
same(2, count($evidenceKeys), 'Evidence references its operation and attempt');
$attemptKey = array_values(array_filter($evidenceKeys, static fn (array $key): bool => $key['columns'] === ['attempt_id']))[0];
Schema::table(OperationSchema::EVIDENCE, static fn ($t) => $t->dropForeign($attemptKey['name']));
try { OperationSchema::verify(); throw new LogicException('Missing attempt reference accepted'); }
catch (RuntimeException $error) { same('Retained Domains schema mismatch: '.OperationSchema::EVIDENCE, $error->getMessage(), 'Missing reference refusal'); }
Schema::table(OperationSchema::EVIDENCE, static fn ($t) => $t->foreign('attempt_id', $attemptKey['name'])->references('id')->on(OperationSchema::ATTEMPTS)->cascadeOnDelete());
try { OperationSchema::verify(); throw new LogicException('Cascading attempt reference accepted'); }
catch (RuntimeException $error) { same('Retained Domains schema mismatch: '.OperationSchema::EVIDENCE, $error->getMessage(), 'Changed referential action refusal'); }
Schema::table(OperationSchema::EVIDENCE, static fn ($t) => $t->dropForeign($attemptKey['name']));
Schema::table(OperationSchema::EVIDENCE, static fn ($t) => $t->foreign('attempt_id', $attemptKey['name'])->references('id')->on(OperationSchema::ATTEMPTS)->restrictOnDelete()->restrictOnUpdate());
OperationSchema::verify();
same($evidenceKeys, Schema::getForeignKeys(OperationSchema::EVIDENCE), 'Restored reference has the original shape');
$guardServer=\App\Models\Server::create(['name'=>'Freshness guard','type'=>'server','extension'=>'Domains','enabled'=>true]);
Schema::create('domains_freshness_guard',static function($table):void {$table->id();$table->unsignedBigInteger('server_id');});
DB::table('domains_freshness_guard')->insert(['server_id'=>$guardServer->id]);
try{(new \ReflectionMethod(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,'freshEnrollment'))
    ->invoke(app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class),(int)$guardServer->id);
    throw new LogicException('Prefixed retained Domains table passed the fresh-server guard');
}catch(DomainException $error){same('Account enrollment requires a fresh server.',$error->getMessage(),'Prefixed retained table blocks account enrollment');}
Schema::drop('domains_freshness_guard');$guardServer->newQuery()->whereKey($guardServer->id)->delete();
// The writer treats a duplicate authority key as already recorded; it relies on MariaDB naming that key in the error.
DB::statement('SET FOREIGN_KEY_CHECKS = 0');
$evidenceRow = ['operation_id' => 1, 'source' => 'local', 'kind' => 'terminal', 'outcome' => 'x', 'authority_key' => str_repeat('a', 64), 'evidence' => 'x'];
DB::table(OperationSchema::EVIDENCE)->insert($evidenceRow);
try { DB::table(OperationSchema::EVIDENCE)->insert($evidenceRow); throw new LogicException('Duplicate authority key accepted'); }
catch (\Illuminate\Database\UniqueConstraintViolationException $error) { expect(str_contains((string) ($error->errorInfo[2] ?? ''), 'authority_key'), 'A duplicate authority key names its key'); }
DB::table(OperationSchema::EVIDENCE)->where('authority_key', $evidenceRow['authority_key'])->delete();
DB::statement('SET FOREIGN_KEY_CHECKS = 1');
$version = DB::selectOne('SELECT VERSION() AS version')->version;
expect(str_starts_with($version, '11.4.5-MariaDB'), 'Pinned real MariaDB version');
echo json_encode(['complete' => true, 'server_version' => $version, 'prefix' => $prefix,
    'retained_tables' => count($owned), 'verified_tables' => count($verified), 'reference_creates' => count($references),
    'implicit_timestamp_defaults_off' => true, 'on_update_drift_refused' => true,
    'fresh' => true, 'reinstall' => true, 'upgrade' => true, 'acquisition_upgrade' => true, 'cleanup_success_and_failure' => true])."\n";
$nativeSuccessful = true;
