<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Model;

class Price extends Model
{
    use InvalidatesStorefront;

    use HasAccountBinding;

    protected $table = 'domains_prices';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint', 'wholesale', 'source', 'eligibility'];
    protected $casts = ['wholesale' => 'decimal:8', 'retail' => 'decimal:8', 'revision' => 'integer', 'term' => 'integer', 'eligibility' => 'array', 'observed_at' => 'immutable_datetime', 'fresh_until' => 'immutable_datetime'];
    protected static function booted(): void
    {
        static::updating(static function (self $price): void {
            if ($price->isDirty(['tld', 'action', 'term', 'currency'])
                || ($price->isDirty() && $price->revision <= (int) $price->getOriginal('revision'))) {
                throw new \DomainException('Price identity is retained and revisions must advance.');
            }
        });
        static::deleting(static function (): never { throw new \DomainException('Current price identities are retained.'); });
    }
}
