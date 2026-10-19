<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;

/** Append-only associations. Native owner/service/import validation belongs to the future linker. */
final class HistoryLink extends Model
{
    public $timestamps = false;
    protected $table = 'domains_history_links';
    protected $guarded = [];
    protected $hidden = ['link_key', 'evidence', 'reason'];
    protected $casts = ['evidence' => 'encrypted:array', 'linked_at' => 'immutable_datetime'];
    public static function keyFor(int $domainId, int $invoiceId, ?int $itemId): string
    { return hash('sha256', json_encode([$domainId, $invoiceId, $itemId], JSON_THROW_ON_ERROR)); }
    protected function incrementOrDecrement($column, $amount, $extra, $method): never
    { throw new \DomainException('Invoice history links are append-only.'); }
    private static function positiveId(mixed $value): bool
    { return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
        && filter_var($value, FILTER_VALIDATE_INT) !== false; }
    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            foreach (['domain_id', 'service_id', 'user_id', 'invoice_id', 'linked_by'] as $key) {
                if (!self::positiveId($row->$key)) { throw new \DomainException('History identity is required.'); }
            }
            if (($row->invoice_item_id !== null && !self::positiveId($row->invoice_item_id))
                || !in_array($row->source, ['native_service', 'staff_verified_import'], true)
                || trim($row->reason ?? '') === '' || !$row->linked_at || !is_array($row->evidence)
                || ($row->evidence['version'] ?? null) !== 1) {
                throw new \DomainException('Invalid invoice history record.');
            }
            $key = self::keyFor((int) $row->domain_id, (int) $row->invoice_id, $row->invoice_item_id === null ? null : (int) $row->invoice_item_id);
            if ($row->link_key !== null && $row->link_key !== $key) { throw new \DomainException('Invoice history identity mismatch.'); }
            $row->link_key = $key;
        });
        static::updating(static function (): never { throw new \DomainException('Invoice history links are append-only.'); });
        static::deleting(static function (): never { throw new \DomainException('Invoice history links are retained.'); });
    }
}
