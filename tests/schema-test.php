<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Price, Tld};
use Paymenter\Extensions\Servers\Domains\Services\FoundationSchema;

$migration = require dirname(__DIR__).'/Domains/database/migrations/2026_10_18_000001_create_domains_foundation.php';
app(Illuminate\Database\Migrations\Migrator::class)->run([dirname(__DIR__).'/Domains/database/migrations']);
FoundationSchema::verify();
expect(DB::table('migrations')->where('migration', '2026_10_18_000001_create_domains_foundation')->exists(), 'Native migration history recorded');
foreach (Schema::getTableListing() as $table) {
    if (!str_starts_with($table, 'domains_')) { continue; }
    foreach (Schema::getIndexes($table) as $index) {
        expect(strlen($index['name']) <= 64, 'Index fits MySQL identifier limit');
    }
}
$binding = ['registrar_key' => 'enom', 'server_id' => 1, 'account_fingerprint' => str_repeat('a', 64)];
$domain = Domain::create($binding + ['id' => 41, 'service_id' => 51, 'user_id' => 61,
    'domain' => 'example.com', 'reservation_name' => 'example.com', 'sld' => 'example', 'tld' => 'com', 'status' => 'pending',
    'snapshot' => ['contact' => 'synthetic'], 'renewal_policy' => ['cap' => '10.00'], 'observation' => ['execution' => null]]);
$operation = Operation::create($binding + ['id' => 71, 'domain_id' => 41, 'service_id' => 51,
    'invoice_id' => 81, 'invoice_item_id' => 91, 'operation_key' => 'fixture:register', 'authority_generation' => 0,
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
    'authority_generation' => null, 'account_fingerprint' => null]); $unverified->save();
same(null, $unverified->fresh()->authority_generation, 'Unknown historical generation retained');
same(null, $unverified->fresh()->account_fingerprint, 'Unknown historical account proof retained');
same(41, $operation->domain->id, 'ID-preserving relation');
same(71, $domain->operations()->first()->id, 'ID-preserving history');
$account = Account::create($binding + ['environment' => 'sandbox', 'stable_identity' => 'synthetic-account', 'identity_fingerprint' => str_repeat('b', 64)]);
expect(!str_contains($account->getRawOriginal('stable_identity'), 'synthetic'), 'Identity encrypted on first save');
same(false, $account->fresh()->processing_enabled, 'Processing defaults off');
$tld = Tld::create($binding + ['id' => 101, 'tld' => 'com', 'cost_register_terms' => ['2' => '19.1234']]);
same(['2' => '19.1234'], $tld->fresh()->cost_register_terms, 'Exact-term legacy costs');
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
Domain::create(array_replace($domain->getAttributes(), ['id' => 42, 'service_id' => 52, 'reservation_name' => null]));
Domain::create(array_replace($domain->getAttributes(), ['id' => 43, 'service_id' => 53, 'reservation_name' => null]));
$before = [];
foreach (['domains_domains', 'domains_operations', 'domains_tlds', 'domains_prices', 'domains_accounts'] as $table) {
    $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
}
$migration->down(); $migration->up(); app(Illuminate\Database\Migrations\Migrator::class)->run([dirname(__DIR__).'/Domains/database/migrations']);
FoundationSchema::verify();
expect(DB::table('migrations')->where('migration', '2026_10_18_000001_create_domains_foundation')->exists(), 'Native migration history recorded');
foreach ($before as $table => $rows) { same($rows, DB::table($table)->orderBy('id')->get()->toJson(), 'Reinstall retains bytes: '.$table); }
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
expect(!array_filter(Schema::getTableListing(), static fn (string $name): bool => str_contains($name, 'domains_verify_')), 'Reference tables cleaned');
Schema::drop('domains_operations');
try { FoundationSchema::install(); expect(false, 'Partial retained installation accepted'); }
catch (RuntimeException $e) { expect(str_contains($e->getMessage(), 'Partial retained'), 'Partial installation refusal'); }
expect(!Schema::hasTable('domains_operations'), 'Missing history was not silently recreated');
echo "Domains schema: retained IDs, exact values, encryption, uniqueness and reinstall guards passed.\n";
$nativeSuccessful = true;
