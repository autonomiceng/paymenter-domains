<?php
// Adapted from Enom d2c55e2 Models/Operation.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    use HasAccountBinding;

    protected $table = 'domains_operations';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint', 'payload', 'policy', 'cost', 'attempt', 'funding', 'funding_reason', 'authorized_by', 'request_fingerprint'];
    protected $casts = ['attempt' => 'encrypted:array', 'funding' => 'encrypted:array', 'authority_generation' => 'integer', 'payload' => 'encrypted:array', 'policy' => 'encrypted:array', 'cost' => 'encrypted:array', 'amount' => 'decimal:2', 'base_expires_at' => 'immutable_date', 'expected_expires_at' => 'immutable_date', 'confirmed_expires_at' => 'immutable_date', 'lease_expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(static function (self $operation): void {
            if ($operation->isDirty(['id', 'domain_id', 'service_id', 'server_id', 'registrar_key', 'account_fingerprint', 'authority_generation', 'action'])) {
                throw new \DomainException('Operation binding and action are immutable.');
            }
        });
        static::deleting(static function (): never { throw new \DomainException('Operation ledger rows are retained.'); });
    }

    public function domain() { return $this->belongsTo(Domain::class); }
}
