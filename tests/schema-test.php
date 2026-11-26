<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/accounts.php';

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Installation\{Registry, SchemaVerifier};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Price, Tld};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationSchema;
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema, ObservationSchema};

// The extension lifecycle owns fresh-install authority through catalogue retirement.
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
$installedTables = Schema::getTableListing(); sort($installedTables);
// Resuming Orders verifies this installation; repeated schema dependencies must not create hundreds of reference tables.
DB::flushQueryLog(); DB::enableQueryLog();
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->verify();
$verificationQueries=DB::getQueryLog(); DB::disableQueryLog();
$referenceCreates=count(array_filter($verificationQueries,static fn(array $q):bool=>str_starts_with(strtolower($q['query']),'create table ')));
expect($referenceCreates<=100,'One verification uses at most 100 reference tables, got '.$referenceCreates);

FoundationSchema::verify(); OperationSchema::verify();
expect(DB::table('migrations')->where('migration', '2026_11_23_000001_create_domains_schema')->exists(), 'Native migration history recorded');
foreach (Schema::getTableListing() as $table) {
    if (!str_starts_with($table, 'domains_')) { continue; }
    foreach (Schema::getIndexes($table) as $index) {
        expect(strlen($index['name']) <= 64, 'Index fits MySQL identifier limit');
    }
}
// Account claims must not scan another account's due work. Pin the physical index,
// then prove retained verification still refuses its loss when native migrations skip.
$accountDue = array_values(array_filter(Schema::getIndexes('domains_observation_work'),
    static fn (array $index): bool => $index['columns'] === ['server_id', 'account_fingerprint', 'due_at'] && !$index['unique']));
same(1, count($accountDue), 'Observation work has the account/due composite index');
ObservationSchema::verify();
Schema::table('domains_observation_work', static fn (Blueprint $t) => $t->dropIndex($accountDue[0]['name']));
app(Illuminate\Database\Migrations\Migrator::class)->run([dirname(__DIR__).'/Domains/database/migrations']);
try { ObservationSchema::verify(); expect(false, 'Missing account/due index accepted after migration skip'); }
catch (RuntimeException $error) { same('Retained Domains schema mismatch: domains_observation_work', $error->getMessage(), 'Missing account/due index refuses retained verification'); }
Schema::table('domains_observation_work', static fn (Blueprint $t) => $t->index($accountDue[0]['columns'], $accountDue[0]['name']));
Schema::table('domains_observation_work', static fn (Blueprint $t) => $t->dropIndex(['due_at']));
try { ObservationSchema::install(); expect(false, 'Other retained observation mismatch accepted'); }
catch (RuntimeException $error) { same('Retained Domains schema mismatch: domains_observation_work', $error->getMessage(), 'Other retained mismatch still refuses'); }
Schema::table('domains_observation_work', static fn (Blueprint $t) => $t->index('due_at'));
ObservationSchema::verify();
$binding = fixtureAccount(1, 'enom', 'synthetic-account');
$account = Account::where('server_id', 1)->firstOrFail();
$domain = Domain::create($binding + ['id' => 41, 'service_id' => 51, 'user_id' => 61,
    'domain' => 'example.com', 'reservation_name' => 'example.com', 'sld' => 'example', 'tld' => 'com', 'status' => 'pending',
    'snapshot' => ['contact' => 'synthetic'], 'renewal_policy' => ['cap' => '10.00'], 'observation' => ['execution' => null]]);
$operation = Operation::create($binding + ['id' => 71, 'domain_id' => 41, 'service_id' => 51,
    'invoice_id' => 81, 'invoice_item_id' => 91, 'operation_key' => 'fixture:register', 'authority_generation' => 1,
    'action' => 'register', 'state' => 'uncertain', 'currency' => 'USD', 'amount' => '12.34',
    'payload' => ['epp' => 'synthetic-code'], 'policy' => ['refund' => null], 'cost' => ['amount' => '9.1234'],
    'attempt' => ['execution' => null], 'funding' => ['invoice' => 81]]);
foreach (['snapshot', 'renewal_policy', 'observation'] as $field) {
    expect(!str_contains($domain->getRawOriginal($field), 'synthetic'), 'Encrypted domain field');
    expect(!array_key_exists($field, $domain->toArray()), 'Domain field hidden');
}
foreach (['payload', 'policy', 'cost', 'attempt', 'funding'] as $field) {
    $raw = $operation->getRawOriginal($field);
    expect(is_string($raw) && !str_starts_with($raw, '{'), 'Encrypted operation field');
    expect(!array_key_exists($field, $operation->toArray()), 'Operation field hidden');
}
same(['execution' => null], $operation->fresh()->attempt, 'Recorded null is retained');
same('12.34', $operation->fresh()->amount, 'Exact legacy invoice amount');
$unverified = $operation->replicate();
$unverified->fill(['operation_key' => 'legacy:unverified', 'invoice_item_id' => null,
    'authority_generation' => null, 'account_fingerprint' => null]); Operation::withoutEvents(static fn () => $unverified->save()); // converter-only historical fixture
same(null, $unverified->fresh()->authority_generation, 'Unknown historical generation retained');
same(null, $unverified->fresh()->account_fingerprint, 'Unknown historical account proof retained');
same(41, $operation->domain->id, 'ID-preserving relation');
same(71, $domain->operations()->first()->id, 'ID-preserving history');
expect(!str_contains($account->getRawOriginal('stable_identity'), 'synthetic'), 'Identity encrypted on first save');
same(false, $account->fresh()->processing_enabled, 'Processing defaults off');
$tld = Tld::create(tldColumns($binding + ['id' => 101, 'tld' => 'com']));
expect(!Schema::hasColumn('domains_tlds','cost_register_terms'),'Current installation retires the legacy cost mirror');
$priceData = $binding + ['tld' => 'com', 'action' => 'register', 'term' => 2, 'currency' => 'USD',
    'unit' => 'exact_term', 'source' => 'synthetic', 'observed_at' => '2026-10-18 00:00:00'];
$price = Price::create($priceData);
same(null, $price->fresh()->wholesale, 'Unknown is not zero');
$zero = Price::create(array_replace($priceData, ['term' => 1, 'wholesale' => '0']));
same('0.00000000', $zero->fresh()->wholesale, 'Known zero retained');
$rejectDuplicate = static function (string $table, array $row): void {
    try { DB::table($table)->insert($row); expect(false, 'Unique identity escaped: '.$table); }
    catch (QueryException) { /* expected */ }
};
$domainRow = $domain->getAttributes(); unset($domainRow['id']);
$rejectDuplicate('domains_domains', array_replace($domainRow, ['service_id' => 52, 'server_id' => 2, 'registrar_key' => 'namesilo']));
$rejectDuplicate('domains_domains', array_replace($domainRow, ['reservation_name' => null]));
$operationRow = $operation->getAttributes(); unset($operationRow['id']);
$rejectDuplicate('domains_operations', array_replace($operationRow, ['invoice_item_id' => null]));
$rejectDuplicate('domains_operations', array_replace($operationRow, ['operation_key' => 'different']));
$priceRow = $price->getAttributes(); unset($priceRow['id']);
$rejectDuplicate('domains_prices', $priceRow);
$tldRow = $tld->getAttributes(); unset($tldRow['id']);
$rejectDuplicate('domains_tlds', $tldRow);
$rejectDuplicate('domains_tlds', array_replace($tldRow, ['registrar_key' => 'namesilo']));
$accountRow = $account->getAttributes(); unset($accountRow['id']);
$rejectDuplicate('domains_accounts', array_replace($accountRow, ['server_id' => 2, 'account_fingerprint' => str_repeat('c', 64)]));
// Released history can coexist without weakening the non-null global identity.
DB::table('domains_domains')->insert(array_replace($domain->getAttributes(), ['id' => 42, 'service_id' => 52, 'reservation_name' => null]));
DB::table('domains_domains')->insert(array_replace($domain->getAttributes(), ['id' => 43, 'service_id' => 53, 'reservation_name' => null]));
$before = [];
foreach (['domains_domains', 'domains_operations', 'domains_tlds', 'domains_prices', 'domains_accounts'] as $table) {
    $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
}
$entry = new Domains([]);
$entry->uninstalled();
$entry->installed();
FoundationSchema::verify(); OperationSchema::verify();
expect(DB::table('migrations')->where('migration', '2026_11_23_000001_create_domains_schema')->exists(), 'Native migration history recorded');
foreach ($before as $table => $rows) { same($rows, DB::table($table)->orderBy('id')->get()->toJson(), 'Reinstall retains bytes: '.$table); }
$operationTables = OperationSchema::tables();
same($operationTables, array_values(array_intersect($operationTables, Registry::expectedTables())), 'The table inventory lists the operation tables');
// Nullable time columns without a default are the declaration MariaDB never initializes or updates implicitly.
$timeColumns = [];
foreach ($operationTables as $table) {
    foreach (Schema::getColumns($table) as $column) {
        if ($column['type_name'] === 'datetime') { $timeColumns[$table.'.'.$column['name']] = [$column['nullable'], $column['default']]; }
    }
}
same(array_fill_keys(['domains_operations.started_at', 'domains_operations.lease_expires_at',
    'domains_operations.completed_at', 'domains_operations.created_at', 'domains_operations.updated_at', 'domains_operation_attempts.claimed_at', 'domains_operation_attempts.claim_until', 'domains_operation_attempts.released_at',
    'domains_operation_attempts.sealed_at', 'domains_operation_attempts.dispatch_until', 'domains_operation_attempts.grant_consumed_at',
    'domains_operation_attempts.read_until', 'domains_operation_evidence.observed_at', 'domains_operation_evidence.recorded_at'], [true, null]),
    $timeColumns, 'Operation time columns are nullable with no default');
// The retired renewal columns are gone, and a retained database that still has one is the old inventory and is refused.
$retired = ['renewal_admission_until', 'renewal_reserved_revision', 'renewal_proof', 'renewal_check'];
same([], array_values(array_filter($retired, static fn (string $c): bool => Schema::hasColumn('domains_operations', $c))), 'The four retired renewal columns are not installed');
Schema::table('domains_operations', static fn (Blueprint $t) => $t->longText('renewal_check')->nullable());
try { OperationSchema::verify(); $oldAccepted = true; } catch (RuntimeException) { $oldAccepted = false; }
Schema::table('domains_operations', static fn (Blueprint $t) => $t->dropColumn('renewal_check'));
expect(!$oldAccepted, 'A retained operation table with a retired renewal column is refused');
OperationSchema::verify();
// Attempts and evidence cannot outlive their operation, and verification compares each reference's columns, target and actions.
$ownedRows = static fn (): array => [DB::table(OperationSchema::STEPS)->count(), DB::table(OperationSchema::EVIDENCE)->count()];
try { DB::table(OperationSchema::STEPS)->insert(['operation_id' => 999999, 'step_type' => 'renew', 'sequence' => 1]); expect(false, 'Step without operation accepted'); }
catch (QueryException) { same([0, 0], $ownedRows(), 'Orphan step refused'); }
$stepId = DB::table(OperationSchema::STEPS)->insertGetId(['operation_id' => 71, 'step_type' => 'renew', 'sequence' => 1]);
try { DB::table('domains_operations')->where('id', 71)->delete(); expect(false, 'Operation with a step deleted'); }
catch (QueryException) { same(1, DB::table('domains_operations')->where('id', 71)->count(), 'Restrictive step reference keeps the operation'); }
DB::table(OperationSchema::STEPS)->where('id', $stepId)->delete();
$evidence = (new ReflectionMethod(OperationSchema::class, 'definitions'))->invoke(null)[OperationSchema::EVIDENCE];
$rebuildEvidence = static function (Closure $change) use ($evidence): void {
    Schema::drop(OperationSchema::EVIDENCE);
    Schema::create(OperationSchema::EVIDENCE, static function (Blueprint $t) use ($evidence, $change): void { $evidence($t); $change($t); });
};
$withoutAttemptReference = static function (Blueprint $t): void {
    (fn () => $this->commands = array_values(array_filter($this->commands, static fn ($command): bool => $command->name !== 'foreign' || $command->columns !== ['attempt_id'])))->call($t);
};
foreach ([
    'Missing attempt reference' => $withoutAttemptReference,
    'Cascading attempt reference' => static function (Blueprint $t) use ($withoutAttemptReference): void {
        $withoutAttemptReference($t);
        $t->foreign('attempt_id', 'domains_operation_evidence_cascade_f')->references('id')->on(OperationSchema::ATTEMPTS)->cascadeOnDelete();
    },
    'Unexpected reference' => static fn (Blueprint $t) => $t->foreign('operation_id', 'domains_operation_evidence_extra_f')->references('id')->on(OperationSchema::STEPS),
] as $case => $change) {
    $rebuildEvidence($change);
    try { OperationSchema::verify(); expect(false, $case.' accepted'); }
    catch (RuntimeException $e) { same('Retained Domains schema mismatch: '.OperationSchema::EVIDENCE, $e->getMessage(), $case.' refuses retained verification'); }
}
$rebuildEvidence(static function (): void {});
OperationSchema::verify();
// A same-named target in another database is a different reference. SQLite cannot build one; MariaDB proves it physically.
$reference = new ReflectionMethod(SchemaVerifier::class, 'reference');
$key = Schema::getForeignKeys(OperationSchema::EVIDENCE)[0];
expect($reference->invoke(null, $key) !== $reference->invoke(null, ['foreign_schema' => 'restored_copy'] + $key), 'Reference target includes its schema');
expect($reference->invoke(null, ['foreign_schema' => 'paymenter'] + $key) !== $reference->invoke(null, ['foreign_schema' => 'Paymenter'] + $key), 'A case-distinct schema is another target');
expect($reference->invoke(null, $key) !== $reference->invoke(null, ['foreign_table' => strtoupper($key['foreign_table'])] + $key), 'A case-distinct table is another target');
// Missing indexes and changed columns fail closed; verification never repairs retained history.
Schema::table('domains_domains', static fn (Blueprint $t) => $t->dropUnique(['reservation_name']));
app(Illuminate\Database\Migrations\Migrator::class)->run([dirname(__DIR__).'/Domains/database/migrations']);
try { FoundationSchema::verify(); expect(false, 'Missing reservation unique index accepted after native migration skip'); }
catch (RuntimeException $e) { expect(str_contains($e->getMessage(), 'schema mismatch'), 'Schema refusal'); }
DB::statement("CREATE UNIQUE INDEX partial_reservation ON domains_domains (reservation_name) WHERE status = 'active'");
try { FoundationSchema::install(); expect(false, 'Partial reservation index accepted'); }
catch (RuntimeException $e) { expect(str_contains($e->getMessage(), 'schema mismatch'), 'Partial index refusal'); }
DB::statement('DROP INDEX partial_reservation');
Schema::table('domains_domains', static fn (Blueprint $t) => $t->unique('reservation_name'));
Schema::table('domains_operations', static fn (Blueprint $t) => $t->dropColumn('attempt'));
try { FoundationSchema::install(); expect(false, 'Missing evidence column accepted'); }
catch (RuntimeException $e) { expect(str_contains($e->getMessage(), 'schema mismatch'), 'Column refusal'); }
$afterVerification = Schema::getTableListing(); sort($afterVerification);
same($installedTables, $afterVerification, 'All reference tables cleaned after success and schema refusals');
Schema::drop('domains_operations');
try { FoundationSchema::install(); expect(false, 'Partial retained installation accepted'); }
catch (RuntimeException $e) { expect(str_contains($e->getMessage(), 'Partial retained'), 'Partial installation refusal'); }
expect(!Schema::hasTable('domains_operations'), 'Missing history was not silently recreated');
// A connection prefix must not hide missing or weakened reservation constraints.
$connection = DB::getDefaultConnection();
$schema = Schema::getFacadeRoot();
config(['database.connections.prefixed' => array_replace(config('database.connections.sqlite'), ['prefix' => 'fixture_'])]);
DB::setDefaultConnection('prefixed');
Schema::swap(DB::connection()->getSchemaBuilder());
try {
    FoundationSchema::install();
    FoundationSchema::verify(); OperationSchema::verify();
    DB::table('domains_operations')->insert($operationRow);
    $rejectDuplicate('domains_operations',array_replace($operationRow,['operation_key'=>'other-key']));
    $retryIndexes=array_values(array_filter(Schema::getIndexes('domains_operations'),fn($i)=>$i['columns']===['invoice_item_id','action','retry_number']&&$i['unique']));
    same(1,count($retryIndexes),'One exact retry identity index');
    Schema::table('domains_operations',static fn(Blueprint $t)=>$t->dropUnique($retryIndexes[0]['name']));
    try{OperationSchema::verify();expect(false,'Missing retry uniqueness accepted');}
    catch(RuntimeException $e){expect(str_contains($e->getMessage(),'schema mismatch'),'Retained retry drift refuses');}
    Schema::table('domains_operations',static fn(Blueprint $t)=>$t->unique(['invoice_item_id','action','retry_number'],$t->getTable().'_debt_retry_u'));
    Schema::table('domains_domains', static fn (Blueprint $t) => $t->dropUnique(['reservation_name']));
    try { FoundationSchema::verify(); expect(false, 'Prefixed missing reservation index accepted'); }
    catch (RuntimeException $e) { same('Retained Domains schema mismatch: domains_domains', $e->getMessage(), 'Prefixed missing index refusal'); }
    DB::statement("CREATE UNIQUE INDEX fixture_partial_reservation ON fixture_domains_domains (reservation_name) WHERE status = 'active'");
    try { FoundationSchema::verify(); expect(false, 'Prefixed partial reservation index accepted'); }
    catch (RuntimeException $e) { same('Retained Domains schema mismatch: partial index', $e->getMessage(), 'Prefixed partial index refusal'); }
} finally {
    Schema::swap($schema);
    DB::setDefaultConnection($connection);
    DB::purge('prefixed');
}
echo "Domains schema: retained IDs, exact values, encryption, uniqueness and reinstall guards passed.\n";
$nativeSuccessful = true;
