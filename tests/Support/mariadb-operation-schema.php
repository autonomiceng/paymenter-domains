<?php
// Operation time columns, keyed claims and evidence references on a disposable MariaDB, no registrar calls.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/mariadb-bootstrap.php';
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Installation\SchemaVerifier;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptToken, OperationSchema, OperationWriter, SealTerms, StepKey, Transition};
use Paymenter\Extensions\Servers\Domains\Services\FoundationSchema;

same(getenv('DB_SOCKET'), DB::connection()->getConfig('unix_socket'), 'Resolved socket belongs to this disposable fixture');
$database = DB::connection()->getDatabaseName();
same(getenv('DB_DATABASE'), $database, 'Resolved database belongs to this disposable fixture');
// With case-sensitive identifiers a differently cased schema or table is another object.
same(0, (int) DB::selectOne('SELECT @@lower_case_table_names AS value')->value, 'Case-sensitive identifiers');
config(['database.connections.mysql.prefix' => (string) getenv('SCHEMA_PREFIX')]);
DB::purge('mysql'); Schema::clearResolvedInstance('db.schema');
$prefix = DB::connection()->getTablePrefix();
// Under this setting MariaDB gives the first NOT NULL timestamp a default and ON UPDATE CURRENT_TIMESTAMP.
DB::statement('SET SESSION explicit_defaults_for_timestamp = OFF');
same(0, (int) DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS value')->value, 'Exercise implicit timestamp behavior');

// Only the foundation and the operation tables are needed; the full lifecycle is test_schema_mariadb.py.
FoundationSchema::install(); OperationSchema::install(); OperationSchema::verify();
$operations = $prefix.'domains_operations'; $evidence = $prefix.OperationSchema::EVIDENCE; $attempts = $prefix.OperationSchema::ATTEMPTS;
$times = DB::select("SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS value, EXTRA AS extra FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?, ?) AND DATA_TYPE IN ('timestamp', 'datetime') ORDER BY TABLE_NAME, ORDINAL_POSITION", [$database, $operations, $attempts, $evidence]);
same(14, count($times), 'Every operation time column inspected');
foreach ($times as $column) {
    // MariaDB reports an explicit NULL default as the string NULL.
    same(['YES', true, ''], [$column->nullable, in_array($column->value, [null, 'NULL'], true), $column->extra], $column->name.' has no implicit default or update');
}
// An ON UPDATE added to a retained column is drift: Laravel's portable columns omit it, so the verifier reads it itself.
DB::statement('ALTER TABLE `'.$attempts.'` MODIFY `claimed_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
try { OperationSchema::verify(); throw new LogicException('ON UPDATE drift accepted'); }
catch (RuntimeException $error) { same('Retained Domains schema mismatch: '.OperationSchema::ATTEMPTS, $error->getMessage(), 'ON UPDATE drift refused'); }
DB::statement('ALTER TABLE `'.$attempts.'` MODIFY `claimed_at` DATETIME NULL DEFAULT NULL');
OperationSchema::verify();

// Sealing and spending the grant update the attempt; the claim time stays the writer's own.
$operation = static fn (string $key): int => (int) DB::table('domains_operations')->insertGetId(['server_id' => 1, 'registrar_key' => 'enom',
    'domain_id' => 1, 'service_id' => 1, 'operation_key' => $key, 'action' => 'renew', 'state' => 'queued', 'currency' => 'USD']);
$operationId = $operation('mariadb-claim-time');
$claimedAt = new DateTimeImmutable('2026-01-05T09:30:00+00:00');
$writer = OperationWriter::at($claimedAt);
$token = DB::transaction(static fn () => $writer->claim($operationId, StepKey::renew(), $claimedAt->modify('+60 seconds')));
expect($token instanceof AttemptToken, 'Claim recorded');
$claimTimes = static fn (): array => (array) DB::table(OperationSchema::ATTEMPTS)->where('id', $token->attemptId)->first(['claimed_at', 'claim_until']);
$claimed = $claimTimes();
same(['claimed_at' => '2026-01-05 09:30:00', 'claim_until' => '2026-01-05 09:31:00'], $claimed, 'Claim times as written');
same(Transition::Applied, DB::transaction(static fn () => $writer->seal($token, new SealTerms(7, str_repeat('a', 64), new Money('8.120000', 'USD'),
    'ordinary', $claimedAt->modify('+300 seconds')))), 'Seal');
same(Transition::Applied, DB::transaction(static fn () => $writer->consumeGrant($token)), 'Grant consumed');
same($claimed, $claimTimes(), 'Updates leave the claim times unchanged');

// Keyed claims on two independent connections: the second worker never takes a step the first holds, even before it commits.
config(['database.connections.second' => config('database.connections.mysql')]);
$on = static function (string $connection, Closure $work): mixed {
    $previous = DB::getDefaultConnection(); DB::setDefaultConnection($connection);
    try { return $work(); } finally { DB::setDefaultConnection($previous); }
};
$on('second', static fn () => DB::statement('SET SESSION innodb_lock_wait_timeout = 1'));
expect(DB::selectOne('SELECT CONNECTION_ID() AS id')->id !== $on('second', static fn () => DB::selectOne('SELECT CONNECTION_ID() AS id')->id),
    'The second worker has its own server connection');
$keyed = $operation('mariadb-keyed-claims');
DB::beginTransaction();
$first = $writer->claim($keyed, StepKey::renew(), $claimedAt->modify('+60 seconds'));
expect($first instanceof AttemptToken, 'The first worker claims the step');
$waited = $on('second', static function () use ($writer, $keyed, $claimedAt): ?int {
    try { DB::transaction(static fn () => $writer->claim($keyed, StepKey::renew(), $claimedAt->modify('+60 seconds'))); return null; }
    catch (QueryException $error) { return (int) ($error->errorInfo[1] ?? 0); }
});
same(1205, $waited, 'An uncommitted claim makes the other connection wait, never claim');
DB::commit();
same(Transition::Busy, $on('second', static fn () => DB::transaction(static fn () => $writer->claim($keyed, StepKey::renew(), $claimedAt->modify('+60 seconds')))),
    'A committed live claim is busy for the other connection');
$later = OperationWriter::at($claimedAt->modify('+120 seconds'));
$second = $on('second', static fn () => DB::transaction(static fn () => $later->claim($keyed, StepKey::renew(), $claimedAt->modify('+180 seconds'))));
expect($second instanceof AttemptToken && $second->ordinal === 2 && $second->claimKey !== $first->claimKey, 'An expired claim passes to the other connection under a new key');
$insideFirstLease = OperationWriter::at($claimedAt->modify('+30 seconds'));
same(Transition::Stale, DB::transaction(static fn () => $insideFirstLease->seal($first, new SealTerms(1, str_repeat('b', 64), new Money('8.120000', 'USD'),
    'ordinary', $claimedAt->modify('+300 seconds')))), 'Replacement fences the first claim even inside its original lease');
same($second->attemptId, (int) DB::table(OperationSchema::STEPS)->where('id', $first->stepId)->value('current_attempt_id'), 'The step points to the second claim');
$stepIds = DB::table(OperationSchema::STEPS)->where('operation_id', $keyed)->pluck('id');
same([1, 2], [count($stepIds), DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', $stepIds)->count()], 'One step with both claims as history');
DB::purge('second');
foreach ([$operationId, $keyed] as $id) {
    $steps = DB::table(OperationSchema::STEPS)->where('operation_id', $id)->pluck('id');
    DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', $steps)->delete();
    DB::table(OperationSchema::STEPS)->whereIn('id', $steps)->delete();
    DB::table('domains_operations')->where('id', $id)->delete();
}

$original = Schema::getForeignKeys(OperationSchema::EVIDENCE);
$attemptKey = array_values(array_filter($original, static fn (array $key): bool => $key['columns'] === ['attempt_id']))[0];
same([$database, $attempts], [$attemptKey['foreign_schema'], $attemptKey['foreign_table']], 'The attempt reference targets this database');

$created = [];
$target = static function (string $schema, string $table) use (&$created): void {
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.$schema.'`');
    DB::statement('CREATE TABLE `'.$schema.'`.`'.$table.'` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $created[] = [$schema, $table];
};
$point = static function (string $schema, string $table) use ($evidence, $attemptKey): void {
    DB::statement('ALTER TABLE `'.$evidence.'` DROP FOREIGN KEY `'.$attemptKey['name'].'`');
    DB::statement('ALTER TABLE `'.$evidence.'` ADD CONSTRAINT `'.$attemptKey['name'].'` FOREIGN KEY (`attempt_id`) REFERENCES `'
        .$schema.'`.`'.$table.'` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT');
};
$refuses = static function (string $case): void {
    try { OperationSchema::verify(); throw new LogicException($case.' accepted'); }
    catch (RuntimeException $error) { same('Retained Domains schema mismatch: '.OperationSchema::EVIDENCE, $error->getMessage(), $case.' refused'); }
};
// Owned fresh-install recovery, as SchemaVerifier::verify runs it for an owning lifecycle: build the reference, add what is missing, drop it.
// Only the full lifecycle owns the tables, so this partial install calls the recovery step directly.
$recover = static function (): void {
    $definition = (new ReflectionMethod(OperationSchema::class, 'definitions'))->invoke(null)[OperationSchema::EVIDENCE];
    $reference = substr(bin2hex(random_bytes(16)), 0, strlen(OperationSchema::EVIDENCE));
    Schema::create($reference, $definition);
    try { (new ReflectionMethod(SchemaVerifier::class, 'resumeIndexes'))->invoke(null, OperationSchema::EVIDENCE, $reference); }
    finally { Schema::dropIfExists($reference); }
};

$cases = [
    'foreign schema' => [$database.'_restored', $attempts],
    'case-distinct schema' => [strtoupper($database), $attempts],
    'case-distinct table' => [$database, strtoupper($attempts)],
];
$proved = [];
foreach ($cases as $case => [$schema, $table]) {
    $target($schema, $table);
    // Retained path: one verification run reuses its expected shapes and must still read the live reference.
    SchemaVerifier::batch(static function () use ($point, $refuses, $schema, $table, $case): void {
        OperationSchema::verify();
        $point($schema, $table);
        $refuses($case.' on the retained path');
    });
    $pointed = array_values(array_filter(Schema::getForeignKeys(OperationSchema::EVIDENCE), static fn (array $key): bool => $key['columns'] === ['attempt_id']))[0];
    same([$schema, $table], [$pointed['foreign_schema'], $pointed['foreign_table']], $case.' is the live target');
    $refuses($case.' on the fresh path');
    // Recovery never replaces a reference it did not create.
    $recover();
    $refuses($case.' after recovery');
    DB::statement('ALTER TABLE `'.$evidence.'` DROP FOREIGN KEY `'.$attemptKey['name'].'`');
    $refuses($case.' once removed');
    $recover();
    OperationSchema::verify();
    same($original, Schema::getForeignKeys(OperationSchema::EVIDENCE), $case.': recovery restores the original reference');
    $proved[] = $case;
}
foreach (array_reverse($created) as [$schema, $table]) {
    DB::statement('DROP TABLE `'.$schema.'`.`'.$table.'`');
    if ($schema !== $database) { DB::statement('DROP DATABASE `'.$schema.'`'); }
}
$version = DB::selectOne('SELECT VERSION() AS version')->version;
expect(str_starts_with($version, '11.4.5-MariaDB'), 'Pinned real MariaDB version');
echo json_encode(['complete' => true, 'server_version' => $version, 'prefix' => $prefix, 'refused_and_recovered' => $proved,
    'time_columns_explicit' => count($times), 'on_update_drift_refused' => true, 'claim_time_kept' => true, 'keyed_claims_independent' => true])."\n";
$nativeSuccessful = true;
