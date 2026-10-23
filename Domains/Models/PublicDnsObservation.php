<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
/** Append-only normalized public evidence. Only the observation service writes these rows. */
abstract class PublicDnsObservation extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['facts', 'source_digest', 'digest'];
    protected $casts = ['facts' => 'encrypted:array', 'started_at' => 'integer', 'completed_at' => 'integer', 'valid_until' => 'integer',
        'website' => 'boolean', 'email' => 'boolean', 'mixed' => 'boolean', 'partial' => 'boolean'];
    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if (DB::transactionLevel() < 1 || !in_array($row->check_kind, ['delegation', 'points'], true) ||
                !in_array($row->result, ['complete', 'partial', 'unknown'], true) ||
                !in_array($row->dns, [null, 'platform', 'external'], true) || !is_array($row->facts) || ($row->facts['version'] ?? null) !== 1 ||
                $row->completed_at < $row->started_at || $row->completed_at >= $row->started_at + 45 ||
                ($row->result === 'unknown' && $row->valid_until !== null)) { throw new \DomainException('Invalid public DNS observation.'); }
        });
        static::updating(static function (): never { throw new \DomainException('DNS observations are immutable.'); });
        static::deleting(static function (): never { throw new \DomainException('DNS history is retained.'); });
    }
    protected function incrementOrDecrement($column, $amount, $extra, $method): never { throw new \DomainException('DNS observations are immutable.'); }
}
