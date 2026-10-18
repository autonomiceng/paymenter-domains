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
            if ($operation->isDirty(['id', 'domain_id', 'service_id', 'server_id', 'registrar_key', 'account_fingerprint', 'authority_generation', 'action', 'term', 'currency', 'amount', 'invoice_id', 'invoice_item_id', 'operation_key', 'funding_basis'])) {
                throw new \DomainException('Operation binding and action are immutable.');
            }
        });
        static::updating(static function (self $operation): void {
            $old = $operation->getOriginal('policy') ?? []; $new = $operation->policy ?? [];
            foreach (['checkout', 'price', 'seller_revision'] as $key) {
                if (array_key_exists($key, $old) && ($new[$key] ?? null) !== $old[$key]) { throw new \DomainException('Checkout evidence is frozen.'); }
            }
            $old = $operation->getOriginal('cost') ?? []; $new = $operation->cost ?? [];
            foreach (['estimated', 'currency', 'unit', 'actual'] as $key) {
                if (array_key_exists($key, $old) && ($new[$key] ?? null) !== $old[$key]) { throw new \DomainException('Estimated cost evidence is frozen.'); }
            }
        });
        static::updating(static fn (self $operation) => \Paymenter\Extensions\Servers\Domains\Services\ContactSetup::guardSnapshot($operation));
        static::updating(static function (self $operation): void {
            $before = $operation->getOriginal('policy') ?? []; $after = $operation->policy ?? [];
            if (array_key_exists('contact_disposition', $before)
                && (!array_key_exists('contact_disposition', $after) || $after['contact_disposition'] !== $before['contact_disposition'])) {
                throw new \DomainException('Contact disposition is frozen.');
            }
            $history = $before['reconciliation_resumptions'] ?? []; $next = $after['reconciliation_resumptions'] ?? [];
            if (!is_array($history) || !is_array($next) || !array_is_list($next) || count($next) > 20
                || count($next) < count($history) || array_slice($next, 0, count($history)) !== $history) { throw new \DomainException('Reconciliation resumptions are append-only.'); }
            if (array_key_exists('contact_readmissions', $before) || array_key_exists('contact_readmissions', $after)) {
                $history = array_key_exists('contact_readmissions', $before) ? $before['contact_readmissions'] : []; $next = $after['contact_readmissions'] ?? null;
                if (!is_array($history) || !array_is_list($history) || !is_array($next) || !array_is_list($next)
                    || count($next) > 10 || count($next) < count($history) || array_slice($next, 0, count($history)) !== $history) {
                    throw new \DomainException('Contact readmissions are append-only and bounded.');
                }
            }
            $old = $operation->getOriginal('attempt') ?? []; $new = $operation->attempt ?? [];
            if (!array_key_exists('registration_admission', $old) && array_key_exists('registration_admission', $new)
                && (($old['seal'] ?? null) !== 'absent' || ($new['seal'] ?? null) !== 'sealed' || $operation->action !== 'register')) {
                throw new \DomainException('Registration admission can only be recorded at the original seal.');
            }
            foreach (['claim_key', 'dispatch_attempted_at', 'authorized_cost', 'registration_admission', 'execution', 'evidence', 'charge', 'order_candidates', 'registration', 'corrections', 'reconciliation', 'terminal'] as $key) {
                if (array_key_exists($key, $old) && (!array_key_exists($key, $new) || $new[$key] !== $old[$key])) {
                    throw new \DomainException('Attempt evidence is write-once.');
                }
            }
            if (isset($old['claim_key']) && $operation->isDirty(['started_at', 'request_fingerprint'])) { throw new \DomainException('Original attempt identity is frozen.'); }
            if (isset($old['reconciliation']) && $operation->isDirty(['provider_order_id', 'confirmed_expires_at', 'completed_at'])) { throw new \DomainException('Completion evidence is frozen.'); }
            if (($old['seal'] ?? null) === 'sealed' && $operation->isDirty('payload')) {
                throw new \DomainException('Sealed desired state is frozen.');
            }
            foreach (['claim', 'seal'] as $key) {
                $before = $operation->getOriginal('funding') ?? []; $after = $operation->funding ?? [];
                if (array_key_exists($key, $before) && (!array_key_exists($key, $after) || $after[$key] !== $before[$key])) {
                    throw new \DomainException('Claim funding evidence is frozen.');
                }
            }
            if (($old['seal'] ?? null) === 'sealed' && ($new['seal'] ?? null) !== 'sealed') {
                throw new \DomainException('Dispatch seals cannot be removed.');
            }
        });
        static::deleting(static function (): never { throw new \DomainException('Operation ledger rows are retained.'); });
    }

    public function domain() { return $this->belongsTo(Domain::class); }
}
