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
    protected $casts = ['reason' => \Paymenter\Extensions\Servers\Domains\Casts\LegacyEncryptedString::class, 'data' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    protected static function booted(): void
    {
        static::updating(static function (): never { throw new \DomainException('Watch audit is append-only.'); });
        static::deleting(static function (): never { throw new \DomainException('Watch audit is retained.'); });
    }
}
