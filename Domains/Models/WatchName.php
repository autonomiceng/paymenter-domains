<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Paymenter\Extensions\Servers\Domains\Services\WatchManagedTarget;
final class WatchName extends WatchRecord
{
    protected $table = 'domains_watch_names';
    protected $attributes = ['revision' => 1, 'origin' => 'service', 'profile_revision' => 0, 'notice_preferences' => '{}'];
    protected $hidden = ['registration_evidence'];
    protected $casts = ['revision' => 'integer', 'profile_revision' => 'integer', 'notice_preferences' => 'array', 'registration_evidence' => 'encrypted:array'];
    protected array $identity = ['id', 'user_id', 'canonical_name', 'origin'];
    public function sources(): \Illuminate\Database\Eloquent\Relations\HasMany {return $this->hasMany(WatchSource::class,'watch_id');}
    public function delegation(): \Illuminate\Database\Eloquent\Relations\HasOne {return $this->hasOne(WatchDnsObservation::class,'watch_id')->ofMany(['id'=>'max'],fn($q)=>$q->where('check_kind','delegation'));}
    public function points(): \Illuminate\Database\Eloquent\Relations\HasOne {return $this->hasOne(WatchDnsObservation::class,'watch_id')->ofMany(['id'=>'max'],fn($q)=>$q->where('check_kind','points'));}
    public function registry(): \Illuminate\Database\Eloquent\Relations\HasOne {return $this->hasOne(WatchRegistryObservation::class,'watch_id')->latestOfMany();}
    public function work(): \Illuminate\Database\Eloquent\Relations\HasMany {return $this->hasMany(WatchWorkRow::class,'watch_id');}
    protected function validateRecord(): void
    {
        if (!self::canonical($this->canonical_name) || !self::digest($this->psl_revision) || $this->origin !== 'service' ||
            !in_array($this->state, ['active', 'paused', 'retired', 'linked'], true) ||
            !in_array($this->registration, [null, 'platform', 'external'], true) || !in_array($this->expected_dns, [null, 'platform', 'external'], true)) {
            throw new \DomainException('Invalid watch identity or state.');
        }
        if (($this->state === 'linked') !== ($this->managed_domain_id !== null) || ($this->registration === 'platform' && $this->state !== 'linked')) {
            throw new \DomainException('Managed registration requires a linked tombstone.');
        }
        if (in_array($this->state, ['active','paused'], true) && WatchManagedTarget::exists((int)$this->user_id,$this->canonical_name)) {
            throw new \DomainException('Managed holdings cannot have active external watches.');
        }
        if ($this->managed_domain_id !== null) {
            $domain = WatchManagedTarget::resolve((int) $this->managed_domain_id, (int) $this->user_id, $this->canonical_name);
            if ($this->registration === 'platform' && !in_array($domain->status, WatchManagedTarget::MANAGED, true)) { throw new \DomainException('Pending acquisition does not establish platform registration.'); }
        }
    }
}
