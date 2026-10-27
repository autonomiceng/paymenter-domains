<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
final class ManagedPublicObservation extends PublicDnsObservation
{
    protected $table = 'domains_managed_public_observations';
    protected $hidden = ['facts', 'source_digest', 'digest', 'account_fingerprint'];
    protected static function booted(): void
    {
        parent::booted();
        static::creating(static function(self $row):void {
            if ($row->ns_confirmed && ($row->check_kind!=='delegation' || $row->result!=='complete' || $row->dns===null)) {
                throw new \DomainException('NS confirmation requires complete delegation facts.');
            }
            if (($row->profile_id === null || $row->profile_revision === null)
                && ($row->result !== 'unknown' || $row->dns !== null || $row->website !== null || $row->email !== null)) {
                throw new \DomainException('A configured profile is required for known managed DNS facts.');
            }
        });
    }
    protected function casts(): array { return ['ns_confirmed' => 'boolean']; }
}
