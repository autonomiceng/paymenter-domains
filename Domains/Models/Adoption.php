<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Internal storage, not verified adoption authority. No application workflow consumes these rows. */
final class Adoption extends Model
{
    protected $table = 'domains_adoptions';
    protected $guarded = [];
    protected $hidden = ['request_key', 'request_digest', 'account_fingerprint', 'evidence', 'reason'];
    protected $casts = ['evidence' => 'encrypted:array', 'do_not_renew' => 'boolean', 'authority_generation' => 'integer',
        'observed_at' => 'immutable_datetime', 'valid_until' => 'immutable_datetime', 'committed_at' => 'immutable_datetime'];
    protected function incrementOrDecrement($column, $amount, $extra, $method): never
    { throw new \DomainException('Adoption changes require an explicit save.'); }
    private static function positiveId(mixed $value): bool
    { return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
        && filter_var($value, FILTER_VALIDATE_INT) !== false; }
    protected static function booted(): void
    {
        static::saving(static function (self $row): void {
            if (DB::transactionLevel() < 1) { throw new \DomainException('Adoption writes require a transaction.'); }
            if (!$row->exists && !in_array($row->state, ['preview', 'refused'], true)) {
                throw new \DomainException('Adoption must start as a preview or refusal.');
            }
            if ($row->exists) {
                $saved = $row->newQuery()->whereKey($row->getRawOriginal('id'))->lockForUpdate()->firstOrFail();
                $changed = false;
                foreach ($saved->getRawOriginal() as $key => $value) {
                    $original = $row->getRawOriginal($key);
                    // PDO scalar representations vary; ciphertext and nulls still compare exactly.
                    $changed = $changed || ($key === 'do_not_renew' ? (bool) $value !== (bool) $original
                        : ($value === null || $original === null ? $value !== $original : (string) $value !== (string) $original));
                }
                if ($changed || $saved->state !== 'preview') {
                    throw new \DomainException('Terminal or changed adoption requests are immutable.');
                }
                $allowed = ['state', 'domain_id', 'authority_generation', 'committed_at', 'cancellation_id', 'updated_at'];
                if (array_diff(array_keys($row->getDirty()), $allowed) !== [] || $row->state === 'preview'
                    || ($saved->domain_id !== null && (string) $row->domain_id !== (string) $saved->domain_id)
                    || ($row->isDirty('domain_id') && $row->state !== 'committed')) {
                    throw new \DomainException('Adoption request identity and evidence are frozen.');
                }
            }
            if (!in_array($row->state, ['preview', 'committed', 'refused', 'superseded'], true)
                || !in_array($row->origin, ['staff', 'converter_pending'], true)
                || !in_array($row->registrar_key, ['enom', 'namesilo'], true)
                || !in_array($row->environment, ['test', 'live'], true)
                || !preg_match('/^[a-f0-9]{64}$/D', $row->request_digest ?? '')
                || !preg_match('/^[a-f0-9]{64}$/D', $row->account_fingerprint ?? '')
                || !is_string($row->request_key) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/D', $row->request_key)
                || trim($row->reason ?? '') === '' || !is_array($row->evidence)
                || ($row->evidence['version'] ?? null) !== 1
                || ($row->evidence['registrar_key'] ?? null) !== $row->registrar_key
                || ($row->evidence['environment'] ?? null) !== $row->environment
                || !$row->observed_at || !$row->valid_until || $row->valid_until <= $row->observed_at) {
                throw new \DomainException('Invalid adoption storage record.');
            }
            foreach (['service_id', 'user_id', 'server_id', 'actor_id'] as $key) {
                if (!self::positiveId($row->$key)) { throw new \DomainException('Adoption identity is required.'); }
            }
            if ($row->domain_id !== null && !self::positiveId($row->domain_id)) { throw new \DomainException('Invalid adoption domain.'); }
            if ($row->state === 'committed') {
                if (!self::positiveId($row->domain_id) || !self::positiveId($row->getAttributes()['authority_generation'] ?? null) || !$row->committed_at
                    || ($row->do_not_renew ? !self::positiveId($row->cancellation_id) : $row->cancellation_id !== null)
                    || ($row->evidence['resulting_generation'] ?? null) !== $row->authority_generation) {
                    throw new \DomainException('Committed adoption requires its domain and generation.');
                }
            } elseif ($row->authority_generation !== null || $row->committed_at !== null || $row->cancellation_id !== null) {
                throw new \DomainException('Only committed adoptions reserve a generation.');
            }
        });
        static::deleting(static function (): never { throw new \DomainException('Adoption evidence is retained.'); });
    }
}
