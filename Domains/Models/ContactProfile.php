<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable owner/account mapping. Contact changes allocate a new profile in slice 30. */
final class ContactProfile extends Model
{
    protected $table = 'domains_contact_profiles';
    protected $guarded = [];
    protected $hidden = ['user_id', 'server_id', 'account_fingerprint', 'profile_fingerprint', 'details'];
    protected $casts = ['details' => 'encrypted:array'];
    private static bool $recording = false;
    protected static function booted(): void
    {
        static::creating(static function (): void { if (!self::$recording) { throw new \DomainException('Profile creation requires accepted evidence.'); } });
        static::updating(static function (): never { throw new \DomainException('Contact profiles are immutable.'); });
        static::deleting(static function (): never { throw new \DomainException('Contact profiles are retained.'); });
    }
    public static function recording(\Closure $write): mixed
    {
        $previous = self::$recording; self::$recording = true;
        try { return $write(); } finally { self::$recording = $previous; }
    }
}
