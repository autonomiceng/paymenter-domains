<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Catalogue\Cost;
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview,StorefrontCatalogueCache};

/** One per-account age policy for bulk observations; metadata and order quotes keep independent ages. */
final readonly class CatalogueFreshness
{
    public const KEY = 'domains_price_freshness_hours';
    public function __construct(public int $hours = 48)
    { if ($hours < 24 || $hours > 168) { throw new \InvalidArgumentException('Enter 24 to 168 hours.'); } }
    public function maxAge(): int { return $this->hours * 3600; }
    public static function forAccount(int $server): self
    {
        return self::forAccounts([$server])[$server];
    }
    /** One settings query for a complete display/admission batch. */
    public static function forAccounts(array $servers, bool $lock = false): array
    {
        $rows = \App\Models\Setting::where('settingable_type', (new Server)->getMorphClass())
            ->whereIn('settingable_id', $servers)->where('key', self::KEY)
            ->when($lock, static fn ($q) => $q->orderBy('id')->lockForUpdate())->get()->groupBy('settingable_id');
        $out = [];
        foreach ($servers as $server) {
            $saved = $rows[$server] ?? collect();
            if ($saved->count() > 1) { throw new \DomainException('Duplicate price freshness setting.'); }
            $value = $saved->first()?->value;
            $hours = $value === null ? 48 : filter_var($value, FILTER_VALIDATE_INT);
            if ($hours === false) { throw new \DomainException('Invalid price freshness setting.'); }
            $out[$server] = new self($hours);
        }
        return $out;
    }
    /** Re-evaluate the saved observation with today's staff policy without redating its source. */
    public function cost(Cost $cost): Cost
    {
        if (!in_array($cost->source, ['enom_bulk_bucket_1','enom_bulk_bucket_2','enom_bulk_bucket_5','enom_bulk_bucket_10','namesilo_account_annual'], true)) { return $cost; }
        return new Cost($cost->action, $cost->term, $cost->enabled, $cost->total, $cost->sourceUnit, $cost->source,
            $cost->observedAt, $cost->observedAt->modify('+'.$this->maxAge().' seconds'), $cost->currency, normalized: true);
    }
    public static function save(StaffSettingsReview $review, array $data): void
    {
        $review->local(static function () use ($review, $data): void {
            if ($review->action !== 'price_freshness' || $review->tldId !== null) { throw new \DomainException('Account freshness review required.'); }
            $hours = filter_var($data['hours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 24, 'max_range' => 168]]);
            if ($hours === false) { throw new \DomainException('Enter 24 to 168 hours.'); }
            $server = Server::whereKey($review->serverId)->lockForUpdate()->firstOrFail();
            $row = $server->settings()->where('key', self::KEY)->lockForUpdate()->first() ?? $server->settings()->make(['key' => self::KEY]);
            $row->value = (string) $hours; $row->type = 'integer'; $row->encrypted = false; $row->save();
            \Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id', $review->serverId)
                ->where('catalogue_converted', true)->increment('catalogue_revision');
            StorefrontCatalogueCache::invalidateAfterCommit();
        });
    }
}
