<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Model;

class SalesPolicy extends Model
{
    use InvalidatesStorefront;

    private static int $writeDepth = 0;
    protected $table = 'domains_sales_policies';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint'];
    protected $casts = ['revision' => 'integer', 'server_id' => 'integer', 'register_enabled' => 'boolean', 'transfer_enabled' => 'boolean'];

    /** @internal SellerPolicies only; privileged PHP scope, never a form input authorization API. */
    public static function withPolicyWrite(\Closure $write): mixed
    {
        self::$writeDepth++;
        try { return $write(); } finally { self::$writeDepth--; }
    }

    protected static function booted(): void
    {
        static::saving(static function (self $policy): void {
            if (self::$writeDepth === 0 || ($policy->exists && ($policy->isDirty(['id', 'tld'])
                || $policy->revision !== (int) $policy->getOriginal('revision') + 1))) {
                throw new \DomainException('Seller policy requires locked service and advancing revision.');
            }
        });
        static::deleting(static function (): never { throw new \DomainException('Sales policies are retained.'); });
    }
}
