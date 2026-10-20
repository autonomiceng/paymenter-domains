<?php
// Adapted from Enom d2c55e2 Models/Domain.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\HoldingIdentityLock;

class Domain extends Model
{
    use HasAccountBinding;

    private bool $attachingAdoption = false;
    private bool $savingAdoption = false;

    protected $table = 'domains_domains';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint', 'snapshot', 'renewal_policy', 'observation', 'provider_domain_id', 'reservation_name'];
    protected $attributes = ['billing_managed' => true, 'price_mode' => 'standard'];

    protected $casts = ['provider_expires_at' => 'immutable_date', 'snapshot' => 'encrypted:array', 'capabilities' => 'array', 'last_synced_at' => 'immutable_datetime',
        'billing_managed' => 'boolean', 'renewal_policy' => 'encrypted:array', 'observation' => 'encrypted:array',
        'authority_generation' => 'integer', 'confirmed_revision' => 'integer', 'adoption_id' => 'integer', 'reminders' => 'array'];

    protected static function booted(): void
    {
        static::saving(static function (self $domain): void {
            foreach (array_keys($domain->getAttributes()) as $key) {
                if ($key !== strtolower($key)) { throw new \DomainException('Domain attributes require canonical names.'); }
            }
        });
        static::creating(static function (self $domain): void {
            if ($domain->adoption_id !== null || isset($domain->snapshot['holding'])) { throw new \DomainException('Adoption requires locked attachment.'); }
            if ($domain->provenance !== null && !in_array($domain->provenance, ['purchased', 'converted_purchase', 'adopted', 'adoption_pending'], true)) { throw new \InvalidArgumentException('Unknown holding provenance.'); }
            if ($domain->adoptionPending()) { throw new \DomainException('Pending holdings require reviewed conversion.'); }
            $name = \Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse($domain->domain, [$domain->tld]);
            if ($domain->sld !== $name['sld']) { throw new \InvalidArgumentException('Domain label mismatch.'); }
            $domain->domain = $name['domain'];
            $domain->reservation_name = $name['domain'];
            $domain->authority_generation ??= 1;
            if ($domain->authority_generation < 1) { throw new \InvalidArgumentException('New holdings require a positive generation.'); }
        });
        static::deleting(static function (): void { throw new \DomainException('Domain ledger rows are retained.'); });
        static::updating(static function (self $domain): void {
            // A stale model must not erase proof attached by another committed writer.
            $current = self::whereKey($domain->getRawOriginal('id'))->lockForUpdate()->firstOrFail();
            if (!$domain->attachingAdoption && $domain->isDirty('snapshot')
                && (($domain->snapshot['holding'] ?? null) !== ($current->snapshot['holding'] ?? null)
                    || ($current->snapshot['checkout'] ?? null) !== ($domain->snapshot['checkout'] ?? null))) {
                throw new \DomainException('Current holding identity is frozen.');
            }
            if (!$domain->attachingAdoption && ($domain->isDirty('adoption_id') || (($domain->snapshot['holding'] ?? null) !== (($domain->getOriginal('snapshot') ?? [])['holding'] ?? null)))) { throw new \DomainException('Original holding adoption is frozen.'); }
            if (!$domain->attachingAdoption && ($domain->isDirty('provenance') || (($domain->adoptionPending() || $domain->getRawOriginal('status') === 'adoption_pending' || $domain->getRawOriginal('provenance') === 'adoption_pending') && $domain->isDirty(['status', 'billing_managed', 'provider_expires_at', 'confirmed_revision', 'renewal_policy'])))) { throw new \DomainException('Holding provenance requires verified adoption.'); }
            if ($domain->getRawOriginal('adoption_id') !== null && isset($domain->snapshot['checkout'])) { throw new \DomainException('Adoption is not checkout evidence.'); }
            $checkout = ($domain->getOriginal('snapshot') ?? [])['checkout'] ?? null;
            if ($checkout !== null && ($domain->snapshot['checkout'] ?? null) !== $checkout) { throw new \DomainException('Domain checkout evidence is frozen.'); }
            if (!$domain->attachingAdoption && $domain->isDirty('authority_generation')) { throw new \DomainException('Domain generation requires verified adoption.'); }
            if ($domain->isDirty(['id', 'domain', 'sld', 'tld', 'reservation_name', 'server_id', 'registrar_key', 'account_fingerprint', 'service_id', 'user_id'])) {
                throw new \DomainException('Domain identity and reservation require the reservation service.');
            }
        });
    }

    public function save(array $options = [])
    {
        if (!$this->exists) {
            return DB::transaction(function () use ($options) {
                $saved = parent::save($options);
                if ($saved) {
                    $created = self::findOrFail($this->getKey());
                    if ($created->adoption_id !== null || isset($created->snapshot['holding']) || $created->adoptionPending()) {
                        throw new \DomainException('Adoption requires locked attachment.');
                    }
                }
                return $saved;
            });
        }
        if ($this->savingAdoption) { throw new \LogicException('Nested adoption write refused.'); }
        // The savepoint includes late public callbacks, even when a caller catches refusal.
        return DB::transaction(function () use ($options) {
            $before = self::whereKey($this->getRawOriginal('id'))->lockForUpdate()->firstOrFail();
            $fields = ['id', 'domain', 'sld', 'tld', 'reservation_name', 'server_id', 'registrar_key',
                'account_fingerprint', 'service_id', 'user_id', 'adoption_id', 'authority_generation', 'provenance'];
            $expected = [];
            foreach ($fields as $field) { $expected[$field] = $this->attachingAdoption ? $this->$field : $before->$field; }
            $proof = $this->attachingAdoption ? $this->snapshot : $before->snapshot;
            $projection = $this->attachingAdoption ? ['status' => $this->status, 'billing_managed' => $this->billing_managed, 'price_mode' => $before->price_mode,
                'confirmed_revision' => $this->confirmed_revision, 'provider_domain_id' => $this->provider_domain_id,
                'expiry' => $this->provider_expires_at?->format('Y-m-d'), 'renewal_policy' => $this->renewal_policy] : null;
            $this->savingAdoption = $this->attachingAdoption;
            try {
                $saved = parent::save($options); $after = $before->fresh();
                foreach ($expected as $field => $value) {
                    if ($after->$field !== $value) { throw new \DomainException('Retained holding identity changed during save.'); }
                }
                foreach (['holding', 'checkout'] as $key) {
                    if (($after->snapshot[$key] ?? null) !== ($proof[$key] ?? null)) { throw new \DomainException('Retained holding proof changed during save.'); }
                }
                if ($projection !== null && $projection !== ['status' => $after->status, 'billing_managed' => $after->billing_managed, 'price_mode' => $after->price_mode,
                    'confirmed_revision' => $after->confirmed_revision, 'provider_domain_id' => $after->provider_domain_id,
                    'expiry' => $after->provider_expires_at?->format('Y-m-d'), 'renewal_policy' => $after->renewal_policy]) {
                    throw new \DomainException('Adoption projection changed during save.');
                }
                return $saved;
            } finally { $this->savingAdoption = false; }
        });
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method): never
    { throw new \DomainException('Domain changes require an explicit save.'); }

    /** Internal A3 storage boundary. The caller owns fresh proof, permission and confirmation. */
    public function attachAdoption(HoldingIdentityLock $lock, int $adoptionId): self
    {
        $owners = $lock->owners((int) $this->user_id);
        $native = $lock->nativeIdentity($this);
        $d = self::whereKey($this->getRawOriginal('id'))->lockForUpdate()->firstOrFail();
        $a = Adoption::whereKey($adoptionId)->lockForUpdate()->firstOrFail();
        $e = $a->evidence;
        if (!$d->adoptionPending() || $d->adoption_id !== null || isset($d->snapshot['checkout'])
            || isset($d->snapshot['holding']) || $a->state !== 'committed' || !$a->committed_at
            || (int) $a->domain_id !== (int) $d->id || (int) $a->service_id !== (int) $d->service_id
            || (int) $a->user_id !== (int) $d->user_id || (int) $a->server_id !== (int) $d->server_id
            || $a->registrar_key !== $d->registrar_key || $a->account_fingerprint !== $d->account_fingerprint
            || $a->authority_generation !== $d->authority_generation + 1 || $d->authority_generation < 1
            || ($e['version'] ?? null) !== 1 || ($e['canonical_name'] ?? null) !== $d->domain
            || ($e['native_identity'] ?? null) !== $native || $native['route_action'] !== 'register'
            || $owners['order_id'] !== $native['order_id'] || ($e['prior_generation'] ?? null) !== $d->authority_generation || ($e['resulting_generation'] ?? null) !== $a->authority_generation
            || !is_int($e['resulting_revision'] ?? null) || $e['resulting_revision'] < 1
            || ($e['prior_revision'] ?? null) !== $d->confirmed_revision || $e['resulting_revision'] !== $d->confirmed_revision + 1
            || ($e['account_proof_digest'] ?? null) !== $d->account_fingerprint
            || !is_string($e['expiry']['value'] ?? null)) { throw new \DomainException('Committed adoption does not match this holding.'); }
        $expiry = substr($e['expiry']['value'], 0, 10);
        new \Paymenter\Extensions\Servers\Domains\Data\DateEvidence($expiry, \Paymenter\Extensions\Servers\Domains\Data\ExpiryPrecision::Date, 'adoption');
        $holding = ['version' => 1, 'adoption_id' => (int) $a->id, 'request_digest' => $a->request_digest,
            'name' => $d->domain, 'native_identity' => $native, 'registrar_key' => $a->registrar_key,
            'environment' => $a->environment, 'account_fingerprint' => $a->account_fingerprint,
            'generation' => $a->authority_generation, 'confirmed_revision' => $e['resulting_revision'],
            'expiry' => $expiry, 'provider_domain_id' => $e['provider_domain_id'] ?? null];
        $d->attachingAdoption = true;
        try {
            $d->fill(['authority_generation' => $a->authority_generation, 'adoption_id' => $a->id, 'provenance' => 'adopted', 'status' => 'active', 'billing_managed' => true,
                'snapshot' => [...($d->snapshot ?? []), 'holding' => $holding], 'confirmed_revision' => $holding['confirmed_revision'],
                'provider_expires_at' => $expiry, 'provider_domain_id' => $holding['provider_domain_id'],
                'renewal_policy' => [...($d->renewal_policy ?? []), 'billing_anchor' => ['kind' => 'adoption', 'adoption_id' => (int) $a->id,
                    'expiry' => $expiry, 'confirmed_revision' => $holding['confirmed_revision']]]])->save();
        } finally { $d->attachingAdoption = false; }
        return $this->refresh();
    }

    /** Read-only observation for the native write guard; the grant stays private. */
    public function attachingAdoption(): bool { return $this->attachingAdoption; }

    public function adoptionPending(): bool { return $this->status === 'adoption_pending' || $this->provenance === 'adoption_pending'; }

    public function service() { return $this->belongsTo(Service::class); }
    public function operations() { return $this->hasMany(Operation::class); }
}
