<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
final class WatchAudit extends Model
{
    public $timestamps = false;
    protected $table = 'domains_watch_audit';
    protected $guarded = [];
    protected $hidden = ['data', 'reason'];
    protected $casts = ['reason' => \Paymenter\Extensions\Servers\Domains\Casts\LegacyEncryptedString::class, 'data' => 'encrypted:array'];
    /** Unmarked retained wall times keep their original local read contract; their zone remains unknown. */
    public function getCreatedAtAttribute(string $value): \Carbon\CarbonImmutable
    { return \Carbon\CarbonImmutable::parse($value,($this->attributes['time_zone']??null)==='UTC'?'UTC':date_default_timezone_get()); }
    /** Date objects carry their own zone. Offset-free caller strings retain the site's normal time meaning. */
    public function setCreatedAtAttribute(\DateTimeInterface|string $value): void
    {
        $at=$value instanceof \DateTimeInterface?\Carbon\CarbonImmutable::instance($value):\Carbon\CarbonImmutable::parse($value,date_default_timezone_get());
        $this->attributes['created_at']=$at->utc()->format('Y-m-d H:i:s');
        $this->attributes['time_zone']='UTC';
    }
    protected static function booted(): void
    {
        static::creating(static function(self $row): void {
            if(!isset($row->attributes['created_at'])){$row->created_at=now();}
            $row->attributes['time_zone']='UTC';
        });
        static::updating(static function (): never { throw new \DomainException('Watch audit is append-only.'); });
        static::deleting(static function (): never { throw new \DomainException('Watch audit is retained.'); });
    }
}
