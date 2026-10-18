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
            $name = \Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse($domain->domain, [$domain->tld]);
            if ($domain->sld !== $name['sld']) { throw new \InvalidArgumentException('Domain label mismatch.'); }
            $domain->domain = $name['domain'];
            $domain->reservation_name = $name['domain'];
            $domain->authority_generation ??= 1;
            if ($domain->authority_generation < 1) { throw new \InvalidArgumentException('New holdings require a positive generation.'); }
        });
        static::deleting(static function (): void { throw new \DomainException('Domain ledger rows are retained.'); });
        static::updating(static function (self $domain): void {
            if ($domain->isDirty(['id', 'domain', 'sld', 'tld', 'reservation_name', 'server_id', 'registrar_key', 'account_fingerprint', 'authority_generation', 'service_id', 'user_id'])) {
                throw new \DomainException('Domain identity and reservation require the reservation service.');
            }
        });
    }

    public function service() { return $this->belongsTo(Service::class); }
    public function operations() { return $this->hasMany(Operation::class); }
}
