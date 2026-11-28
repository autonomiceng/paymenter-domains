<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Data\SendState;

/** Reads steps, attempts and evidence. Callers that act on a result hold the operation lock. */
final class OperationAttempts
{
    /** The json_decode depth of stored evidence. The writer refuses deeper evidence before it is stored. */
    public const EVIDENCE_DEPTH = 32;

    /** What evidenceMany() gives for a row that no longer decrypts. */
    public const UNREADABLE = ['unreadable' => true];

    public static function definitelyUnsent(AttemptFacts $attempt): bool
    {
        return $attempt->sendState === SendState::DefinitelyUnsent
            && ($attempt->sealed() || $attempt->grantConsumedAt === null)
            && !self::unresolvedRead($attempt);
    }

    public static function unresolvedRead(AttemptFacts $attempt): bool
    { return $attempt->readToken !== null || $attempt->readUntil !== null || $attempt->readUnresolved; }

    public static function releasedUnsent(AttemptFacts $attempt, bool $lock = false): bool
    {
        return self::orderedRelease($attempt)
            && ($attempt->sealed() ? self::locallyRefused($attempt, $lock) : self::unsealedUnsent($attempt, $lock));
    }

    private static function orderedRelease(AttemptFacts $attempt): bool
    {
        return $attempt->released && !$attempt->current && $attempt->claimedAt !== null
            && $attempt->releasedAt !== null && $attempt->releasedAt >= $attempt->claimedAt;
    }

    public static function unsealedUnsent(AttemptFacts $attempt, bool $lock): bool
    {
        return !$attempt->sealed() && self::definitelyUnsent($attempt)
            && !self::hasAcquisitionSettlementEvidence($attempt->token, $lock, unsealed: true);
    }

    /** Only the transport's durable local refusal proves the physical grant was unused. */
    public static function locallyRefused(AttemptFacts $attempt, bool $lock = false): bool
    {
        if (!$attempt->sealed() || $attempt->claimedAt === null || $attempt->sealedAt < $attempt->claimedAt
            || !self::definitelyUnsent($attempt)) { return false; }
        $token = $attempt->token;
        $reply = self::evidence($token->operationId, $token->attemptId, 'reply', $lock);
        return ($reply['source'] ?? null) === 'local_refusal' && ($reply['outcome'] ?? null) === 'unsent'
            && !self::hasAcquisitionSettlementEvidence($token, $lock);
    }

    /** Null means there is no purchase step. Every retained attempt must independently prove no send. */
    public static function unsentPurchase(int $operationId, StepKey $key, bool $lock = false): ?bool
    {
        $step = self::step($operationId, $key, $lock);
        if (!$step) { return null; }
        $rows = DB::table(OperationSchema::ATTEMPTS)->where('step_id', $step->id)->orderBy('ordinal')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($step->current_attempt_id !== null && (int) $step->current_attempt_id !== (int) ($rows->last()->id ?? 0)) {
            return false;
        }
        foreach ($rows as $row) {
            $attempt = self::facts($operationId, $step, $row);
            $unsent = $attempt->current
                ? !$attempt->released && self::locallyRefused($attempt, $lock)
                : self::releasedUnsent($attempt, $lock);
            if (!$unsent) {
                return false;
            }
        }
        return true;
    }

    public static function recordedLease(AttemptFacts $attempt): bool
    {
        return $attempt->claimedAt !== null && $attempt->claimUntil !== null && $attempt->claimedAt <= $attempt->claimUntil;
    }

    public static function replaceable(AttemptFacts $attempt, \DateTimeImmutable $now, bool $lock = false): bool
    {
        return self::releasedUnsent($attempt, $lock) || ($attempt->current && !$attempt->released && !$attempt->sealed()
            && self::recordedLease($attempt) && $attempt->claimUntil <= $now && self::unsealedUnsent($attempt, $lock));
    }

    public static function step(int $operationId, StepKey $key, bool $lock = false): ?object
    {
        return DB::table(OperationSchema::STEPS)->where(['operation_id' => $operationId, 'step_type' => $key->type, 'sequence' => $key->sequence])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /** An inconsistent current pointer must not turn a claimed operation into an unattempted one. */
    public static function hasAttempts(int $operationId, StepKey $key, bool $lock = false): bool
    {
        $step = self::step($operationId, $key, $lock);
        return $step !== null && ($step->current_attempt_id !== null
            || DB::table(OperationSchema::ATTEMPTS)->where('step_id', $step->id)
                ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null);
    }

    /** The latest attempt, provided any current pointer names that attempt. */
    public static function latest(int $operationId, StepKey $key, bool $lock = false): ?AttemptFacts
    {
        $step = self::step($operationId, $key, $lock);
        if (!$step) { return null; }
        $row = DB::table(OperationSchema::ATTEMPTS)->where('step_id', $step->id)
            ->orderByDesc('ordinal')->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (!$row || ($step->current_attempt_id !== null && (int) $step->current_attempt_id !== (int) $row->id)) {
            return null;
        }
        return self::facts($operationId, $step, $row);
    }

    /** The current attempt, only while the step points at it. */
    public static function current(int $operationId, StepKey $key, bool $lock = false): ?AttemptFacts
    {
        $facts = self::latest($operationId, $key, $lock);
        return $facts?->current ? $facts : null;
    }

    /** The active sealed attempt. A TEST cap refusal may have retired an earlier seal. */
    public static function sealed(int $operationId, StepKey $key, bool $lock = false): ?AttemptFacts
    {
        $facts = self::latest($operationId, $key, $lock);
        return $facts?->sealed() && !$facts->released ? $facts : null;
    }

    /**
     * Each listed acquisition's current attempt, or latest history when the step has no owner.
     * @return array<int, array{facts: AttemptFacts, action: string, start: ?\DateTimeImmutable}>
     */
    public static function latestAcquisitions(array $operationIds, bool $lock = false): array
    {
        if (!$operationIds) { return []; }
        $rows = DB::table(OperationSchema::STEPS.' as s')->join(OperationSchema::ATTEMPTS.' as a', 'a.step_id', '=', 's.id')
            ->join('domains_operations as o', 'o.id', '=', 's.operation_id')
            ->leftJoin(OperationSchema::EVIDENCE.' as e', fn ($join) => $join->on('e.attempt_id', '=', 'a.id')
                ->where('e.kind', 'test_capacity')->where('e.outcome', 'fresh'))
            ->whereIn('s.operation_id', $operationIds)->whereIn('s.step_type', ['register', 'transfer'])
            ->where('s.sequence', 1)->whereColumn('s.step_type', 'o.action')
            ->where(fn ($q) => $q->whereNull('s.current_attempt_id')->orWhereColumn('a.id', 's.current_attempt_id'))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderByDesc('a.ordinal')->get(['a.*', 's.operation_id as step_operation_id', 's.current_attempt_id as step_current_attempt_id',
                's.step_type', 'e.id as fresh_capacity_refusal']);
        $attempts = [];
        foreach ($rows as $row) {
            $id = (int) $row->step_operation_id;
            $step = (object) ['id' => $row->step_id, 'current_attempt_id' => $row->step_current_attempt_id];
            $attempts[$id] ??= [
                'facts' => self::facts($id, $step, $row), 'action' => $row->step_type,
                'start' => $row->fresh_capacity_refusal === null ? self::time($row->claimed_at) : null,
            ];
        }
        return $attempts;
    }

    /** The latest management attempt of each operation, including released pre-seal history. */
    public static function latestManagement(array $operationIds, bool $lock = false): array
    {
        if (!$operationIds) { return []; }
        $rows = DB::table(OperationSchema::STEPS.' as s')->join(OperationSchema::ATTEMPTS.' as a', 'a.step_id', '=', 's.id')
            ->whereIn('s.operation_id', $operationIds)->where(['s.step_type' => 'management', 's.sequence' => 1])
            ->where(fn ($q) => $q->whereNull('s.current_attempt_id')->orWhereColumn('a.id', 's.current_attempt_id'))
            ->when($lock, fn ($q) => $q->lockForUpdate())->orderByDesc('a.ordinal')
            ->get(['a.*', 's.operation_id as step_operation_id', 's.current_attempt_id as step_current_attempt_id']);
        $attempts = [];
        foreach ($rows as $row) {
            $id = (int) $row->step_operation_id;
            $step = (object) ['id' => $row->step_id, 'current_attempt_id' => $row->step_current_attempt_id];
            $attempts[$id] ??= self::facts($id, $step, $row);
        }
        return $attempts;
    }

    /** Ordered facts for the management projection. Paced recovery keeps a separate, unbounded audit trail. */
    public static function managementEvidence(array $operationIds, bool $lock = false): array
    {
        if (!$operationIds) { return []; }
        $reserve = ManagementEvidenceKind::ReserveRead->value;
        $readback = ManagementEvidenceKind::Readback->value;
        // Lock the aggregate's inputs too so the bounds cannot use an earlier transaction snapshot.
        $recovery = DB::table(OperationSchema::EVIDENCE)->whereIn('operation_id', $operationIds)
            ->where(fn ($q) => $q->where(['kind' => $reserve, 'outcome' => 'recovery'])->orWhere('kind', $readback))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->groupBy('attempt_id')->select('attempt_id')
            ->selectRaw('MIN(CASE WHEN kind = ? THEN id END) AS first_reservation', [$reserve])
            ->selectRaw('MAX(CASE WHEN kind = ? THEN id END) AS last_reservation', [$reserve])
            ->selectRaw('COUNT(CASE WHEN kind = ? THEN 1 END) AS recovery_count', [$reserve])
            ->selectRaw('MAX(CASE WHEN kind = ? THEN id END) AS last_readback', [$readback]);
        // Fast reads precede the first recovery reservation. A failed recovery adds no readback.
        $rows = DB::table(OperationSchema::EVIDENCE.' as e')->whereIn('e.operation_id', $operationIds)
            ->leftJoinSub($recovery, 'r', 'r.attempt_id', '=', 'e.attempt_id')
            ->whereIn('e.kind', [...array_column(ManagementEvidenceKind::cases(), 'value'), 'management_failed', 'admission', 'reply', 'claim', 'terminal'])
            ->where(fn ($q) => $q->whereNull('r.first_reservation')->orWhereNotIn('e.kind', [$reserve, $readback])
                ->orWhereColumn('e.id', '<', 'r.first_reservation')->orWhereColumn('e.id', 'r.last_reservation')
                ->orWhereColumn('e.id', 'r.last_readback'))
            ->when($lock, fn ($q) => $q->lockForUpdate())->orderBy('e.id')->get(['e.*', 'r.recovery_count']);
        $found = [];
        foreach ($rows as $row) {
            try { $proof = self::decoded($row); }
            catch (DecryptException|\JsonException) { $proof = self::UNREADABLE; }
            $found[(int) $row->operation_id][] = ['kind' => $row->kind, 'attempt_id' => (int) $row->attempt_id,
                'recovery_count' => (int) $row->recovery_count, ...$proof];
        }
        return $found;
    }

    /** Of the given operations, those whose step has a sealed attempt. */
    public static function sealedOperations(array $operationIds, StepKey $key): array
    {
        if (!$operationIds) { return []; }
        return DB::table(OperationSchema::STEPS.' as s')->join(OperationSchema::ATTEMPTS.' as a', 'a.step_id', '=', 's.id')
            ->whereIn('s.operation_id', $operationIds)->where(['s.step_type' => $key->type, 's.sequence' => $key->sequence])
            ->whereNotNull('a.sealed_at')->distinct()->pluck('s.operation_id')->map(fn ($id) => (int) $id)->all();
    }

    /** The sealed attempts of the given operations' step, keyed by operation id, in one read. */
    public static function sealedMany(array $operationIds, StepKey $key): array
    {
        if (!$operationIds) { return []; }
        $rows = DB::table(OperationSchema::STEPS.' as s')->join(OperationSchema::ATTEMPTS.' as a', 'a.step_id', '=', 's.id')
            ->whereIn('s.operation_id', $operationIds)->where(['s.step_type' => $key->type, 's.sequence' => $key->sequence])
            ->whereNotNull('a.sealed_at')->get(['a.*', 's.operation_id as step_operation_id', 's.current_attempt_id as step_current_attempt_id']);
        $sealed = [];
        foreach ($rows as $row) {
            $step = (object) ['id' => $row->step_id, 'current_attempt_id' => $row->step_current_attempt_id];
            $sealed[(int) $row->step_operation_id] = self::facts((int) $row->step_operation_id, $step, $row);
        }
        return $sealed;
    }

    public static function attempt(AttemptToken $token, bool $lock = false): ?AttemptFacts
    {
        $step = DB::table(OperationSchema::STEPS)->where('id', $token->stepId)->where('operation_id', $token->operationId)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $row = $step ? DB::table(OperationSchema::ATTEMPTS)->where('id', $token->attemptId)->where('step_id', $step->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        if (!$row || !hash_equals($row->claim_key, $token->claimKey)) { return null; }
        return self::facts($token->operationId, $step, $row);
    }

    /** A negative answer must see any retained proof, even if its payload is unreadable. */
    public static function hasAcquisitionSettlementEvidence(AttemptToken $token, bool $lock = false, bool $unsealed = false): bool
    {
        return DB::table(OperationSchema::EVIDENCE)->where(['operation_id' => $token->operationId, 'attempt_id' => $token->attemptId])
            ->whereIn('kind', ['observation', 'acquisition_charge', 'held', 'settlement', 'answer', ...($unsealed ? ['admission', 'reply', 'decision'] : [])])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null;
    }

    /** Decrypted evidence of one kind for an attempt, or for the operation when no attempt is given. */
    public static function evidence(int $operationId, ?int $attemptId, string $kind, bool $lock = false): ?array
    {
        $row = DB::table(OperationSchema::EVIDENCE)->where(['operation_id' => $operationId, 'attempt_id' => $attemptId, 'kind' => $kind])
            ->when($lock, fn ($q) => $q->lockForUpdate())->orderBy('id')->first();
        return $row ? self::decoded($row) : null;
    }

    /** The exact counter refusal and, after seal, the durable proof that this attempt did not reach handoff. */
    public static function testCapacityRefusal(AttemptFacts $attempt, bool $lock = false): ?int
    {
        if (!self::releasedUnsent($attempt, $lock)) { return null; }
        $token = $attempt->token;
        $row = DB::table(OperationSchema::EVIDENCE)->where(['operation_id' => $token->operationId, 'attempt_id' => $token->attemptId,
            'kind' => 'test_capacity', 'source' => 'local', 'outcome' => 'fresh'])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (!$row) { return null; }
        if (DB::table(OperationSchema::EVIDENCE)->where(['operation_id' => $token->operationId, 'attempt_id' => $token->attemptId,
            'kind' => 'admission_window', 'outcome' => AcquisitionAdmission::TestCapacity->value])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id'])) { return null; }
        $event = self::decodeEvidence($row->evidence);
        $at = self::time($row->recorded_at);
        return $at !== null && $at >= $attempt->claimedAt && $at <= $attempt->releasedAt
            && ($event['phase'] ?? null) === ($attempt->sealed() ? 'purchase' : 'preflight') ? (int) $row->id : null;
    }

    /**
     * The first evidence of each kind for the given operations in one read, keyed by operation id, then attempt id
     * (0 for operation-level evidence), then kind. A row that no longer decrypts reads as UNREADABLE, so it never
     * hides the evidence of another operation.
     */
    public static function evidenceMany(array $operationIds, array $kinds, bool $lock = false): array
    {
        if (!$operationIds || !$kinds) { return []; }
        $found = [];
        $rows = DB::table(OperationSchema::EVIDENCE)->whereIn('operation_id', $operationIds)->whereIn('kind', $kinds)
            ->when($lock, fn ($q) => $q->lockForUpdate())->orderBy('id')->get();
        foreach ($rows as $row) {
            $slot = &$found[(int) $row->operation_id][(int) ($row->attempt_id ?? 0)][$row->kind];
            if ($slot === null) { try { $slot = self::decoded($row); } catch (DecryptException|\JsonException) { $slot = self::UNREADABLE; } }
            unset($slot);
        }
        return $found;
    }

    private static function decoded(object $row): array
    {
        return ['source' => $row->source, 'outcome' => $row->outcome, 'observed_at' => self::time($row->observed_at)?->format(DATE_ATOM),
            'recorded_at' => self::time($row->recorded_at)?->format(DATE_ATOM), 'evidence' => self::decodeEvidence($row->evidence)];
    }

    public static function decodeEvidence(string $ciphertext): array
    { return json_decode(Crypt::decryptString($ciphertext), true, self::EVIDENCE_DEPTH, JSON_THROW_ON_ERROR); }

    /** The first evidence of one kind on the step's sealed attempt, in one read. */
    public static function sealedEvidence(int $operationId, StepKey $key, string $kind): ?array
    {
        $row = DB::table(OperationSchema::EVIDENCE.' as e')->join(OperationSchema::ATTEMPTS.' as a', 'a.id', '=', 'e.attempt_id')
            ->join(OperationSchema::STEPS.' as s', 's.id', '=', 'a.step_id')
            ->where(['s.operation_id' => $operationId, 's.step_type' => $key->type, 's.sequence' => $key->sequence, 'e.operation_id' => $operationId, 'e.kind' => $kind])
            ->whereNotNull('a.sealed_at')->orderBy('e.id')->first(['e.source', 'e.outcome', 'e.observed_at', 'e.recorded_at', 'e.evidence']);
        return $row ? self::decoded($row) : null;
    }

    /** Ids of these operations whose sealed attempt on the step retained evidence of one kind, in one read. */
    public static function sealedWithEvidence(array $operationIds, StepKey $key, string $kind): array
    {
        $ids = array_values(array_unique(array_map('intval', $operationIds)));
        if (!$ids) { return []; }
        return DB::table(OperationSchema::EVIDENCE.' as e')->join(OperationSchema::ATTEMPTS.' as a', 'a.id', '=', 'e.attempt_id')
            ->join(OperationSchema::STEPS.' as s', 's.id', '=', 'a.step_id')
            ->whereIn('s.operation_id', $ids)->where(['s.step_type' => $key->type, 's.sequence' => $key->sequence, 'e.kind' => $kind])
            ->whereColumn('e.operation_id', 's.operation_id')->whereNotNull('a.sealed_at')->distinct()->pluck('s.operation_id')->map(static fn ($id) => (int) $id)->all();
    }

    /** Logical step and attempt facts for authority stamps. Evidence is left out, so a read's own proof keeps the stamp. */
    public static function stamp(array $operationIds): array
    {
        if (!$operationIds) { return []; }
        $steps = DB::table(OperationSchema::STEPS)->whereIn('operation_id', $operationIds)->orderBy('id')->get();
        $attempts = DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', $steps->pluck('id'))->orderBy('id')->get();
        return [$steps->map(fn ($s) => (array) $s)->all(), $attempts->map(fn ($a) => (array) $a)->all()];
    }

    private static function facts(int $operationId, object $step, object $row): AttemptFacts
    {
        return new AttemptFacts(new AttemptToken($operationId, (int) $step->id, (int) $row->id, (int) $row->ordinal, $row->claim_key),
            $step->current_attempt_id !== null && (int) $step->current_attempt_id === (int) $row->id, $row->released_at !== null,
            self::recordedTime($row->claim_until), self::time($row->sealed_at), $row->operation_revision === null ? null : (int) $row->operation_revision,
            $row->request_digest, SendState::from($row->send_state), self::time($row->grant_consumed_at), $row->authorized_amount,
            $row->authorized_currency, $row->mode, $row->read_token, self::time($row->read_until), (bool) $row->read_unresolved,
            self::recordedTime($row->claimed_at), self::recordedTime($row->released_at), self::recordedTime($row->dispatch_until));
    }

    /**
     * Stored as a UTC instant in a datetime column, which no session time zone converts, so a deadline in the
     * repeated hour of a daylight-saving rollback still compares correctly, in PHP and in SQL.
     */
    public static function at(\DateTimeInterface $time): string
    { return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }

    private static function recordedTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) { return null; }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $time && self::at($time) === $value ? $time : null;
    }

    private static function time(?string $value): ?\DateTimeImmutable
    { return $value === null ? null : new \DateTimeImmutable($value, new \DateTimeZone('UTC')); }
}
