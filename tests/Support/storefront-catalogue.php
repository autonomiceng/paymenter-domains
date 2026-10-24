<?php
// Catalogue scenarios adapted from Enom 9fc07a1/6119f1c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Product, Server};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueEntry, CatalogueRate, Environment};
use Paymenter\Extensions\Servers\Domains\Models\{Price, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, CheckoutEligibility, Rdap, Storefront, TransferPrecheck};

/** A separate synthetic account keeps catalogue mutations isolated from other storefront scenarios. */
function storefrontCatalogueFixture(array $fixture, array $endings, array $terms): array
{
    extract($fixture);
    $server = Server::create(['name' => 'Catalogue fixture', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
    $binding = $bindings->configure($server->id, 'enom', Environment::Test, ['token' => 'catalogue-fixture-'.$server->id]);
    $bindings->setProcessing($server->id, true, syntheticActivation());
    $rows = array_fill_keys($endings, ['register' => $terms, 'renew' => [1], 'transfer' => [1], 'registry' => true, 'renewal_safe' => true]);
    $accepted = CatalogueAcceptances::save($binding, 0, $rows); $catalogueTlds = [];
    foreach ($endings as $ending) {
        $tld = Tld::create(['server_id' => $server->id, 'registrar_key' => 'enom', 'account_fingerprint' => $binding->fingerprint,
            'tld' => $ending, 'enabled' => true, 'min_years' => min($terms), 'max_years' => max($terms),
            'use_default_rule' => false, 'pricing_mode' => 'fixed', 'fixed_register' => '20.00', 'fixed_renew' => '21.00', 'fixed_transfer' => '22.00']);
        $rates = [new CatalogueRate('renew', 1, '8.00', 'synthetic', $now, $now->modify('+1 day')),
            new CatalogueRate('transfer', 1, '7.00', 'synthetic', $now, $now->modify('+1 day'))];
        foreach ($terms as $term) { $rates[] = new CatalogueRate('register', $term, '6.00', 'synthetic', $now, $now->modify('+1 day')); }
        $prices->import(new CatalogueEntry($binding, $ending, $rates, $terms, true, true), $accepted, static fn () => null, $now);
        $native->publish($tld->id, $now); $catalogueTlds[$ending] = $tld->fresh();
        $policy = $sellers->initialize($ending); $sellers->change($ending, $policy->revision, $server->id, true, true, $now);
    }
    return compact('server', 'binding', 'catalogueTlds');
}

function storefrontCorruptionChecks(array $fixture, Storefront $store): void
{
    extract($fixture);
    extract(storefrontCatalogueFixture($fixture, ['net', 'org', 'test', 'old', 'belowcost', 'unknown'], [1, 3]));
    $broken = $native->product($catalogueTlds['org'], 'register');
    $marker = $broken->settings()->where('key', 'domains_action')->sole();
    $brokenPrices = $broken->plans()->with('prices')->get()->flatMap(static fn ($plan) => $plan->prices)->pluck('id');
    // Valid registration survives an independently corrupt transfer price on the same ending.
    $onlyTransfer = $native->product($catalogueTlds['test'], 'transfer')->plans()->sole()->prices()->sole();
    $transferPrice = $onlyTransfer->getRawOriginal('price');
    DB::table('prices')->where('id', $onlyTransfer->id)->update(['price' => 'malformed']);
    foreach (['old' => ['fresh_until' => $now], 'belowcost' => ['hold_reason' => 'below_cost'], 'unknown' => ['wholesale' => null]] as $ending => $changes) {
        DB::table('domains_prices')->where('server_id', $server->id)->where('tld', $ending)->whereIn('action', ['register', 'transfer'])->update($changes);
    }
    // Withdraw the other action so each corrupt registration must remove its ending entirely.
    $native->product($catalogueTlds['org'], 'transfer')->update(['stock' => 0]);
    foreach (['price', 'setup_fee', 'marker', 'duplicate_product'] as $corruption) {
        if ($corruption === 'price') { DB::table('prices')->whereIn('id', $brokenPrices)->update(['price' => 'malformed']); }
        if ($corruption === 'setup_fee') { DB::table('prices')->whereIn('id', $brokenPrices)->update(['setup_fee' => null]); }
        if ($corruption === 'marker') { $marker->update(['value' => 'invalid']); }
        if ($corruption === 'duplicate_product') {
            $duplicateProduct = $broken->replicate(); $duplicateProduct->save();
            foreach ($broken->settings()->get() as $setting) { $duplicateProduct->settings()->create($setting->only(['key', 'value', 'type', 'encrypted'])); }
        }
        Cache::flush(); $before = $reads->calls; $public = array_column($store->endings(), null, 'tld');
        same(['com', 'net', 'test'], array_keys($public), 'Corrupt '.$corruption.' isolates broken, old, below-cost and unknown offers');
        same(['tld' => 'net', 'price' => '$20.00', 'register' => '$20.00', 'transfer' => '$22.00', 'renew' => '$21.00', 'terms' => [1, 3], 'testEnvironment' => true],
            $public['net'], 'Corrupt '.$corruption.' preserves exact healthy row');
        same(null, $public['test']['transfer'], 'Corrupt transfer never leaks a price');
        same([1, 3], $public['test']['terms'], 'Registration-only ending retains exact terms');
        same($before, $reads->calls, 'Catalogue isolation performs no provider I/O');
        foreach (['net', 'test'] as $ending) {
            $result = $store->check('example.'.$ending);
            same('available', $result['status'], 'Corrupt '.$corruption.' preserves healthy check for '.$ending);
            same([1 => '$20.00', 3 => '$60.00'], $result['terms'], 'Healthy check retains exact prices');
            same('$21.00', $result['renew'], 'Healthy check retains annual renewal');
        }
        $before = $reads->calls;
        foreach (['org', 'old', 'belowcost', 'unknown'] as $ending) {
            same('held', $store->check('example.'.$ending)['status'], 'Corrupt '.$corruption.' never revives '.$ending);
        }
        same($before, $reads->calls, 'Unorderable endings stop before availability');
        DB::table('prices')->whereIn('id', $brokenPrices)->update(['price' => DB::raw('20 * (select billing_period from plans where plans.id = prices.plan_id)'), 'setup_fee' => '0.00']);
        $marker->update(['value' => 'register']);
        if ($corruption === 'duplicate_product') { $duplicateProduct->settings()->delete(); $duplicateProduct->delete(); }
        Cache::flush(); $repaired = array_column($store->endings(), null, 'tld');
        same([1, 3], $repaired['org']['terms'], 'Repaired '.$corruption.' is visible in a fresh projection');
        same('available', $store->check('example.org')['status'], 'Repaired '.$corruption.' checks normally');
    }
    DB::table('prices')->where('id', $onlyTransfer->id)->update(['price' => $transferPrice]);
    Cache::flush(); $repaired = array_column($store->endings(), null, 'tld');
    same('$22.00', $repaired['test']['transfer'], 'Restored test transfer price is visible');
    foreach (array_keys($catalogueTlds) as $ending) { $sellers->change($ending, 2, null, false, false, $now); }
    Cache::flush();
}
