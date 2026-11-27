<?php
// Adapted from Enom d2c55e2 Models/Operation.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementAttempt;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AcquisitionResultSchema, AttemptFacts, AttemptToken, OperationAttempts, OperationSchema};

class Operation extends Model
{
    use GuardsOperation, HasAccountBinding, HasManagementAttempt;

    /** Transaction-local capability evidence; never an attribute, relationship or persisted authority. */
    public ?Tld $catalogueTld = null;
    /** Exact worker authority, set by the claim or seal transaction and never persisted. */
    public ?AttemptToken $acquisitionWorker = null;
    /** @var array{facts: AttemptFacts, action: string, start: ?\DateTimeImmutable}|array{}|null */
    private ?array $acquisitionRow = null;
    private AcquisitionAttempt|\Throwable|null $acquisitionAttempt = null;
    private bool $acquisitionLoaded = false;
    private ?array $terminalProof = null;
    private ?string $terminalCiphertext = null;
    private bool $noAcquisitionStep = false;
    private bool $validatingUpdate = false;

    protected $table = 'domains_operations';
    protected $guarded = [];
    // Keep the first attempt's schema default on the model returned by create().
    protected $attributes = ['retry_number' => 0];
    protected $hidden = ['account_fingerprint', 'payload', 'policy', 'cost', 'attempt', 'renewal_previous_proof', 'transition_revision', 'funding', 'funding_reason', 'authorized_by', 'request_fingerprint', 'management_pending'];
    protected $casts = ['transition_revision' => 'integer', 'retry_number'=>'integer', 'attempt' => 'encrypted:array', 'funding' => 'encrypted:array', 'authority_generation' => 'integer', 'payload' => 'encrypted:array', 'policy' => 'encrypted:array', 'cost' => 'encrypted:array', 'amount' => 'decimal:2', 'base_expires_at' => 'immutable_date', 'expected_expires_at' => 'immutable_date', 'confirmed_expires_at' => 'immutable_date', 'lease_expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    /**
     * The retained attempt record. A renewal's attempt lives in its attempt and evidence rows, so its retired column reads
     * as empty and is never decrypted: an unreadable copy cannot block a refund, a save guard or any other reader.
     * Writes still encrypt through the cast.
     */
    protected function attempt(): Attribute
    {
        return $this->retainedEvidence('attempt');
    }

    protected function funding(): Attribute { return $this->retainedEvidence('funding'); }
    protected function policy(): Attribute { return $this->retainedEvidence('policy'); }

    /** @return Attribute */
    private function retainedEvidence(string $field)
    {
        return Attribute::make(
            get: function (?string $value, array $row) use ($field): ?array {
                return $this->projectEvidence($field, $value, $row);
            },
            set: function (?array $value, array $row) use ($field): ?string {
                $value = $this->keepManagementEvidence($field, $value);
                $terminal = $field === 'attempt' ? $this->acquisitionTerminal($row) : [];
                if ($attempt = $this->acquisition($row)) {
                    $value = $attempt->keepOperationFacts($field, array_replace($this->acquisitionEvidence($field, $row), $terminal), $value);
                } elseif ($terminal) {
                    $value = AcquisitionAttempt::retain($terminal, $value);
                }
                return $value === null ? null : $this->castAttributeAsEncryptedString($field, $this->asJson($value));
            },
        );
    }

    private function projectEvidence(string $field, ?string $value, array $row): ?array
    {
        if ($field === 'attempt' && ($row['action'] ?? null) === 'renew') { return []; }
        if ($field === 'attempt' && ($management = $this->managementAttempt())) { return $management->attempt; }
        $stored = $this->decodeEvidence($value);
        $projection = $this->acquisitionEvidence($field, $row);
        if ($field === 'attempt') { $projection = array_replace($projection, $this->acquisitionTerminal($row)); }
        $projection = array_replace($projection, $this->managementProjection($field));
        return $projection ? array_replace($stored ?? [], $projection) : $stored;
    }

    private function acquisitionTerminal(array $row): array
    {
        if (!isset($row['id']) || ($row['state'] ?? null) !== 'rejected' || !AcquisitionAttempt::key($row['action'] ?? '')) { return []; }
        if ($this->terminalCiphertext !== null) {
            $terminal = OperationAttempts::decodeEvidence($this->terminalCiphertext);
        } else {
            $proof = $this->terminalProof ??= OperationAttempts::evidence((int) $row['id'], null, 'terminal') ?? [];
            if ($proof === OperationAttempts::UNREADABLE) {
                throw new \Illuminate\Contracts\Encryption\DecryptException('Acquisition terminal proof is unreadable.');
            }
            $terminal = in_array($proof['outcome'] ?? null, ['definitely_unsent', 'unpaid_closed'], true) ? $proof['evidence'] : null;
        }
        return $terminal === null ? [] : ['seal'=>'absent', 'send_state'=>'definitely_unsent', 'disposition'=>'rejected',
            'rejection_basis'=>'definitely_unsent', 'terminal'=>$terminal];
    }

    private function decodeEvidence(?string $value): ?array
    {
        return $value === null ? null : $this->fromJson($this->fromEncryptedString($value));
    }

    /** Receipt returns are owned by the operation, independently of claim and seal funding. */
    public function receiptFunding(): array
    {
        return $this->decodeEvidence($this->attributes['funding'] ?? null) ?? [];
    }

    /** Operation policy without projected registrar attempt history. */
    public function operationPolicy(): array
    {
        return $this->decodeEvidence($this->attributes['policy'] ?? null) ?? [];
    }

    public function getOriginal($key = null, $default = null)
    {
        if ($this->validatingUpdate && in_array($key, ['attempt', 'funding', 'policy'], true)) {
            // The save checks compare both versions against the same retained attempt.
            return $this->projectEvidence($key, Arr::get($this->original, $key, $default), $this->original);
        }
        return parent::getOriginal($key, $default);
    }

    /** Read step presence with the operation when the caller has a current view of the ledger. */
    public function scopeWithAcquisitionStep(Builder $query): Builder
    {
        return $query->addSelect([
            'has_acquisition_step' => DB::table(OperationSchema::STEPS)
                ->select('id')->whereColumn('operation_id', 'domains_operations.id')
                ->whereIn('step_type', ['register', 'transfer'])->where('sequence', 1)
                ->whereColumn('step_type', 'domains_operations.action')->limit(1),
            'acquisition_terminal_evidence' => DB::table(OperationSchema::EVIDENCE)
                ->select('evidence')->whereColumn('operation_id', 'domains_operations.id')->whereNull('attempt_id')
                ->where('kind', 'terminal')->whereIn('outcome', ['definitely_unsent', 'unpaid_closed'])
                ->whereIn('domains_operations.action', ['register', 'transfer'])->where('domains_operations.state', 'rejected')
                ->orderBy('id')->limit(1),
        ]);
    }

    /** Step absence loaded by withAcquisitionStep while the holding's writers are serialized. */
    public function acquisitionStepAbsent(): bool
    {
        return $this->noAcquisitionStep;
    }

    private function acquisitionEvidence(string $field, array $row): array
    {
        $attempt = $this->acquisition($row);
        if (!$attempt) { return []; }
        return match ($field) {
            'attempt' => $attempt->attempt(),
            'funding' => $attempt->funding(),
            'policy' => ($answer = $attempt->answer()) ? ['enom_answer' => $answer] : [],
        };
    }

    private function acquisitionRow(array $row): array
    {
        if ($this->noAcquisitionStep
            || !isset($row['id'], $row['action'], $row['transition_revision'])
            || AcquisitionAttempt::key($this->getRawOriginal('action') ?? $row['action']) === null) { return []; }
        $id = (int) $row['id'];
        return $this->acquisitionRow ??= OperationAttempts::latestAcquisitions([$id])[$id] ?? [];
    }

    public function acquisitionAttemptFacts(): ?AttemptFacts
    { return $this->acquisitionRow($this->attributes)['facts'] ?? null; }

    public function acquisitionSealWindow(): ?array
    { return $this->acquisition($this->attributes)?->sealedWindow(); }

    public function contactRecheckSince(): ?\DateTimeImmutable
    { return $this->acquisition($this->attributes)?->contactRecheckSince(); }

    private function acquisition(array $row): ?AcquisitionAttempt
    {
        if (!($facts = $this->acquisitionRow($row))) { return null; }
        if (!$this->acquisitionLoaded) {
            $id = (int) $row['id'];
            $this->acquisitionAttempt = AcquisitionAttempt::many([$id => $facts])[$id];
            $this->acquisitionLoaded = true;
        }
        if ($this->acquisitionAttempt instanceof \Throwable) { throw $this->acquisitionAttempt; }
        return $this->acquisitionAttempt;
    }

    /** Batch the attempt rows for staff readers. Date-only readers need no encrypted evidence. */
    public static function loadAcquisitionAttempts(iterable $operations, bool $withEvidence = true, bool $lock = false): void
    {
        $pending = $terminal = [];
        foreach ($operations as $op) {
            if ($withEvidence && $op->terminalProof === null && $op->terminalCiphertext === null && $op->state === 'rejected' && $op->getRawOriginal('id') !== null
                && AcquisitionAttempt::key((string) $op->getRawOriginal('action')) !== null) {
                $terminal[(int) $op->id][] = $op;
            }
            if ($op->noAcquisitionStep) { continue; }
            if (!$op->acquisitionLoaded && $op->getRawOriginal('id') !== null
                && AcquisitionAttempt::key((string) $op->getRawOriginal('action')) !== null) {
                $pending[(int) $op->id][] = $op;
            }
        }
        $rows = OperationAttempts::latestAcquisitions(array_keys($pending), $lock);
        $attempts = $withEvidence ? AcquisitionAttempt::many($rows, $lock) : [];
        foreach ($pending as $id => $models) {
            foreach ($models as $op) {
                $op->acquisitionRow = $rows[$id] ?? [];
                $op->acquisitionAttempt = $attempts[$id] ?? null;
                $op->acquisitionLoaded = $withEvidence;
            }
        }
        $proofs = OperationAttempts::evidenceMany(array_keys($terminal), ['terminal'], $lock);
        foreach ($terminal as $id => $models) {
            foreach ($models as $op) { $op->terminalProof = $proofs[$id][0]['terminal'] ?? []; }
        }
    }

    /** Called after a writer transition on this model's locked operation. */
    public function reloadAcquisitionAttempt(): void
    {
        $this->acquisitionRow = null;
        $this->acquisitionAttempt = null;
        $this->acquisitionLoaded = false;
        $this->terminalProof = null;
        $this->terminalCiphertext = null;
        $this->noAcquisitionStep = false;
    }

    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->reloadAcquisitionAttempt();
        $this->reloadManagementAttempt();
        $this->noAcquisitionStep = array_key_exists('has_acquisition_step', $attributes) && $attributes['has_acquisition_step'] === null;
        $this->terminalCiphertext = $attributes['acquisition_terminal_evidence'] ?? null;
        if (array_key_exists('acquisition_terminal_evidence', $attributes) && $this->terminalCiphertext === null
            && ($attributes['state'] ?? null) === 'rejected') { $this->terminalProof = []; }
        unset($attributes['has_acquisition_step'], $attributes['acquisition_terminal_evidence']);
        return parent::setRawAttributes($attributes, $sync);
    }

    protected function startedAt(): Attribute
    {
        return Attribute::get(function ($value, array $row) {
            $acquisition = $this->acquisitionRow($row);
            $start = $acquisition ? $acquisition['start'] : ($this->currentManagementFacts()?->claimedAt ?? $value);
            return $start === null ? null : $this->asDateTime($start)->toImmutable();
        })->withoutObjectCaching();
    }

    protected function requestFingerprint(): Attribute
    {
        return Attribute::get(fn ($value, array $row) => ($attempt = $this->acquisition($row)) ? $attempt->digest() : $value);
    }

    protected function leaseExpiresAt(): Attribute
    {
        return Attribute::get(function ($value, array $row) {
            $facts = $this->acquisitionRow($row)['facts'] ?? $this->currentManagementFacts();
            if (!$facts) { return $value === null ? null : $this->asDateTime($value)->toImmutable(); }
            return ($row['state'] ?? null) === 'running' && $facts->current && $facts->claimUntil !== null
                ? $this->asDateTime($facts->claimUntil)->toImmutable() : null;
        })->withoutObjectCaching();
    }

    protected function performInsert(Builder $query)
    {
        if (AcquisitionAttempt::key((string) $this->action) === null) { return parent::performInsert($query); }

        return $this->getConnection()->transaction(function () use ($query): bool {
            if (!parent::performInsert($query)) { return false; }
            AcquisitionResultSchema::insert((int) $this->id);
            return true;
        });
    }

    protected function performUpdate(Builder $query)
    {
        $this->validatingUpdate = true;
        try {
            $action = (string) $this->getRawOriginal('action');
            return match (true) {
                AcquisitionAttempt::key($action) !== null => $this->updateAcquisition($query),
                ManagementAttempt::applies($action) => $this->updateManagement($query),
                default => parent::performUpdate($query),
            };
        } finally {
            $this->validatingUpdate = false;
        }
    }

    private function updateAcquisition(Builder $query): bool
    {
        $dirty = $this->getDirty();
        $identity = array_intersect_key($dirty, array_flip(['started_at', 'request_fingerprint', 'lease_expires_at']));
        $payload = array_key_exists('payload', $dirty);
        $claimFacts = [];
        foreach (array_intersect_key($dirty, ['attempt' => true, 'funding' => true]) as $field => $value) {
            // The setter strips projected facts. A receipt-only update has no claim authority to write.
            $facts = AcquisitionAttempt::operationClaimFacts($field, $this->decodeEvidence($value))
                + AcquisitionAttempt::operationClaimFacts($field, $this->decodeEvidence($this->getRawOriginal($field)));
            if ($facts) { $claimFacts[$field] = $facts; }
        }
        if (!$identity && !$payload && !$claimFacts) { return parent::performUpdate($query); }

        $connection = $this->getConnection();
        $write = function () use ($query, $identity, $payload, $claimFacts): bool {
            // The attempt writer holds the same operation lock. Read through old snapshots before saving.
            $this->newModelQuery()->whereKey($this->getKeyForSaveQuery())->lockForUpdate()->firstOrFail(['id']);
            $this->reloadAcquisitionAttempt();
            self::loadAcquisitionAttempts([$this], lock: true);
            if ($attempt = $this->acquisition($this->original)) {
                if ($identity || ($payload && $attempt->sealed())) {
                    throw new \DomainException('Acquisition authority belongs to its operation attempt.');
                }
                foreach ($claimFacts as $field => $facts) { $attempt->guardOperationClaimFacts($field, $facts); }
            }
            return parent::performUpdate($query);
        };
        return $connection->transactionLevel() ? $write() : $connection->transaction($write);
    }

    public function invoice() { return $this->belongsTo(\App\Models\Invoice::class); }
    public function domain() { return $this->belongsTo(Domain::class); }
}
