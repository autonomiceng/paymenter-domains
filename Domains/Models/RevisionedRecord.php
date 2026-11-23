<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
/** Internal writes require an enclosing transaction and exact optimistic revision. SQL/quiet writes are privileged maintenance. */
abstract class RevisionedRecord extends Model
{
    protected $guarded = [];
    protected $attributes = ['revision' => 1];
    protected $casts = ['revision' => 'integer'];
    protected array $identity = ['id', 'user_id'];
    abstract protected function validateRecord(): void;
    protected function incrementOrDecrement($column, $amount, $extra, $method): never
    { throw new \DomainException('Direct numeric updates require an explicit revisioned save.'); }
    protected static function booted(): void
    {
        static::saving(static function (self $row): void {
            if (DB::transactionLevel() < 1) { throw new \DomainException('Revisioned writes require a transaction.'); }
            if (!$row->exists && $row->revision !== 1) { throw new \DomainException('New records start at revision one.'); }
            if ($row->exists) {
                foreach ($row->identity as $key) { if ($row->isDirty($key)) { throw new \DomainException('Record identity is immutable.'); } }
                $saved = $row->newQuery()->whereKey($row->getRawOriginal($row->getKeyName()))->lockForUpdate()->firstOrFail();
                if ((int) $saved->revision !== (int) $row->getRawOriginal('revision') || $row->revision !== $saved->revision + 1) {
                    throw new \DomainException('The saved record revision changed.');
                }
            }
            $row->validateRecord();
        });
        static::deleting(static function (): never { throw new \DomainException('Record history is retained.'); });
    }
}
