<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Data\SendState;

/** Reads steps, attempts and evidence. Callers that act on a result hold the operation lock. */
final class OperationAttempts
{
    /** The json_decode depth of stored evidence. The writer refuses deeper evidence before it is stored. */
    public const EVIDENCE_DEPTH = 32;

    public static function step(int $operationId, StepKey $key, bool $lock = false): ?object
    {
        return DB::table(OperationSchema::STEPS)->where(['operation_id' => $operationId, 'step_type' => $key->type, 'sequence' => $key->sequence])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /** The step's current attempt, or its latest one when no attempt is current. */
    public static function latest(int $operationId, StepKey $key, bool $lock = false): ?AttemptFacts
    {
        $step = self::step($operationId, $key, $lock);
        if (!$step) { return null; }
        $row = DB::table(OperationSchema::ATTEMPTS)->where('step_id', $step->id)
            ->when($step->current_attempt_id !== null, fn ($q) => $q->where('id', $step->current_attempt_id), fn ($q) => $q->orderByDesc('ordinal'))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        return $row ? self::facts($operationId, $step, $row) : null;
    }

    /** The current attempt, only while the step points at it. */
    public static function current(int $operationId, StepKey $key, bool $lock = false): ?AttemptFacts
    {
        $facts = self::latest($operationId, $key, $lock);
        return $facts?->current ? $facts : null;
    }

    /** The one sealed attempt of a step. Row 08 permits at most one. */
    public static function sealed(int $operationId, StepKey $key, bool $lock = false): ?AttemptFacts
    {
        $facts = self::latest($operationId, $key, $lock);
        return $facts?->sealed() ? $facts : null;
    }

    /** True only when the step's sealed attempt is definitely unsent and its retained reply is the local refusal. */
    public static function locallyRefused(int $operationId, StepKey $key): bool
    {
        $facts = self::sealed($operationId, $key, DB::transactionLevel() > 0);
        if ($facts?->sendState !== SendState::DefinitelyUnsent) { return false; }
        $reply = self::evidence($operationId, $facts->token->attemptId, 'reply');
        return ($reply['source'] ?? null) === 'local_refusal' && $reply['outcome'] === 'unsent';
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

    /** Decrypted evidence of one kind for an attempt, or for the operation when no attempt is given. */
    public static function evidence(int $operationId, ?int $attemptId, string $kind): ?array
    {
        $row = DB::table(OperationSchema::EVIDENCE)->where(['operation_id' => $operationId, 'attempt_id' => $attemptId, 'kind' => $kind])
            ->orderBy('id')->first();
        return $row ? self::decoded($row) : null;
    }

    /**
     * The first evidence of each kind for the given operations in one read, keyed by operation id, then attempt id
     * (0 for operation-level evidence), then kind.
     */
    public static function evidenceMany(array $operationIds, array $kinds): array
    {
        if (!$operationIds || !$kinds) { return []; }
        $found = [];
        foreach (DB::table(OperationSchema::EVIDENCE)->whereIn('operation_id', $operationIds)->whereIn('kind', $kinds)->orderBy('id')->get() as $row) {
            $found[(int) $row->operation_id][(int) ($row->attempt_id ?? 0)][$row->kind] ??= self::decoded($row);
        }
        return $found;
    }

    private static function decoded(object $row): array
    {
        return ['source' => $row->source, 'outcome' => $row->outcome, 'observed_at' => self::time($row->observed_at)?->format(DATE_ATOM),
            'recorded_at' => self::time($row->recorded_at)?->format(DATE_ATOM), 'evidence' => json_decode(Crypt::decryptString($row->evidence), true, self::EVIDENCE_DEPTH, JSON_THROW_ON_ERROR)];
    }

    /** The first evidence of one kind on the step's sealed attempt, in one read. */
    public static function sealedEvidence(int $operationId, StepKey $key, string $kind): ?array
    {
        $row = DB::table(OperationSchema::EVIDENCE.' as e')->join(OperationSchema::ATTEMPTS.' as a', 'a.id', '=', 'e.attempt_id')
            ->join(OperationSchema::STEPS.' as s', 's.id', '=', 'a.step_id')
            ->where(['s.operation_id' => $operationId, 's.step_type' => $key->type, 's.sequence' => $key->sequence, 'e.operation_id' => $operationId, 'e.kind' => $kind])
            ->whereNotNull('a.sealed_at')->orderBy('e.id')->first(['e.source', 'e.outcome', 'e.observed_at', 'e.recorded_at', 'e.evidence']);
        return $row ? self::decoded($row) : null;
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
            self::time($row->claim_until), self::time($row->sealed_at), $row->operation_revision === null ? null : (int) $row->operation_revision,
            $row->request_digest, SendState::from($row->send_state), self::time($row->grant_consumed_at), $row->authorized_amount,
            $row->authorized_currency, $row->mode, $row->read_token, self::time($row->read_until), (bool) $row->read_unresolved);
    }

    /**
     * Stored as a UTC instant in a datetime column, which no session time zone converts, so a deadline in the
     * repeated hour of a daylight-saving rollback still compares correctly, in PHP and in SQL.
     */
    public static function at(\DateTimeInterface $time): string
    { return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }

    private static function time(?string $value): ?\DateTimeImmutable
    { return $value === null ? null : new \DateTimeImmutable($value, new \DateTimeZone('UTC')); }
}
