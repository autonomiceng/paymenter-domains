<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;

/** Five-second display cache only. Admission and interactive checks always resolve current offers. */
final class StorefrontCatalogueCache
{
    public function __construct(private readonly Clock $clock) {}

    public function remember(\Closure $render): array
    {
        [$signature, $until] = $this->snapshot(); $now = $this->clock->now();
        $key = 'domains.storefront:'.$signature; $cached = Cache::get($key);
        if (is_array($cached) && new \DateTimeImmutable($cached['from']) <= $now && $now < new \DateTimeImmutable($cached['until'])) {
            return $cached['rows'];
        }
        $rows = $render();
        // A concurrent publication cannot leave a mixed projection cached under the old revision.
        if ($signature !== $this->snapshot()[0] || $this->clock->now() >= $until) { return $rows; }
        Cache::put($key, ['rows' => $rows, 'from' => $now->format('Y-m-d\TH:i:s.uP'), 'until' => $until->format('Y-m-d\TH:i:s.uP')], 5);
        return $rows;
    }

    private function snapshot(): array
    {
        $now = $this->clock->now(); $until = $now->modify('+5 seconds'); $state = [(string) config('app.url')];
        // Constant query count, independent of endings/terms. Raw settings stay encrypted;
        // only their digest leaves this method, never values or model serialization.
        foreach (['domains_sales_policies', 'domains_accounts', 'domains_tlds', 'domains_prices',
            'extensions', 'settings', 'products', 'plans', 'prices', 'product_upgrades', 'config_option_products'] as $table) {
            $query = DB::table($table);
            if ($table === 'extensions') { $query->where('type', 'server'); }
            if ($table === 'settings') { $query->where('key', 'like', 'domains_%'); }
            $rows = $query->get()->all();
            // Canonical order covers native pivot tables without a primary key.
            $encoded = array_map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $rows); sort($encoded);
            $state[] = hash('sha256', implode("\n", $encoded));
            if ($table !== 'domains_prices') { continue; }
            foreach ($rows as $row) {
                foreach (['observed_at', 'fresh_until'] as $field) {
                    if (!$row->$field) { continue; }
                    $boundary = new \DateTimeImmutable($row->$field, new \DateTimeZone('UTC'));
                    if ($boundary > $now && $boundary < $until) { $until = $boundary; }
                }
            }
        }
        return [hash('sha256', implode(':', $state)), $until];
    }
}
