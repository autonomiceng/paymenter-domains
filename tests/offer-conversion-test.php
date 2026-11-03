<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use App\Models\{Invoice, InvoiceItem, InvoiceTransaction, Order, Server, Service};
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Evaluator, FeedObserved, Intent, Query, Revision, Snapshot, UpgradeComparison, UpgradeConversion};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, Installation, OfferSchema, OfferWorkSchema, SellRule, SellTableSchema};

// A real retained/native database is necessary: atomic resume, permanent mappings and frozen
// financial rows are the converter's persistence contract. Registrar fixtures are entirely fake.
$migrator = app(Migrator::class);
$files = $migrator->getMigrationFiles([dirname(__DIR__).'/Domains/database/migrations']);
// Retain the schema from before conversion and its later worker migration.
unset($files[OfferSchema::MIGRATION], $files[OfferWorkSchema::MIGRATION]);
$migrator->runPending($files, ['force' => true]);
extract(commerceFixture());
[$enom, $namesilo] = $tlds;
$binding = CatalogueAcceptances::binding(Account::where('server_id', $enom->server_id)->sole());
$key = ['server_id' => $binding->serverId, 'account_fingerprint' => $binding->fingerprint];
$server = Server::findOrFail($binding->serverId);
$server->settings()->create(['key' => SellRule::KEY, 'type' => 'array', 'encrypted' => false,
    'value' => ['mode' => 'only', 'max_cost' => '12.0000', 'years' => 3, 'transfers' => true]]);
DB::table(SellTableSchema::ROWS)->insert([...$key, 'tld' => 'com', 'choice' => 'sell', 'longest' => 2,
    'transfer' => false, 'register_cost' => '9.1250', 'renew_cost' => '9.5000', 'transfer_cost' => null,
    'read_at' => $now->modify('+1 hour')->getTimestamp(), 'prices' => json_encode([1 => [], 2 => [], 5 => []])]);
DB::table(SellTableSchema::ROWS)->insert([...$key, 'tld' => 'co.uk', 'choice' => 'sell']);
DB::table(SellTableSchema::FILLS)->insert([...$key, 'run' => 7, 'tried_at' => 100,
    'filled_at' => 90, 'failed_at' => 95, 'failure' => 'transport_failed']);
$accepted = CatalogueAcceptances::load($binding);
CatalogueAcceptances::save($binding, $accepted->revision, [...$accepted->endings,
    'net' => ['register' => [1], 'renew' => [1], 'transfer' => [], 'registry' => true, 'renewal_safe' => true]]);
$org = Tld::create([...$key, 'registrar_key' => 'enom', 'tld' => 'org']);
$time = '2020-01-01 00:00:00'; $until = '2020-01-03 00:00:00';
foreach ([['register', 1, '5.0000', 'term_total'], ['register', 2, '7.0000', 'annual'],
    ['register', 4, '2.0000', 'mystery'], ['renew', 1, null, 'term_total'], ['transfer', 1, null, 'term_total']] as [$action, $term, $amount, $unit]) {
    DB::table('domains_prices')->insert([...$key, 'registrar_key' => 'enom', 'tld' => 'org',
        'action' => $action, 'term' => $term, 'currency' => 'USD', 'unit' => $unit,
        'source' => 'synthetic', 'wholesale' => $amount, 'retail' => $action === 'renew' ? '19.95' : null,
        'observed_at' => $time, 'fresh_until' => $action === 'renew' ? null : $until]);
}
DB::table(SellTableSchema::ROWS)->insert([...$key, 'tld' => 'org', 'choice' => 'skip', 'register_cost' => '6.0000',
    'read_at' => (new DateTimeImmutable($time, new DateTimeZone('UTC')))->getTimestamp()]);
// The owned account is deliberately no longer selling, while native mappings and retail stay.
$sellers->change('com', 2, $namesilo->server_id, true, true, $now);
DB::table('domains_tlds')->where('id', $enom->id)->update(['enabled' => false, 'registry_fields' => '[]',
    'cost_register_terms' => '{"1":"1.0000"}', 'costs_synced_at' => '2020-01-01 00:00:00']);
$owner = commerceUser(); $product = $native->product($enom, 'register');
$order = Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
$service = Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $product->id,
    'plan_id' => $product->plans()->where('billing_period', 1)->sole()->id, 'quantity' => 1,
    'price' => '10.40', 'currency_code' => 'USD', 'status' => 'active']);
$domain = DB::table('domains_domains')->insertGetId([...$key, 'registrar_key' => 'enom',
    'service_id' => $service->id, 'user_id' => $owner->id, 'domain' => 'example.com', 'reservation_name' => 'example.com',
    'sld' => 'example', 'tld' => 'com', 'status' => 'active', 'snapshot' => 'synthetic frozen snapshot']);
foreach (['pending', 'paid', 'unknown'] as $i => $state) {
    $invoice = Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => $state === 'pending' ? 'pending' : 'paid']);
    $item = InvoiceItem::create(['invoice_id' => $invoice->id, 'reference_type' => $service->getMorphClass(),
        'reference_id' => $service->id, 'description' => 'Retained annual obligation', 'quantity' => 1, 'price' => '10.40']);
    if ($state !== 'pending') { InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => '10.40', 'fee' => '0.00', 'status' => 'succeeded']); }
    DB::table('domains_operations')->insert([...$key, 'registrar_key' => 'enom', 'domain_id' => $domain,
        'service_id' => $service->id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id,
        'operation_key' => 'synthetic-conversion-'.$i, 'action' => 'renew', 'state' => $state,
        'amount' => '10.40', 'currency' => 'USD', 'cost' => 'synthetic frozen ceiling',
        'attempt' => 'synthetic frozen attempt', 'funding' => 'synthetic frozen funding']);
}
DB::table('domains_tlds')->where('id', $namesilo->id)->update(['register_enabled' => false, 'renew_enabled' => false, 'transfer_enabled' => false]);
$before = UpgradeComparison::capture();
(new Installation)->install();
(new Installation)->install();
same(true, UpgradeComparison::compare($before)['compatible'], 'Retained upgrade adds fields without changing IDs, mappings, intent or obligations');
$converter = new UpgradeConversion;
$result = $converter->batch($binding, 1);
same(1, $result['converted'], 'Conversion stages the retained offer instead of silently skipping it');
same(false, $result['complete'], 'A bounded first batch leaves a resume point');
// Interrupt after the offer UPDATE but before its cursor commit. The transaction must roll back.
$interrupt = true;
DB::listen(static function ($query) use (&$interrupt): void {
    if ($interrupt && str_starts_with($query->sql, 'update "domains_tlds"') && str_contains($query->sql, '"catalogue_converted"')) {
        $interrupt = false; throw new RuntimeException('Synthetic conversion interruption');
    }
});
try { $converter->batch($binding, 1); expect(false, 'Expected interrupted conversion'); }
catch (RuntimeException $error) { same('Synthetic conversion interruption', $error->getMessage(), 'Intended interruption reached'); }
same(0, (int) DB::table('domains_tlds')->where('id', $enom->id)->value('catalogue_converted'), 'Interrupted offer is not half converted');
same('co.uk', DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->value('offer_conversion_cursor'), 'Interrupted cursor retains its last committed offer');
same(1, $converter->batch($binding, 1)['converted'], 'Interrupted offer converts on resume');
same(['converted' => 2, 'complete' => true], $converter->batch($binding, 100), 'A final bounded call includes acceptance-only and conflicting offers');
$converted = UpgradeComparison::query('domains_tlds')->where('id', $enom->id)->sole();
$intent = json_decode($converted->catalogue_intent, true);
same([true, 2, false], [$intent['sell'], $intent['longest'], $intent['transfers']], 'Explicit choices survive the account default and accepted intent');
same([false, 3, true, '12.0000'], [$intent['defaultSell'], $intent['defaultLongest'], $intent['defaultTransfers'], $intent['maxAnnualCost']], 'Typed saved account defaults survive');
same(['register', 'renew', 'transfer'], $intent['stops'], 'Saved Sell rule preserves the independent staff master Off');
$revision = new Revision(1, 1, 1);
$holderOffer = new Snapshot(new FeedObserved($binding, 'com', []), null, new Intent(...$intent),
    $revision, null, [], null, accountReady: true, retainedAnnualRetail: $converted->catalogue_retained_annual_retail);
same('staff_stopped', (new Evaluator)->evaluate($holderOffer, new Query($binding, ['com'], 'register'), Context::Staff, $now)->reason,
    'Master Off still blocks an otherwise Sell offer through the shared evaluator');
$holder = (new Evaluator)->evaluate($holderOffer, new Query($binding, ['com'], 'renew'), Context::Holder, $now);
same([true, '10.4', 'retained_renewal'], [$holder->available, $holder->retail, $holder->warning], 'Existing holder retains annual renewal despite the master stop');
same(null, $converted->catalogue_facts, 'Old registry flags and cost timestamps never become dated facts');
same('10.4', $converted->catalogue_retained_annual_retail, 'Deselected unsold holder keeps positive annual retail independently of newest costs');
same(null, $converted->catalogue_published_revision, 'Conversion grants no publication authority');
$readCost = static fn (string $action, int $term = 1): ?array => json_decode(DB::table('domains_prices')
    ->where([...$key, 'tld' => 'com', 'action' => $action, 'term' => $term])->value('catalogue_observation') ?? 'null', true);
same('9.1250', $readCost('register')['total'], 'Newest valid comparable annual source wins exactly');
same('18.0000', $readCost('register', 3)['total'], 'Existing evidenced exact-term total is retained without bucket interpolation');
same('2026-10-18T12:00:00+00:00', $readCost('register', 3)['observed_at'], 'Old observation time remains old');
same(null, $readCost('register', 2), 'Ambiguous bucket never creates an exact-term observation');
$report = $converter->report($binding, $before);
same([true, true, true, false], [$report['compatible'], $report['complete'], $report['source_unchanged'], $report['activated']], 'Comparison preserves IDs, native mappings, sellers, annual retail and frozen financial evidence');
same(1, $report['issues']['unproven_bucket_terms'], 'Comparison reports unproven mappings');
same(4, $report['issues']['unknown_facts'], 'Comparison reports undiscovered and undated facts');
$orgRows = DB::table('domains_prices')->where([...$key, 'tld' => 'org'])->get()->keyBy(static fn ($p) => $p->action.':'.$p->term);
same(null, $orgRows['register:1']->catalogue_observation, 'Equal-time conflicting normalized costs remain unknown');
same('14.0000', json_decode($orgRows['register:2']->catalogue_observation, true)['total'], 'An explicitly evidenced annual exact term normalizes without floats');
same('2020-01-01T00:00:00+00:00', json_decode($orgRows['register:2']->catalogue_observation, true)['observed_at'], 'Stale evidence is not refreshed by conversion');
same(null, $orgRows['register:4']->catalogue_observation, 'Unknown units do not acquire a guessed amount');
same(false, json_decode($orgRows['transfer:1']->catalogue_observation, true)['enabled'], 'Explicit dated disabled evidence survives ambiguous fill omissions');
same('19.95', UpgradeComparison::query('domains_tlds')->where('id', $org->id)->sole()->catalogue_retained_annual_retail, 'Missing current cost does not erase last published annual retail');
same(1, $report['issues']['conflicting_price:register:1'], 'Comparison flags conflicting exact-term sources');
same(null, DB::table('domains_tlds')->where([...$key, 'tld' => 'net'])->value('catalogue_facts'), 'Acceptance-only candidates carry no invented facts');
// MySQL's JSON storage may reorder object keys; a comparison must keep types but ignore key order.
$originalIntent = $converted->catalogue_intent;
$reordered = json_decode($originalIntent, true); ksort($reordered);
DB::table('domains_tlds')->where('id', $enom->id)->update(['catalogue_intent' => json_encode($reordered)]);
same(true, $converter->report($binding, $before)['compatible'], 'Equivalent JSON object key order does not change upgrade intent');
DB::table('domains_tlds')->where('id', $enom->id)->update(['catalogue_intent' => '{}']);
$corrupt = $converter->report($binding, $before);
same(false, $corrupt['compatible'], 'Comparison detects incorrect staged intent even when original rows are unchanged');
same(1, $corrupt['staging_mismatches']['intent'], 'Comparison names the staged intent mismatch');
DB::table('domains_tlds')->where('id', $enom->id)->update(['catalogue_intent' => '{']);
$malformed = $converter->report($binding, $before);
same(false, $malformed['compatible'], 'Malformed staged intent rejects comparison without throwing');
same(1, $malformed['staging_mismatches']['intent'], 'Malformed JSON is reported as a staged intent mismatch');
DB::table('domains_tlds')->where('id', $enom->id)->update(['catalogue_intent' => '42']);
$scalar = $converter->report($binding, $before);
same(false, $scalar['compatible'], 'Scalar staged intent rejects comparison without throwing');
same(1, $scalar['staging_mismatches']['intent'], 'Scalar JSON is reported as a staged intent mismatch');
DB::table('domains_tlds')->where('id', $enom->id)->update(['catalogue_intent' => $originalIntent]);
// Compare membership as well as values: each mutation leaves retained source digests untouched.
$coverage = [];
$checkCoverage = static function (string $case) use (&$coverage, $converter, $binding, $before): void {
    $result = $converter->report($binding, $before);
    $coverage[$case] = [$result['compatible'], $result['complete']];
};
DB::table('domains_tlds')->where('id', $enom->id)->update(['catalogue_converted' => false, 'catalogue_intent' => null]);
$checkCoverage('cleared_marker');
DB::table('domains_tlds')->where('id', $enom->id)->update(['catalogue_converted' => true, 'catalogue_intent' => $originalIntent]);
$candidate = (array) DB::table('domains_tlds')->where([...$key, 'tld' => 'net'])->sole();
DB::table('domains_tlds')->where('id', $candidate['id'])->delete();
$checkCoverage('deleted_candidate');
DB::table('domains_tlds')->insert($candidate);
$extraId = DB::table('domains_tlds')->insertGetId([...$key, 'registrar_key' => 'enom', 'tld' => 'extra.example',
    'catalogue_created' => true, 'catalogue_converted' => true,
    'catalogue_intent' => json_encode(new Intent(false, 3, true, '12.0000'))]);
$checkCoverage('extra_identity');
DB::table('domains_tlds')->where('id', $extraId)->delete();
DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->update(['offer_conversion_complete' => false]);
$checkCoverage('completion_cleared');
DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->update(['offer_conversion_complete' => true, 'offer_conversion_cursor' => 'com']);
$checkCoverage('completion_cursor');
DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->update(['offer_conversion_cursor' => 'org']);
same(['cleared_marker' => [false, false], 'deleted_candidate' => [false, false], 'extra_identity' => [false, false],
    'completion_cleared' => [false, false], 'completion_cursor' => [false, false]], $coverage,
    'Incomplete or extra staged offers reject comparison and completion: '.json_encode($coverage));
same(true, $converter->report($binding, $before)['compatible'], 'Restored offer membership matches the completed conversion');
$queue = DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->sole();
same([8, 100, 90, 95, 'transport_failed'], [(int) $queue->offer_feed_run, (int) $queue->offer_feed_tried_at,
    (int) $queue->offer_feed_succeeded_at, (int) $queue->offer_feed_failed_at, $queue->offer_feed_failure], 'Legacy feed-state values survive conversion');
$stagedBefore = DB::table('domains_tlds')->orderBy('id')->get()->toJson();
same(['converted' => 0, 'complete' => true], $converter->batch($binding), 'Completed conversion is idempotent');
same($stagedBefore, DB::table('domains_tlds')->orderBy('id')->get()->toJson(), 'Rerun does not advance revisions or dates');
expect(!array_key_exists('catalogue_defaults', Account::where('server_id', $binding->serverId)->sole()->toArray()), 'Staging defaults stay out of active model serialization');
// Changed legacy data after an interruption/completion must never be silently synchronized.
DB::table(SellTableSchema::ROWS)->where([...$key, 'tld' => 'com'])->update(['choice' => 'skip']);
try { $converter->batch($binding); expect(false, 'Expected changed-source refusal'); }
catch (DomainException $error) { expect(str_contains($error->getMessage(), 'sources changed'), 'Changed-source refusal is explicit'); }
same(false, $converter->report($binding, $before)['compatible'], 'Comparison detects changed original intent');
DB::table(SellTableSchema::ROWS)->where([...$key, 'tld' => 'com'])->update(['choice' => 'sell']);
same(true, $converter->report($binding, $before)['compatible'], 'Restored source matches frozen comparison');
// A rotated binding may not adopt the saved account or retained rows.
$foreign = new Paymenter\Extensions\Servers\Domains\Data\AccountBinding($binding->registrarKey,
    $binding->serverId, $binding->environment, 'synthetic-foreign-binding');
try { $converter->batch($foreign); expect(false, 'Expected wrong-binding refusal'); }
catch (Paymenter\Extensions\Servers\Domains\Data\AccountBindingMismatch $error) { expect(str_contains($error->getMessage(), 'binding'), 'Wrong-binding refusal is explicit'); }
// Without a saved default, accepted intent fills only missing overrides on the other registrar.
$other = CatalogueAcceptances::binding(Account::where('server_id', $namesilo->server_id)->sole());
same(true, $converter->batch($other)['complete'], 'Annual-only registrar uses the same conversion');
$otherIntent = json_decode(DB::table('domains_tlds')->where('id', $namesilo->id)->value('catalogue_intent'), true);
same([false, true, 1, true], [$otherIntent['defaultSell'], $otherIntent['sell'], $otherIntent['longest'], $otherIntent['transfers']], 'Legacy acceptance supplies missing intent without acting as facts');
same([], $otherIntent['stops'], 'Derived action flags do not become explicit staff stops');
// Separate fresh, prefixed database; all native/extension queries must respect its prefix.
// Native migration history contains unprefixed data SQL, so reuse its clean table definitions;
// test the Domains installer with a prefix without pretending to qualify native migration SQL.
$nativeTables = DB::connection()->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'domains_%' AND name NOT LIKE 'sqlite_%'");
config(['database.connections.offer_prefix' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'b2_', 'foreign_key_constraints' => false]]);
DB::setDefaultConnection('offer_prefix'); Schema::swap(DB::connection()->getSchemaBuilder());
$migrator->setConnection('offer_prefix');
foreach ($nativeTables as $definition) {
    $sql = preg_replace('/^(CREATE TABLE )([\"`]?)([a-z_][a-z0-9_]*)([\"`]?)/i', '$1"b2_'.$definition->name.'"', $definition->sql, 1);
    DB::statement($sql);
}
(new Installation)->install();
$freshAccount = fixtureAccount(1, 'namesilo', 'synthetic-prefixed');
$freshKey = ['server_id' => 1, 'account_fingerprint' => $freshAccount['account_fingerprint']];
Server::findOrFail(1)->settings()->create(['key' => SellRule::KEY, 'type' => 'array', 'encrypted' => false,
    'value' => ['mode' => 'all', 'max_cost' => null, 'years' => 1, 'transfers' => false]]);
$freshBinding = CatalogueAcceptances::binding(Account::where('server_id', 1)->sole());
$rows = [];
for ($i = 0; $i < 1007; $i++) { $rows[] = [...$freshKey, 'tld' => 'suffix'.chr(97 + intdiv($i, 676)).chr(97 + intdiv($i % 676, 26)).chr(97 + $i % 26).'.example']; }
foreach (array_chunk($rows, 100) as $chunk) { DB::table(SellTableSchema::ROWS)->insert($chunk); }
DB::table(SellTableSchema::ROWS)->where('tld', 'suffixaaa.example')->update(['choice' => 'skip']);
$freshBefore = UpgradeComparison::capture(); $convertedCount = 0;
do { $part = $converter->batch($freshBinding, 250); $convertedCount += $part['converted']; } while (!$part['complete']);
same(1007, $convertedCount, 'Bounded prefixed conversion reaches every offer beyond one thousand');
same(1007, DB::table('domains_tlds')->count(), 'Discovered offers get one permanent candidate each');
$allIntent = json_decode(DB::table('domains_tlds')->where('tld', 'suffixaaa.example')->value('catalogue_intent'), true);
same([true, null], [$allIntent['defaultSell'], $allIntent['sell']], 'Legacy all mode continues to ignore old skip rows');
same([], $allIntent['stops'], 'Created disabled candidates do not invent staff stops');
same(0, DB::table('domains_tlds')->where('enabled', true)->count(), 'Discovered candidates do not activate old sales');
same(true, $converter->report($freshBinding, $freshBefore)['compatible'], 'Prefixed fresh install preserves original rows through conversion');
same(['converted' => 0, 'complete' => true], $converter->batch($freshBinding), 'Large conversion rerun is idempotent');
(new Installation)->install(); OfferSchema::verify();
$nativeSuccessful = true;
echo "Offer conversion checks passed.\n";
