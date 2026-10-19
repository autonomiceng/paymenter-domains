<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\WatchManagedTarget;
final class WatchSource extends WatchRecord
{
    protected $table = 'domains_watch_sources';
    private bool $retiring = false;
    protected array $identity = ['id', 'user_id', 'hostname', 'registrable_name', 'map_id'];
    protected $hidden = ['source_digest'];
    protected $casts = ['revision' => 'integer', 'map_revision' => 'integer', 'last_seen_at' => 'integer'];
    protected function validateRecord(): void
    {
        if ($this->exists && $this->getRawOriginal('state') === 'retired') { throw new \DomainException('Retired source history is immutable.'); }
        if ($this->exists && !$this->retiring && $this->isDirty(['watch_id', 'managed_domain_id'])) { throw new \DomainException('Source target changes require retained history.'); }
        $watch = $this->watch_id !== null; $managed = $this->managed_domain_id !== null;
        if (!in_array($this->state, ['watch', 'managed_link', 'held', 'retired'], true) ||
            $watch !== ($this->state === 'watch') || $managed !== ($this->state === 'managed_link') || !self::digest($this->source_digest)) {
            throw new \DomainException('A source must have exactly its declared target.');
        }
        if ($this->exists && $this->isDirty('service_id') && !($this->state === 'retired' && $this->service_id === null)) {
            throw new \DomainException('Source services cannot be reparented.');
        }
        if ($this->state === 'retired') {
            if (!$this->exists || ($this->isDirty(['state', 'service_id']) && !$this->retiring)) { throw new \DomainException('Source retirement requires retained audit.'); }
            if ($this->service_id !== null) { throw new \DomainException('Retired sources must release their active service identity.'); }
            return;
        }
        foreach (['hostname', 'registrable_name'] as $key) {
            if ($this->{$key} !== null && !self::canonical($this->{$key})) { throw new \DomainException('Source names must be canonical or absent.'); }
        }
        if (($this->state === 'held' && (!is_string($this->safe_code) || preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $this->safe_code) !== 1))
            || ($this->state !== 'held' && $this->safe_code !== null)) { throw new \DomainException('Source hold reasons must be safe codes.'); }
        $map = WatchProductMap::whereKey($this->map_id)->lockForUpdate()->firstOrFail();
        $service = \App\Models\Service::whereKey($this->service_id)->lockForUpdate()->firstOrFail();
        if ((int) $map->revision !== (int) $this->map_revision || (int) $service->product_id !== (int) $map->product_id || (int) $service->user_id !== (int) $this->user_id) {
            throw new \DomainException('Source owner or mapping changed.');
        }
        if (($watch || $managed) && (!self::canonical($this->hostname) || !self::canonical($this->registrable_name))) { throw new \DomainException('Source names must be canonical.'); }
        if ($watch && !WatchName::whereKey($this->watch_id)->where('user_id', $this->user_id)->where('canonical_name', $this->registrable_name)->whereIn('state', ['active', 'paused'])->exists()) {
            throw new \DomainException('Watch source target changed.');
        }
        if ($managed) {
            WatchManagedTarget::resolve((int) $this->managed_domain_id, (int) $this->user_id, $this->registrable_name);
        }
    }
    /** Internal lifecycle primitive: archive before releasing the unique current-service key. */
    public function retire(string $reason): void
    {
        DB::transaction(function () use ($reason): void {
            if (trim($reason) === '' || strlen($reason) > 1000) { throw new \DomainException('A retirement reason is required.'); }
            if (!$this->exists || $this->isDirty()) { throw new \DomainException('Retirement requires an unchanged saved source.'); }
            $saved = self::whereKey($this->getRawOriginal('id'))->lockForUpdate()->firstOrFail();
            if ((int) $saved->revision !== (int) $this->getRawOriginal('revision')) { throw new \DomainException('The saved source changed.'); }
            $before = $saved->revision;
            $evidence = ['service_id' => $saved->service_id, 'watch_id' => $saved->watch_id, 'managed_domain_id' => $saved->managed_domain_id, 'state' => $saved->state];
            if ($saved->state === 'retired') { throw new \DomainException('Source is already retired.'); }
            $this->retiring = true;
            try { $this->fill(['state' => 'retired', 'service_id' => null, 'watch_id' => null, 'managed_domain_id' => null, 'revision' => $before + 1])->save(); }
            finally { $this->retiring = false; }
            WatchAudit::create(['subject_type' => 'source', 'subject_id' => $this->id, 'user_id' => $this->user_id, 'event' => 'source_retired', 'reason' => $reason,
                'before_revision' => (string) $before, 'after_revision' => (string) $this->revision, 'data' => $evidence, 'created_at' => now()]);
        });
    }
}
