<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;

use Paymenter\Extensions\Servers\Domains\Services\StorefrontCatalogueCache;

/** Owned catalogue writers use ordinary model saves; raw/quiet maintenance uses the display TTL. */
trait InvalidatesStorefront
{
    public static function bootInvalidatesStorefront(): void
    {
        static::saved(static fn () => StorefrontCatalogueCache::invalidateAfterCommit());
        static::deleted(static fn () => StorefrontCatalogueCache::invalidateAfterCommit());
    }
}
