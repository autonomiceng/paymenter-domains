<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\{Cache, DB, Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;

/**
 * Thirty-second display cache: short native-edit/freshness lag without catalogue reads on hits.
 * Owned writers invalidate after commit in the shared cache; node-local stores retain the TTL bound.
 * Native product/price/settings edits and raw/quiet maintenance writes use the TTL only.
 * Admission, checkout and interactive checks always resolve current offers, including expiry.
 */
final class StorefrontCatalogueCache
{
    public const TTL = 30;
    public function __construct(private readonly Clock $clock) {}

    public function remember(\Closure $render): array
    {
        // Never publish an uncommitted projection, or reuse display rows inside a writer.
        if (DB::transactionLevel() !== 0) { return $render(); }
        $scope = self::scope(); $revision = self::revision($scope); $key = self::key($scope, $revision);
        $now = $this->clock->now(); $until = $now->modify('+'.self::TTL.' seconds');
        $cached = Cache::get($key);
        if (is_array($cached) && new \DateTimeImmutable($cached['from']) <= $now && $now < new \DateTimeImmutable($cached['until'])) {
            return $cached['rows'];
        }
        $rows = $render();
        // Publication changes the namespace, including a commit racing the following put.
        if ($revision !== self::revision($scope) || $this->clock->now() >= $until) { return $rows; }
        Cache::put($key, ['rows' => $rows, 'from' => $now->format('Y-m-d\TH:i:s.uP'), 'until' => $until->format('Y-m-d\TH:i:s.uP')], self::TTL);
        return $rows;
    }

    public static function invalidateAfterCommit(): void
    {
        $scope = self::scope();
        DB::afterCommit(static function () use ($scope): void {
            try {
                $revision = self::revision($scope);
                Cache::forever($scope.':revision', bin2hex(random_bytes(16)));
                // One revision invalidates every deployment-configuration row variant.
                Cache::forget(self::key($scope, $revision));
            } catch (\Throwable $error) {
                // A display cache failure must not interrupt other committed side effects.
                Log::warning('Domains storefront display invalidation deferred.', ['exception_class' => $error::class]);
            }
        });
    }

    private static function revision(string $scope): string
    {
        return Cache::rememberForever($scope.':revision', static fn () => bin2hex(random_bytes(16)));
    }

    private static function key(string $scope, string $revision): string
    {
        return $scope.':'.$revision.':'.hash('sha256', json_encode([config('domains.enom_catalogue.enabled'), config('domains.enom_identity.environment'),config('domains.enom_live')], JSON_THROW_ON_ERROR));
    }

    private static function scope(): string
    {
        // Include the declared extension version without autoloading its native entry/hooks.
        static $release = null;
        $release ??= hash_file('sha256', dirname(__DIR__).'/Domains.php');
        // Bump the projection version when the cached row shape or eligibility implementation changes.
        return 'domains.storefront:'.hash('sha256', json_encode([(string) config('app.url'), $release, 3], JSON_THROW_ON_ERROR));
    }
}
