<?php
// Adapted from Enom d2c55e2 Models/Domain.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    use HasAccountBinding;

    protected $table = 'domains_domains';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint', 'snapshot', 'renewal_policy', 'observation', 'provider_domain_id', 'reservation_name'];
    protected $attributes = ['billing_managed' => true, 'price_mode' => 'standard'];

    protected $casts = ['provider_expires_at' => 'immutable_date', 'snapshot' => 'encrypted:array', 'capabilities' => 'array', 'last_synced_at' => 'immutable_datetime',
        'billing_managed' => 'boolean', 'renewal_policy' => 'encrypted:array', 'observation' => 'encrypted:array',
        'authority_generation' => 'integer', 'confirmed_revision' => 'integer', 'reminders' => 'array'];

    protected static function booted(): void
    {
        static::creating(static function (self $domain): void {
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
            if ($domain->isDirty('provenance') || (($domain->adoptionPending() || $domain->getRawOriginal('status') === 'adoption_pending' || $domain->getRawOriginal('provenance') === 'adoption_pending') && $domain->isDirty(['status', 'billing_managed', 'provider_expires_at', 'confirmed_revision', 'renewal_policy']))) { throw new \DomainException('Holding provenance requires verified adoption.'); }
            $checkout = ($domain->getOriginal('snapshot') ?? [])['checkout'] ?? null;
            if ($checkout !== null && ($domain->snapshot['checkout'] ?? null) !== $checkout) { throw new \DomainException('Domain checkout evidence is frozen.'); }
            if ($domain->isDirty(['id', 'domain', 'sld', 'tld', 'reservation_name', 'server_id', 'registrar_key', 'account_fingerprint', 'authority_generation', 'service_id', 'user_id'])) {
                throw new \DomainException('Domain identity and reservation require the reservation service.');
            }
        });
    }

    public function adoptionPending(): bool { return $this->status === 'adoption_pending' || $this->provenance === 'adoption_pending'; }

    public function service() { return $this->belongsTo(Service::class); }
    public function operations() { return $this->hasMany(Operation::class); }
}
