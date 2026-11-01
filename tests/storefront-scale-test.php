<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native Eloquent product/settings/plan loading is the regression; pure rule tests cannot cover it.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
use App\Models\{Plan, Product};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, StorefrontController};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, SalesPolicy, SellRow, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, CheckoutEligibility, NativeCatalogue, PslReviewSchema, Storefront, WatchSchema};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

extract(commerceFixture(true));
$names = [];
foreach (range('a', 'z') as $a) {
    foreach (range('a', 'z') as $b) {
        $names[] = 'scale'.$a.$b;
        if (count($names) === 599) { break 2; }
    }
}
$base = $tlds[0]; $binding = CatalogueAcceptances::binding(Account::where('server_id', $base->server_id)->sole());
$accepted = CatalogueAcceptances::load($binding); $ends = [];
foreach ($names as $name) { $ends[$name] = ['register' => [1], 'renew' => [1], 'transfer' => [1], 'registry' => true, 'renewal_safe' => true]; }
$accepted = CatalogueAcceptances::save($binding, $accepted->revision, $ends);
$templates = [];
foreach (['register', 'transfer'] as $action) {
    $product = $native->product($base, $action); $plan = $product->plans()->where('billing_period', 1)->sole();
    $templates[$action] = [$product, $product->settings()->get(), $plan, $plan->prices()->sole()];
}
$priceTemplates = Price::where('server_id', $base->server_id)->where('term', 1)->get();
DB::transaction(function () use ($names, $base, $templates, $priceTemplates, $accepted, $now): void {
    foreach ($names as $name) {
        $tld = $base->replicate(); $tld->tld = $name; $tld->min_years = $tld->max_years = 1;
        // The rehearsal bulk edit turned TLD transfers off while the seller remained selected.
        $tld->transfer_enabled = false; $tld->save();
        SellRow::create(['server_id' => $tld->server_id, 'account_fingerprint' => $tld->account_fingerprint, 'tld' => $name,
            'choice' => 'sell', 'longest' => 1, 'transfer' => false, 'register_cost' => '6.0000', 'renew_cost' => '8.0000',
            'transfer_cost' => '7.0000', 'high_cost' => '8.0000', 'prices' => [1 => ['register' => '6.0000', 'renew' => '8.0000', 'transfer' => '7.0000']], 'read_at' => $now->getTimestamp()]);
        foreach ($priceTemplates as $template) {
            $price = $template->replicate(); $price->tld = $name;
            $proof = $price->eligibility; $proof['catalogue_revision'] = $accepted->revision; $proof['accepted_terms'] = [1];
            $price->eligibility = $proof; $price->save();
        }
        foreach ($templates as $action => [$template, $settings, $plan, $nativePrice]) {
            $product = $template->replicate(); $product->slug = NativeCatalogue::slug($tld, $action);
            $product->name = ucfirst($action).' .'.$name; $product->save();
            foreach ($settings as $templateSetting) {
                $setting = $templateSetting->replicate(); $setting->settingable_id = $product->id;
                if ($setting->key === 'domains_tld_id') { $setting->value = (string) $tld->id; }
                $setting->save();
            }
            $copy = $plan->replicate(); $copy->priceable_id = $product->id; $copy->save();
            $price = $nativePrice->replicate(); $price->plan_id = $copy->id; $price->save();
        }
        SalesPolicy::withPolicyWrite(fn () => SalesPolicy::create(['tld' => $name, 'revision' => 1, 'server_id' => $base->server_id,
            'registrar_key' => 'enom', 'account_fingerprint' => $base->account_fingerprint, 'register_enabled' => true, 'transfer_enabled' => true]));
    }
});
// Discard only the seed catalogue in this disposable memory database, leaving the exact scale shape.
DB::table('domains_sales_policies')->where('tld', 'com')->delete();
DB::table('products')->whereNotIn('slug', array_merge(
    array_map(fn ($name) => 'domains-enom-s'.$base->server_id.'-'.$name.'-registration', $names),
    array_map(fn ($name) => 'domains-enom-s'.$base->server_id.'-'.$name.'-transfer', $names)))->delete();
DB::table('plans')->whereNotIn('priceable_id', Product::pluck('id'))->delete();
DB::table('prices')->whereNotIn('plan_id', Plan::pluck('id'))->delete();
DB::table('domains_prices')->where('tld', 'com')->delete(); DB::table('domains_tlds')->where('tld', 'com')->delete();
PslReviewSchema::install(); WatchSchema::install();
$clock = new FakeClock($now);
$store = new Storefront($sellers, $prices, $native, $bindings, new CheckoutEligibility($bindings, $clock), $clock);
app()->instance(Storefront::class, $store); app()->instance(Clock::class, $clock);
require dirname(__DIR__).'/Domains/routes/web.php'; require dirname(__DIR__).'/Domains/routes/owned.php';
storefrontViews(); $user = commerceUser(); Auth::login($user);
$controller = new StorefrontController($store); $owned = app(OwnedDomainController::class);
same([599, 599, 599, 1797, 1198, 1198], [Tld::count(), SellRow::count(), SalesPolicy::count(), Price::count(), Product::count(), Plan::count()], 'Reseller sells the full scale shape');
$failures = []; $before = $reads->calls; $count = 0; $measuring = false;
DB::listen(static function ($query) use (&$count, &$measuring): void {
    if ($measuring) { expect(str_starts_with(strtolower($query->sql), 'select'), 'Page GET only reads our database'); $count++; }
});
foreach (['/domains', '/domains/register', '/domains/pricing'] as $url) {
    Cache::flush(); $request = Request::create($url);
    $request->setLaravelSession(app('session')->driver()); $request->setUserResolver(fn () => $user);
    $count = 0; $measuring = true;
    try {
        $view = match ($url) { '/domains' => $owned->index($request), '/domains/register' => $controller->search($request), default => $controller->pricing($request) };
        $html = $view->render();
    } finally { $measuring = false; }
    echo $url.' scale queries: '.$count."\n";
    if ($count > 64) { $failures[] = $url.': '.$count; }
    if ($url === '/domains/pricing') { same(599, storefrontDom($html)->query('//tbody[@id="domains-price-rows"]/tr')->length, 'All prices stay in server HTML without script'); }
    if ($url === '/domains') { same(false, $view->getData()['transfersOffered'], 'No transfer link after all TLD transfers are turned off'); }
    else {
        same(599, count($view->getData()[$url === '/domains/register' ? 'endings' : 'rows']), 'Every offered registration remains visible');
        expect(str_contains($html, 'scaleaa') && str_contains($html, 'scalexa'), 'Real Blade renders the first and last catalogue rows');
    }
}
same($before, $reads->calls, 'All three page GETs make zero registrar calls');
expect($failures === [], 'At most 64 queries per page at 599 endings: '.implode(', ', $failures));
$nativeSuccessful = true; echo "Reseller-scale customer page query bounds passed.\n";
