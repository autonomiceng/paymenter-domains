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
extract(offerCommerceFixture());
$legacy = new StorefrontCatalogueCache($clock);
$legacy->remember(static fn () => [['legacy' => true]]);
$cache = new StorefrontCatalogueCache($clock, $offers->cacheFence(...)); $renders = 0;
$render = static function () use (&$renders): array { return [['render' => ++$renders]]; };
same([['render' => 1]], $cache->remember($render), 'New cache cannot reuse an old caller namespace');
same([['render' => 1]], $cache->remember($render), 'Current evidence reuses a warm public cache');
$q = new Query($binding, ['com'], 'register');
$ports->apply(Edit::defaults($binding, 'com', new Intent(defaultLongest: 3, defaultTransfers: true),
    Paymenter\Extensions\Servers\Domains\Services\PricingRule::DEFAULT), $ports->readOffers($q, Context::Staff)[0]->revision);
same([['render' => 2]], $cache->remember($render), 'Default revision fences public display even before republication');
commerceReject(static fn () => $storefront->offer('com', 'register', 1));
$ports->apply(Edit::republish($binding, 'com'), $ports->readOffers($q, Context::Staff)[0]->revision);
same([['render' => 3]], $cache->remember($render), 'Publication advances the cache namespace');
$before = $offers->cacheFence(); offerCommerceRotate($binding, 'cache-rotation-synthetic');
expect($before !== $offers->cacheFence(), 'Same binding with new credentials has a distinct namespace');
same([['render' => 4]], $cache->remember($render), 'Credential rotation cannot reuse old public rows');
$gate = new UpgradeGate; $gate->pause(); $gate->resume();
same([['render' => 5]], $cache->remember($render), 'Generation advance fences an otherwise identical catalogue');
// Facts can invalidate eligibility without changing retail. Never reuse the former display rows.
$facts = DB::table('domains_tlds')->where('id', $tlds[0]->id)->value('catalogue_facts');
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => null]);
same([['render' => 6]], $cache->remember($render), 'Facts loss fences cached public rows');
commerceReject(static fn () => $storefront->offer('com', 'register', 1));
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_facts' => $facts]);
$clock->instant = $now->modify('+31 seconds');
same([['render' => 7]], $cache->remember($render), 'Display TTL still bounds the freshness lag');
// A generation change during rendering must not poison the next request's namespace.
$clock->instant = $now->modify('+62 seconds');
$cache->remember(static function () use ($gate): array { $gate->pause(); $gate->resume(); return [['raced' => true]]; });
same(null, $cache->cachedRows(), 'Racing render is not cached in either generation');
same([['render' => 8]], $cache->remember($render), 'Next request renders under current generation');
$nativeSuccessful = true;
echo "Offer cache: ok\n";
