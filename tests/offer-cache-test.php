<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Encrypted credentials, revision rows and native prices require the existing commerce fixture.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Intent, Query, UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Services\{Storefront, StorefrontCatalogueCache};
expect(method_exists(Storefront::class, 'catalogue'), 'Public cache needs a dormant offer route and activation/credential namespace');
require __DIR__.'/Support/offer-commerce.php';
$fixture = offerCommerceFixture(); extract($fixture);
$legacy = new StorefrontCatalogueCache($clock);
$legacy->remember(static fn () => [['legacy' => true]]);
$cache = new StorefrontCatalogueCache($clock, $offers->cacheFence(...)); $renders = 0;
$render = static function () use (&$renders): array { return [['render' => ++$renders]]; };
same([['render' => 1]], $cache->remember($render), 'New cache cannot reuse an old caller namespace');
same([['render' => 1]], $cache->remember($render), 'Current evidence reuses a warm public cache');
$q = new Query($binding, ['com'], 'register');
$ports->apply(Edit::defaults($binding, 'com', new Intent(defaultLongest: 3, defaultTransfers: true)), offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->revision));
same([['render' => 2]], $cache->remember($render), 'Default revision fences public display even before republication');
expect($storefront->offer('com', 'register', 1) !== null, 'A wider default keeps the published offer for sale until it republishes');
$ports->apply(Edit::republish($binding, 'com'), offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->revision));
same([['render' => 3]], $cache->remember($render), 'Publication advances the cache namespace');
$before = $offers->cacheFence(); offerCommerceRotate($binding, 'cache-rotation-synthetic');
expect($before !== $offers->cacheFence(), 'Same binding with new credentials has a distinct namespace');
same([['render' => 4]], $cache->remember($render), 'Credential rotation cannot reuse old public rows');
advanceOfferGeneration();
same([['render' => 5]], $cache->remember($render), 'Generation advance fences an otherwise identical catalogue');
// Real Writer fact commits advance the shared namespace without scanning every offer on cache hits.
$clock->instant = $now->modify('+1 second');
$ports->apply(new Paymenter\Extensions\Servers\Domains\Catalogue\FactsObserved($binding, 'com',
    new Paymenter\Extensions\Servers\Domains\Catalogue\Facts(['register' => [1, 3], 'renew' => [1], 'transfer' => [1]],
        null, true, false, false, 'synthetic', $clock->now(), $now->modify('+1 day'))), offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->revision));
same([['render' => 6]], $cache->remember($render), 'Unknown registry facts fence cached public rows');
commerceReject(static fn () => $storefront->offer('com', 'register', 1));
$clock->instant = $now->modify('+2 seconds');
$ports->apply(new Paymenter\Extensions\Servers\Domains\Catalogue\FactsObserved($binding, 'com',
    new Paymenter\Extensions\Servers\Domains\Catalogue\Facts(['register' => [1, 3], 'renew' => [1], 'transfer' => [1]],
        [], true, false, false, 'synthetic', $clock->now(), $now->modify('+1 day'))), offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->revision));
$clock->instant = $now->modify('+31 seconds');
same([['render' => 7]], $cache->remember($render), 'Display TTL still bounds the freshness lag');
// A generation change during rendering must not poison the next request's namespace.
$clock->instant = $now->modify('+62 seconds');
$cache->remember(static function (): array { advanceOfferGeneration(); return [['raced' => true]]; });
same(null, $cache->cachedRows(), 'Racing render is not cached in either generation');
same([['render' => 8]], $cache->remember($render), 'Next request renders under current generation');
// Real public rendering must isolate one unavailable selected account on cold AND warm caches.
[$secondBinding] = offerCommerceSecond($fixture);
foreach (['disabled', 'credential'] as $failure) {
    Illuminate\Support\Facades\Cache::flush();
    same(['com', 'net'], array_column($storefront->endings(), 'tld'), 'Both healthy sellers render before '.$failure);
    $server = App\Models\Server::findOrFail($secondBinding->serverId);
    $proof = $server->settings()->where('key', 'domains_verified_credentials')->sole();
    $raw = $proof->getRawOriginal('value');
    if ($failure === 'disabled') { $server->update(['enabled' => false]); }
    else { DB::table('settings')->where('id', $proof->id)->update(['value' => 'invalid-synthetic-proof']); }
    foreach (['warm', 'cold'] as $temperature) {
        if ($temperature === 'cold') { Illuminate\Support\Facades\Cache::flush(); }
        same(['com'], array_column($storefront->endings(), 'tld'), $temperature.': healthy seller survives '.$failure);
        expect($storefront->hasTransferOffers(), 'Healthy transfer remains linked after '.$failure);
    }
    if ($failure === 'disabled') { $server->update(['enabled' => true]);$bindings->setProcessing($server->id,true,syntheticActivation());
        same(['com'],array_column($storefront->endings(),'tld'),'Resuming processing waits for local republication');
        $secondQuery=new Query($secondBinding,['net'],'register');
        $ports->apply(Edit::republish($secondBinding,'net'),offerCommerceAsStaff(static fn()=>$ports->readOffers($secondQuery,Context::Staff)[0]->revision)); }
    else { DB::table('settings')->where('id', $proof->id)->update(['value' => $raw]); }
    same(['com', 'net'], array_column($storefront->endings(), 'tld'), 'Restored account changes the namespace again');
}
$nativeSuccessful = true;
echo "Offer cache: ok\n";
