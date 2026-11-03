<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use App\Models\{Invoice, InvoiceItem, InvoiceTransaction, Order, Server, Service, Setting};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{UpgradeGate, UpgradeComparison};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CatalogueRate};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, CardFee, Installation, PricingRule, PricingRules, PriceFill, SellRule, SellTableSchema};

// Native transactions and retained projections are required to prove a quiet conversion window;
// this fixture has synthetic accounts and no installed provider transport.
(new Installation)->install();
extract(commerceFixture());
$clock = new class($now) implements Clock {
    public function __construct(private DateTimeImmutable $time) {}
    public function now(): DateTimeImmutable { return $this->time; }
};
// Pending/paid/uncertain evidence must survive conversion byte-for-byte; it grants no new send.
$owner = commerceUser(); $product = $native->product($tlds[0], 'register');
$order = Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
$service = Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $product->id,
    'plan_id' => $product->plans()->where('billing_period', 1)->sole()->id, 'quantity' => 1,
    'price' => '10.40', 'currency_code' => 'USD', 'status' => 'active']);
$domain = DB::table('domains_domains')->insertGetId(['server_id' => $tlds[0]->server_id,
    'account_fingerprint' => $tlds[0]->account_fingerprint, 'registrar_key' => 'enom', 'service_id' => $service->id,
    'user_id' => $owner->id, 'domain' => 'example.com', 'reservation_name' => 'example.com', 'sld' => 'example',
    'tld' => 'com', 'status' => 'active', 'snapshot' => 'synthetic frozen snapshot']);
foreach (['awaiting_payment', 'queued', 'uncertain'] as $i => $state) {
    $invoice = Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => $i === 0 ? 'pending' : 'paid']);
    $item = InvoiceItem::create(['invoice_id' => $invoice->id, 'reference_type' => $service->getMorphClass(),
        'reference_id' => $service->id, 'description' => 'Synthetic retained obligation', 'quantity' => 1, 'price' => '10.40']);
    if ($i > 0) { InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => '10.40', 'fee' => '0.00', 'status' => 'succeeded']); }
    DB::table('domains_operations')->insert(['server_id' => $tlds[0]->server_id, 'registrar_key' => 'enom',
        'account_fingerprint' => $tlds[0]->account_fingerprint, 'domain_id' => $domain, 'service_id' => $service->id,
        'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'operation_key' => 'synthetic-upgrade-'.$i,
        'action' => 'renew', 'state' => $state, 'amount' => '10.40', 'currency' => 'USD',
        'cost' => 'synthetic frozen ceiling', 'attempt' => 'synthetic frozen attempt', 'funding' => 'synthetic frozen funding']);
}

$setting = Setting::create(['key' => 'domains_offer_upgrade', 'settingable_type' => null,
    'settingable_id' => null, 'type' => 'string', 'encrypted' => true,
    'value' => json_encode(['state' => 'paused', 'generation' => 1])]);
$refused = false;
try { $native->publish($tlds[0]->id, $now); }
catch (DomainException $error) { $refused = str_contains($error->getMessage(), 'Catalogue upgrade'); }
same(true, $refused, 'Quiet conversion window refuses legacy native publication');
same(1, UpgradeGate::generation(), 'Quiet window generation is durable across service instances');
$reject = static function (callable $call, string $message): void {
    $refused = false;
    try { $call(); }
    catch (DomainException $error) { $refused = str_contains($error->getMessage(), 'Catalogue upgrade'); }
    same(true, $refused, $message);
};
$reject(fn () => $prices->republish($tlds[0]), 'Quiet conversion window refuses legacy price republication');
$b = CatalogueAcceptances::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
$n = CatalogueAcceptances::binding(Account::where('server_id', $tlds[1]->server_id)->sole());
$accepted = CatalogueAcceptances::load($b);
$reject(fn () => CatalogueAcceptances::save($b, $accepted->revision, $accepted->endings), 'Quiet window preserves legacy acceptance');
$reject(fn () => $sellers->change('com', 2, $n->serverId, true, true, $now), 'Quiet window preserves selected sellers');
$reject(fn () => (new PricingRules)->saveDefaultRule($b->serverId, PricingRule::DEFAULT, static fn () => null), 'Quiet window preserves account pricing defaults');
$reject(fn () => DB::transaction(fn () => CardFee::saveLocked($b->serverId, ['percent' => '1.00', 'fixed' => '0.10'])), 'Quiet window preserves account fees');
$reject(fn () => DB::transaction(fn () => SellRule::applyLocked(Account::where('server_id', $b->serverId)->sole(), $b, $accepted,
    ['rule' => ['mode' => 'except', 'max_cost' => null, 'years' => 1, 'transfers' => false]])), 'Quiet window preserves explicit staff intent');

$gate = new UpgradeGate;
$evidence = $gate->capture();
same(false, $gate->check($evidence)['compatible'], 'Unconverted retained offers cannot pass the gate');
$gate->resume();
$native->publish($tlds[0]->id, $now);
same(2, UpgradeGate::generation(), 'Rollback resumes legacy writers without dropping staging storage');
// A read that started before maintenance cannot save after rollback has reopened legacy work.
$pricesBefore = DB::table('domains_prices')->orderBy('id')->get()->toJson();
$result = $prices->sync($b->serverId, static function () use ($gate, $now): array {
    same(0, DB::transactionLevel(), 'Legacy provider callback runs without locks');
    $gate->pause(); $gate->resume();
    return [new CatalogueRate('register', 1, '99.0000', 'synthetic_late_read', $now, $now->modify('+1 day'))];
});
same(0, $result['synced'], 'An in-flight legacy read is fenced across pause and rollback');
same($pricesBefore, DB::table('domains_prices')->orderBy('id')->get()->toJson(), 'Superseded reads preserve saved price evidence');


// Scale is real persisted source membership, not 1,007 copies of a mapper unit assertion.
$records = [];
for ($i = 0; $i < 1007; $i++) {
    $name = 'suffix'.chr(97 + intdiv($i, 26 * 26)).chr(97 + intdiv($i, 26) % 26).chr(97 + $i % 26);
    $records[] = ['server_id' => $b->serverId, 'account_fingerprint' => $b->fingerprint, 'tld' => $name, 'choice' => 'skip'];
}
foreach (array_chunk($records, 200) as $chunk) { DB::table(SellTableSchema::ROWS)->insert($chunk); }
$gate->pause(); $evidence = $gate->capture();
// The capture belongs to the complete account set and this exact quiet-window generation.
$foreign = new AccountBinding('enom', $b->serverId, $b->environment, 'synthetic-other-account');
commerceReject(fn () => $gate->batch($foreign, $evidence));
$reject(fn () => $gate->batch($b, ['generation' => -1, ...array_diff_key($evidence, ['generation' => true])]), 'An earlier capture cannot be reused in another quiet window');
foreach ([$b, $n] as $binding) {
    do { $batch = $gate->batch($binding, $evidence, 500); } while (!$batch['complete']);
    same(['converted' => 0, 'complete' => true], $gate->batch($binding, $evidence), 'A checked conversion rerun is idempotent');
}
$obligationQueries = 0; $countQueries = true;
DB::listen(static function ($query) use (&$obligationQueries, &$countQueries): void {
    if ($countQueries && str_starts_with($query->sql, 'select * from "invoice_items"')) { $obligationQueries++; }
});
$report = $gate->check($evidence); $countQueries = false;
same([true, true, 2, 1009, false, 'caller_cutover_pending'],
    [$report['compatible'], $report['complete'], $report['accounts'], $report['offers'], $report['activated'], $report['activation_blocked']],
    'Complete checked conversion preserves obligations while activation waits for all callers');
same(1, $obligationQueries, 'Global frozen obligations are compared once across all accounts');
same(1009, $report['issues']['unknown_facts'], 'Approved unknown current-offer facts remain visible, never inferred');
same(true, UpgradeComparison::compare($evidence['before'])['compatible'], 'IDs, native mappings, pending/paid/uncertain evidence and retained retail survive');
// Conversion cannot be pre-published or supplied invented facts while old callers remain active.
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => '{}']);
same(false, $gate->check($evidence)['compatible'], 'Unreviewed staged facts refuse the conversion gate');
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => null]);
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_published_revision' => '{}']);
same(false, $gate->check($evidence)['compatible'], 'Premature publication refuses the conversion gate');
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_published_revision' => null]);
$reject(fn () => (new PriceFill($bindings, $clock))->daily(Account::where('server_id', $b->serverId)->sole()), 'Quiet window refuses the legacy fill scheduler');
DB::table('domains_operations')->where('operation_key', 'synthetic-upgrade-2')->update(['cost' => 'changed frozen ceiling']);
same(['domains_operations'], $gate->check($evidence)['changed'], 'A changed uncertain-operation ceiling blocks the comparison');
DB::table('domains_operations')->where('operation_key', 'synthetic-upgrade-2')->update(['cost' => 'synthetic frozen ceiling']);
DB::table('domains_observation_queues')->where('server_id', $b->serverId)->update(['catalogue_claim' => str_repeat('a', 32)]);
$reject(fn () => $gate->check($evidence), 'Even an expired legacy claim must drain before comparison');
DB::table('domains_observation_queues')->where('server_id', $b->serverId)->update(['catalogue_claim' => null]);
DB::table('domains_observation_queues')->where('server_id', $b->serverId)->update(['offer_claim' => str_repeat('b', 32)]);
$reject(fn () => $gate->check($evidence), 'The new worker cannot claim shared rows during conversion');
DB::table('domains_observation_queues')->where('server_id', $b->serverId)->update(['offer_claim' => null]);
$account = Account::where('server_id', $b->serverId)->sole();
DB::table('domains_accounts')->where('id', $account->id)->update(['account_fingerprint' => str_repeat('c', 64)]);
commerceReject(fn () => $gate->check($evidence));
DB::table('domains_accounts')->where('id', $account->id)->update(['account_fingerprint' => $b->fingerprint]);
same(true, $gate->check($evidence)['compatible'], 'Restoring the exact source restores conversion compatibility');
$gate->resume();
$reject(fn () => $gate->check($evidence), 'Rollback invalidates the checked window rather than granting activation');
// The old reader and writer remain usable with converted fields present.
$native->publish($tlds[0]->id, $now);
same('$7.80', (new Paymenter\Extensions\Servers\Domains\Services\Storefront($sellers, $prices, $native, $bindings,
    new Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility($bindings, $clock), $clock))->offer('com', 'register', 1)['price'],
    'Pre-activation rollback keeps the legacy public/native offer usable');
$nativeSuccessful = true;
echo "Offer upgrade gate checks passed.\n";
