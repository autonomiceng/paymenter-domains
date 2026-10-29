<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline query counts and display parity.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\Storefront;
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

extract(commerceFixture(true)); $clock = new FakeClock($now);
require __DIR__.'/Support/owner-pages.php';
$store = app(Storefront::class); $actor = commerceUser();
$controller = new OwnedDomainController($owners);
$request = Request::create('/domains', 'GET'); $request->setUserResolver(fn () => $actor);
$measure = static function (Closure $read): array {
    DB::flushQueryLog(); DB::enableQueryLog();
    try { return [$read(), count(DB::getQueryLog())]; }
    finally { DB::disableQueryLog(); DB::flushQueryLog(); }
};
// A valid early offer must stop before any later enabled policy, even on the actual index.
Cache::flush(); [$page, $small] = $measure(fn () => $controller->index($request));
same(true, $page->getData()['transfersOffered'], 'Cold index has a transfer offer');
$extra = [];
for ($i = 0; $i < 1499; $i++) {
    $extra[] = ['tld' => 'z'.chr(97 + intdiv($i, 676)).chr(97 + intdiv($i % 676, 26)).chr(97 + $i % 26), 'transfer_enabled' => true];
}
foreach (array_chunk($extra, 100) as $chunk) { DB::table('domains_sales_policies')->insert($chunk); }
Cache::flush(); [$page, $large] = $measure(fn () => $controller->index($request));
same(true, $page->getData()['transfersOffered'], 'Large cold index retains the offer');
echo "Cold index queries: one ending $small, 1500 endings $large.\n";
same($small, $large, 'Cold index stops at the first valid transfer offer');
DB::table('domains_sales_policies')->where('tld', '!=', 'com')->delete();

$parity = static function (bool $expected, string $label) use ($store, $measure): void {
    Cache::flush();
    same($expected, $store->hasTransferOffers(), $label.' cold existence');
    $rows = $store->endings();
    same($expected, collect($rows)->contains(fn ($row) => $row['transfer'] !== null), $label.' full catalogue');
    [$warm, $queries] = $measure(fn () => $store->hasTransferOffers());
    same($expected, $warm, $label.' warm existence');
    same(0, $queries, $label.' warm existence is query-free');
};
$parity(true, 'Test account');
[$offered, $queries] = $measure(fn () => $store->hasTransferOffers());
same(true, $offered, 'Warm positive is retained'); same(0, $queries, 'Warm existence is query-free');
// Raw native edits retain exactly the same display lag as endings(), including negative rows.
$product = $native->product($tlds[0], 'transfer');
$product->update(['stock' => 0]);
same(true, $store->hasTransferOffers(), 'Warm positive survives native withdrawal within TTL');
$clock->instant = $now->modify('+30 seconds');
same(false, $store->hasTransferOffers(), 'Exact TTL expiry rechecks native withdrawal');
$parity(false, 'No transfer stock');
$product->update(['stock' => null]);
same(false, $store->hasTransferOffers(), 'Warm negative survives native restoration within TTL');
$clock->instant = $now->modify('+60 seconds'); $parity(true, 'Restored stock');
// A separate Boolean cache must not disagree with endings() when its full projection is cold.
Cache::flush(); $product->update(['stock' => 0]);
same(false, $store->hasTransferOffers(), 'Cold negative without a full projection');
$product->update(['stock' => null]);
same(true, $store->hasTransferOffers(), 'Cold restoration cannot reuse a separate negative cache');
same(true, collect($store->endings())->contains(fn ($row) => $row['transfer'] !== null), 'Cold restored catalogue agrees');
// A writer must bypass committed rows and must not publish its temporary result.
DB::beginTransaction();
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['enabled' => false]);
same(false, $store->hasTransferOffers(), 'Transaction sees its own disabled TLD');
DB::rollBack(); same(true, $store->hasTransferOffers(), 'Rollback retains committed display');
foreach (['enabled', 'renew_enabled', 'transfer_enabled'] as $flag) {
    DB::table('domains_tlds')->where('id', $tlds[0]->id)->update([$flag => false]);
    $parity(false, 'Disabled TLD '.$flag);
    DB::table('domains_tlds')->where('id', $tlds[0]->id)->update([$flag => true]);
}
DB::table('domains_sales_policies')->where('tld', 'com')->update(['transfer_enabled' => false]);
$parity(false, 'Registration-only policy');
DB::table('domains_sales_policies')->where('tld', 'com')->update(['transfer_enabled' => true]);
foreach (['transfer', 'renew'] as $action) {
    DB::table('domains_prices')->where('server_id', $tlds[0]->server_id)->where('action', $action)->update(['hold_reason' => 'below_cost']);
    $parity(false, 'Held '.$action.' price');
    DB::table('domains_prices')->where('server_id', $tlds[0]->server_id)->where('action', $action)->update(['hold_reason' => null]);
}
// A failing earlier candidate must not hide a later valid offer.
DB::table('domains_sales_policies')->insert(['tld' => 'aaa', 'transfer_enabled' => true]);
$parity(true, 'Unavailable earlier seller');
DB::table('domains_sales_policies')->where('tld', 'aaa')->delete();
$demoServer = App\Models\Server::create(['name' => 'Synthetic', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
$bindings->configure($demoServer->id, 'enom', Paymenter\Extensions\Servers\Domains\Data\Environment::Test, ['token' => 'demo-enom']);
expect(Paymenter\Extensions\Servers\Domains\Support\DemoData::present(), 'Real demo account marker is installed');
$parity(true, 'Offline demo display');
config(['domains.enom_identity.environment' => 'test']);
$parity(true, 'Demo with test deployment');
$savedRows = $store->endings(); $before = $reads->calls;
$clock->instant = $now->modify('+1 day'); $parity(true, 'Saved prices after one day');
same($savedRows, $store->endings(), 'Age preserves exact public transfer prices and terms');
same($before, $reads->calls, 'Older transfer prices require no provider reads');
DB::table('domains_sales_policies')->delete(); $parity(false, 'No policies');
$nativeSuccessful = true;
echo "Transfer existence display parity and early-return query bounds passed.\n";
