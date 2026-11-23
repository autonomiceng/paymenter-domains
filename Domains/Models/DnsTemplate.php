<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\RoutingProfile;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,StaffReason};
/** Registration nameservers edited through revision-checked staff actions. */
final class DnsTemplate extends Model
{
    protected $table = 'domains_dns_templates';
    protected $attributes = ['revision' => 1, 'enabled' => false];
    protected $guarded = [];
    protected $hidden = ['reason'];
    protected $casts = ['reason' => \Paymenter\Extensions\Servers\Domains\Casts\LegacyEncryptedString::class, 'revision' => 'integer', 'enabled' => 'boolean', 'nameservers' => 'array'];
    protected function incrementOrDecrement($column, $amount, $extra, $method): never
    { throw new \DomainException('Direct numeric updates require an explicit revisioned save.'); }
    private function validateRecord(): void
    {
        if (!is_string($this->name) || trim($this->name) === '' || strlen($this->name) > 128 ||
            !is_string($this->reason) || trim($this->reason) === '' || !StaffReason::valid($this->reason,1,1000) || (int) $this->edited_by < 1) {
            throw new \DomainException('A profile name, actor and reason are required.');
        }
        $profile = $this->profile();
        if($this->exists && $this->isDirty('enabled') && !$this->enabled
            && ($refusal=SiteDnsDefault::usesRefusal((int)$this->id,true))){throw new \DomainException($refusal);}
        if($saved=SiteDnsDefault::usesRefusal((int)$this->id)){
            if(!$this->enabled){throw new \DomainException($saved===SiteDnsDefault::REPLACE?'Choose another template for the default nameservers before turning this one off.':$saved);}
            SiteDnsDefault::validateRegistrars($profile->nameservers);
        }
        $this->nameservers = $profile->nameservers;
    }
    public function profile(): RoutingProfile { return new RoutingProfile($this->nameservers); }
    /** Unreadable retained records cannot supply editable defaults. */
    public function readProfile(): ?RoutingProfile
    {
        try{return $this->profile();}catch(\Throwable){return null;}
    }
    protected static function booted(): void
    {
        static::deleting(static function(self $row):void {
            // An unusable saved reference names its own recovery.
            if($saved=SiteDnsDefault::usesRefusal((int)$row->id)){
                throw new \DomainException($saved===SiteDnsDefault::REPLACE?'Choose another template for the default nameservers before deleting this one.':$saved);
            }
            throw new \DomainException('Record history is retained.');
        });
        static::saving(static function (self $row): void {
            if (DB::transactionLevel() < 1) { throw new \DomainException('Revisioned writes require a transaction.'); }
            if (!$row->exists && $row->revision !== 1) { throw new \DomainException('New records start at revision one.'); }
            if ($row->exists) {
                if ($row->isDirty($row->getKeyName())) { throw new \DomainException('Record identity is immutable.'); }
                $saved = $row->newQuery()->whereKey($row->getRawOriginal($row->getKeyName()))->lockForUpdate()->firstOrFail();
                if ((int) $saved->revision !== (int) $row->getRawOriginal('revision') || $row->revision !== $saved->revision + 1) {
                    throw new \DomainException('The saved record revision changed.');
                }
            }
            $row->validateRecord();
        });
        static::saved(static function (self $row): void {
            DomainAudit::create(['subject_type' => 'dns_profile', 'subject_id' => $row->id, 'actor_id' => $row->edited_by,
                'event' => 'dns_profile_saved', 'reason' => $row->reason, 'before_revision' => (string) ($row->revision - 1),
                'after_revision' => (string) $row->revision, 'data' => ['nameservers' => $row->nameservers,
                    'enabled' => $row->enabled], 'created_at' => now()]);
        });
    }
}
