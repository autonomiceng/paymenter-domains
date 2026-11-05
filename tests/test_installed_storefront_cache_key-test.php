<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native DatabaseStore is required: ArrayStore cannot expose prefixed database key overflow.
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{Cache, DB, Schema};
use Paymenter\Extensions\Servers\Domains\Services\StorefrontCatalogueCache;

Schema::create('cache', static function (Illuminate\Database\Schema\Blueprint $table): void {
    $table->string('key', 255)->primary(); $table->text('value'); $table->integer('expiration');
});
$store = new Illuminate\Cache\DatabaseStore(DB::connection(), 'cache', 'laravel_cache_');
same(14, strlen($store->getPrefix()), 'Real database prefix length');
Cache::swap(new Illuminate\Cache\Repository($store));
$clock = new class implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-11-04T00:00:00Z'); }
};
$fence = str_repeat('f', 64); $renders = 0;
$cache = new StorefrontCatalogueCache($clock, static function () use (&$fence): string { return $fence; });
$render = static function () use (&$renders): array { return [['render' => ++$renders]]; };
same([['render' => 1]], $cache->remember($render), 'Database cache accepts a fenced write');
$keys = DB::table('cache')->pluck('key')->all();
expect($keys !== [] && max(array_map('strlen', $keys)) <= 255, 'Every persisted prefixed key fits the native 255-character column');
same([['render' => 1]], $cache->cachedRows(), 'Separate read path reads the database write');
same([['render' => 1]], $cache->remember($render), 'Warm remember does not render again');
$fence = str_repeat('e', 64);
same(null, $cache->cachedRows(), 'Changed offer fence cannot reuse cached rows');
$cache->remember($render);
$live = config('domains.enom_live'); config(['domains.enom_live' => !$live]);
same(null, $cache->cachedRows(), 'Changed configuration variant cannot reuse cached rows');
$cache->remember($render);
$url = config('app.url'); config(['app.url' => 'https://another-storefront.invalid']);
same(null, $cache->cachedRows(), 'Changed site scope cannot reuse cached rows');
$cache->remember($render); config(['app.url' => $url]);
same([['render' => 3]], $cache->cachedRows(), 'Original scope retains its own rows');
DB::beginTransaction(); StorefrontCatalogueCache::invalidateAfterCommit(); DB::rollBack();
same([['render' => 3]], $cache->cachedRows(), 'Rollback preserves the committed revision');
DB::transaction(static fn () => StorefrontCatalogueCache::invalidateAfterCommit());
same(null, $cache->cachedRows(), 'Committed invalidation fences persisted rows');
config(['domains.enom_live' => $live]);
same(null, $cache->cachedRows(), 'One revision invalidates all configuration variants');
same([['render' => 5]], $cache->remember($render), 'Next request writes under the new revision');
same([['render' => 5]], $cache->cachedRows(), 'New revision is readable');
$maximum = DB::table('cache')->pluck('key')->map(static fn ($key) => strlen($key))->max();
expect($maximum <= 255, 'All scope, variant, fence and revision keys remain bounded');
$nativeSuccessful = true;
echo "Storefront database cache keys: ok (maximum $maximum characters)\n";
