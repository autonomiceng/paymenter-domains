<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{UpgradeGate, UpgradeComparison};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Data\CatalogueRate;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, CardFee, Installation, PricingRule, PricingRules, SellRule};

// Native transactions and retained projections are required to prove a quiet conversion window;
// this fixture has synthetic accounts and no installed provider transport.
(new Installation)->install();
extract(commerceFixture());
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

$nativeSuccessful = true;
echo "Offer upgrade gate checks passed.\n";
