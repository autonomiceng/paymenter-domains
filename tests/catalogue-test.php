<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/accounts.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\CatalogueRate;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Price, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema, HolderPricing, PriceCatalogue, PricingRule, PricingRules};
FoundationSchema::install();
$a = fixtureAccount(1, 'enom', 'account-a'); $b = fixtureAccount(2, 'namesilo', 'account-b');
foreach (['com', 'net', 'org'] as $ending) { Tld::create([...$a, 'tld' => $ending, 'enabled' => false, 'renew_enabled' => true]); }
Tld::create([...$b, 'tld' => 'com', 'renew_enabled' => true]);
$now = new DateTimeImmutable('2026-10-18T12:00:00Z');
$rate = static fn (string $action, int $term, ?string $price): CatalogueRate => new CatalogueRate($action, $term, $price, 'synthetic', $now, $now->modify('+1 day'));
$rules = new PricingRules; $catalogue = new PriceCatalogue($rules); $holder = new HolderPricing($catalogue);
$reject = static function (callable $call): void {
    try { $call(); expect(false, 'Pricing guard escaped'); } catch (DomainException|InvalidArgumentException|LogicException $e) { /* expected */ }
};
// Count the default-setting SELECT, one of the reads made by each rule resolution.
$withRuleReads = static function (callable $call, int $expected, string $label): mixed {
    DB::flushQueryLog(); DB::enableQueryLog();
    try { $result = $call(); }
    finally { $queries = DB::getQueryLog(); DB::disableQueryLog(); DB::flushQueryLog(); }
    $reads = array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'select ')
        && in_array('domains_pricing_default_rule', $query['bindings'], true));
    same($expected, count($reads), $label);
    return $result;
};
$read = static fn (Tld $tld): array => [$rate('register', 1, '10.0000'), $rate('register', 3, '8.0000'), $rate('renew', 1, '12.0000'), $rate('transfer', 1, '9.0000')];
same(['synced' => 3, 'failed' => []], $withRuleReads(static fn () => $catalogue->sync(1, $read), 3,
    'Sync resolves one rule per locked TLD across four rates'), 'Non-selling holders still sync');
$catalogue->sync(2, static fn (): array => [$rate('renew', 1, '5.0000')]);
$com = Tld::where('server_id', 1)->where('tld', 'com')->firstOrFail();
$assertPublishedRule = static function (Tld $tld, array $rule) use ($read): void {
    $hash = hash('sha256', json_encode($rule, JSON_THROW_ON_ERROR));
    foreach ($read($tld) as $rate) {
        $price = Price::where('server_id', $tld->server_id)->where('tld', $tld->tld)
            ->where('action', $rate->action)->where('term', $rate->term)->firstOrFail();
        same($hash, $price->eligibility['rule_hash'], 'Every rate hashes the resolved rule');
        same(PricingRule::total(PricingRule::price($rate->perYear, $rule, $rate->action), $rate->term),
            PriceCatalogue::retail($price->retail), 'Every rate is priced from that same rule');
    }
};
foreach (Tld::where('server_id', 1)->get() as $tld) { $assertPublishedRule($tld, PricingRule::DEFAULT); }
$withRuleReads(static fn () => $catalogue->republish($com), 1, 'Republication resolves one rule across four rates');
$assertPublishedRule($com, PricingRule::DEFAULT);
$withRuleReads(static fn () => DB::transaction(static function () use ($catalogue, $com): void {
    App\Models\Server::whereKey($com->server_id)->lockForUpdate()->firstOrFail();
    Account::where('server_id', $com->server_id)->lockForUpdate()->firstOrFail();
    $locked = Tld::whereKey($com->id)->lockForUpdate()->firstOrFail();
    $catalogue->republishLocked($locked);
}), 1, 'Locked republication resolves one rule across four rates');
$assertPublishedRule($com, PricingRule::DEFAULT);
$p = $withRuleReads(static fn () => $catalogue->current(1, $a['account_fingerprint'], 'com', 'register', 3, 'USD', $now),
    1, 'Current hashes its own single rule resolution');
same('24.0000', $p->wholesale, 'Selected-term wholesale total'); same('31.20', $p->retail, 'Selected-term annual retail rounded then totalled');
same('term_total', Price::findOrFail($p->id)->unit, 'Explicit normalized total unit');
same('exact_term_annual_rate', Price::findOrFail($p->id)->eligibility['source_unit'], 'Source units retained');
expect(!str_contains(json_encode($p, JSON_THROW_ON_ERROR), $a['account_fingerprint']), 'Price JSON hides binding');
expect(!str_contains(print_r($p, true), 'wholesale'), 'Price debug hides cost');
$reject(static fn () => serialize($p));
$domain = Domain::create([...$a, 'service_id' => 11, 'user_id' => 21, 'domain' => 'example.com', 'sld' => 'example', 'tld' => 'com', 'status' => 'active']);
same('15.60', $holder->renewal($domain, 'USD', $now)->retail, 'Holding account, never cheaper account renewal');
$frozen = $holder->renewal($domain, 'USD', $now);
$before = Price::where('server_id', 1)->where('tld', 'com')->orderBy('id')->get()->toJson();
$summary = $catalogue->sync(1, static function (Tld $tld) use ($rate): array {
    if ($tld->tld === 'com') { throw new RuntimeException('Provider unavailable'); }
    return [$rate('renew', 1, '14.0000')];
});
same(['synced' => 2, 'failed' => ['.com']], $summary, 'Read failure reports and continues');
same($before, Price::where('server_id', 1)->where('tld', 'com')->orderBy('id')->get()->toJson(), 'Failed TLD retains every price byte/revision/freshness');
$summary = $catalogue->sync(1, static fn (): array => [$rate('renew', 1, '13.0000'), $rate('renew', 1, '14.0000')]);
same(0, $summary['synced'], 'Duplicate malformed response retains prior costs');
$catalogue->sync(1, static fn (): array => [$rate('register', 3, null), $rate('renew', 1, '20.0000')]);
$reject(static fn () => $catalogue->current(1, $a['account_fingerprint'], 'com', 'register', 3, 'USD', $now));
same('10.0000', $catalogue->current(1, $a['account_fingerprint'], 'com', 'register', 1, 'USD', $now)->wholesale, 'Omitted terms retained');
same('15.60', $frozen->retail, 'Frozen old obligation amount immutable');
expect($holder->renewal($domain, 'USD', $now)->revision > $frozen->revision, 'Refresh advances revision');
$fixed = [...PricingRule::DEFAULT, 'mode' => 'fixed', 'fixed' => ['register' => '1.00', 'renew' => '1.00', 'transfer' => '1.00']];
$rules->saveDefaultRule(1, $fixed, $catalogue->republish(...));
same('1.00', $holder->renewal($domain, 'USD', $now)->retail, 'Below-cost holder renewals still priced');
same('below_cost', $holder->renewal($domain, 'USD', $now)->staffAlert, 'Below-cost renewal staff alert');
$reject(static fn () => $catalogue->current(1, $a['account_fingerprint'], 'com', 'register', 1, 'USD', $now));
same(true, $holder->renewal($domain, 'USD', $now->modify('+2 days'))->retained, 'Stale cost retains billing retail');
same('1.00', $holder->renewal($domain, 'USD', $now->modify('+2 days'))->retail, 'Outage never stops existing retail billing');
$reject(static fn () => $holder->renewal($domain, 'EUR', $now));
$reject(static fn () => $rate('transfer', 2, '1.0000'));
$reject(static fn () => $rate('renew', 2, '1.0000'));
$reject(static fn () => DB::transaction(static fn () => $catalogue->sync(1, $read)));
$reject(static fn () => DB::transaction(static fn () => $catalogue->republish(Tld::where('server_id', 1)->firstOrFail())));
$revision = $holder->renewal($domain, 'USD', $now)->revision;
$older = new CatalogueRate('renew', 1, '1.0000', 'synthetic', $now->modify('-1 hour'), $now->modify('+1 day'));
same(0, $catalogue->sync(1, static fn (): array => [$older])['synced'], 'Older in-flight read cannot overwrite newer observation');
same($revision, $holder->renewal($domain, 'USD', $now)->revision, 'Stale read keeps revision');
// Option E refuses every dirty manual price, regardless of transaction or quote freshness.
$saveRefusal = static function (callable $call, string $message = 'Managed pricing authority requires a dedicated audited transition.'): void {
    try { $call(); } catch (DomainException $e) { same($message, $e->getMessage(), 'Exact compatibility save refusal'); return; }
    expect(false, 'Expected compatibility save refusal');
};
$service = new App\Models\Service(['product_id' => 101, 'plan_id' => 102, 'user_id' => 21, 'quantity' => 1, 'currency_code' => 'USD', 'price' => '1.00']);
$service->id = 11; $service->exists = true; $service->syncOriginal();
$service->setRelation('product', new App\Models\Product(['server_id' => 1]));
$holder->assertSave($service, $domain->fresh(), $now);
$service->price = '10.00'; $saveRefusal(static fn () => $holder->assertSave($service, $domain->fresh(), $now));
$saveRefusal(static fn () => $holder->assertSave($service, $domain->fresh(), $now->modify('+2 days')));
$service->price = '1.00'; $holder->assertSave($service, $domain->fresh(), $now->modify('+2 days'));
$service->price = '9.00'; $service->syncOriginal(); $service->status = 'cancelled';
$holder->assertSave($service, $domain->fresh(), $now); // unchanged old retail must not block lifecycle saves
$nonstandard = $domain->fresh(); $nonstandard->price_mode = 'complimentary';
$holder->assertSave($service, $nonstandard, $now);
$service->price = '1.00'; $saveRefusal(static fn () => $holder->assertSave($service, $domain->fresh(), $now));
$service->plan_id = 103; $saveRefusal(static fn () => $holder->assertSave($service, $domain->fresh(), $now), 'Managed service binding is permanent.');
App\Models\Server::findOrFail(1)->update(['enabled' => false]);
same('1.00', $holder->renewal($domain, 'USD', $now)->retail, 'Disabled native seller retains holder price access');
same(0, $catalogue->sync(1, $read)['synced'], 'Native disabled server is uniformly read-only');
App\Models\Server::findOrFail(1)->update(['enabled' => true]);
$catalogue->sync(1, static fn (): array => [$rate('renew', 1, null)]);
$unknown = Price::where('server_id', 1)->where('tld', 'com')->where('action', 'renew')->firstOrFail();
same(null, $unknown->wholesale, 'Explicit no-price records unknown wholesale');
same('1.00000000', $unknown->retail, 'Unknown renewal keeps prior published retail');
same(null, $unknown->hold_reason, 'Unknown renewal creates no financial hold');
$retained = $holder->renewal($domain, 'USD', $now);
same(true, $retained->retained, 'Unknown cost retains billing authority');
same('unknown_price', $retained->staffAlert, 'Retained unknown renewal alerts staff');
same('1.00', $retained->retail, 'Unknown cost never cancels published renewal retail');
$reject(static fn () => serialize($retained));
$service->plan_id = 102; $service->syncOriginal(); $service->price = '2.00';
$saveRefusal(static fn () => $holder->assertSave($service, $domain->fresh(), $now));
$service->price = '1.00'; $service->syncOriginal(); $service->status = 'suspended';
Tld::where('server_id', 1)->where('tld', 'com')->firstOrFail()->update(['use_default_rule' => false, 'pricing_mode' => 'invalid']);
$holder->assertSave($service, $domain->fresh(), $now);
$unknown->wholesale = '12.0000'; $unknown->revision++; $unknown->save();
same(true, $holder->renewal($domain, 'USD', $now)->retained, 'Invalid current rule preserves published renewal');
$service->price = '2.00'; $saveRefusal(static fn () => $holder->assertSave($service, $domain->fresh(), $now));
same(0, App\Models\Invoice::count(), 'No billing/invoice mutation in catalogue lane');
$nativeSuccessful = true;
echo "Catalogue persistence, retained read failures, holder revisions and reprice contract passed.\n";
