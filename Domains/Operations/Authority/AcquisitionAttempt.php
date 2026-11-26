<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Paymenter\Extensions\Servers\Domains\Models\Operation;

/** Acquisition evidence as seen by checkout, staff, profile and refund readers. */
final readonly class AcquisitionAttempt
{
    private function __construct(public AttemptFacts $facts, private array $rows, private array $origin) {}

    public static function key(string $action): ?StepKey
    {
        return in_array($action, ['register', 'transfer'], true) ? new StepKey($action, 1) : null;
    }

    public static function of(int $id, string $action, bool $lock = false): ?self
    {
        $key = self::key($action);
        $facts = $key ? OperationAttempts::latest($id, $key, $lock) : null;
        if (!$facts) { return null; }
        $rows = OperationAttempts::evidenceMany([$id], ['claim', 'admission', 'reply', 'test_capacity', 'settlement'])[$id] ?? [];
        $current = $rows[$facts->token->attemptId] ?? [];
        $origin = $current['claim']['evidence'] ?? throw new \DomainException('Acquisition claim evidence unavailable.');
        if (isset($origin['origin_attempt_id'])) {
            $original = $rows[$origin['origin_attempt_id']]['claim']['evidence'] ?? throw new \DomainException('Original acquisition claim unavailable.');
            $origin = [...$original, 'origin_attempt_id' => $origin['origin_attempt_id']];
        }
        return new self($facts, $current, $origin);
    }

    public function originId(): int { return $this->origin['origin_attempt_id'] ?? $this->facts->token->attemptId; }

    public function hasOriginalClaim(): bool
    { return ($this->rows['test_capacity']['outcome'] ?? null) !== 'fresh'; }

    public function sealed(): bool { return $this->facts->sealed() && !$this->facts->released; }

    public function balanceCap(): ?array
    {
        $cap = $this->rows['test_capacity'] ?? [];
        return ($cap['outcome'] ?? null) === 'balance' && $this->facts->sealed() && $this->facts->released
            ? array_intersect_key($cap['evidence'], array_flip(['dispatch_attempted_at', 'authorized_cost', 'refused_at'])) : null;
    }

    public function start(): ?\DateTimeImmutable
    { return $this->hasOriginalClaim() ? new \DateTimeImmutable($this->origin['started_at']) : null; }

    public function digest(): ?string { return $this->hasOriginalClaim() ? $this->origin['digest'] : null; }

    public function funding(): array
    {
        if (!$this->hasOriginalClaim()) { return []; }
        return ['claim' => $this->origin['funding'], ...($this->sealed() ? ['seal' => $this->rows['admission']['evidence']['funding']] : [])];
    }

    public function attempt(string $action): array
    {
        $id = $this->facts->token->operationId;
        $deferrals = array_column(OperationAttempts::history($id, 'capacity'), 'recorded_at');
        $caps = array_column(array_filter(OperationAttempts::history($id, 'test_capacity'), static fn ($r) => $r['outcome'] === 'fresh'), 'evidence');
        $history = [...($caps ? ['test_call_refusals' => $caps] : []), ...($deferrals ? ['capacity_deferrals' => $deferrals] : [])];
        if (!$this->hasOriginalClaim()) { return $history; }
        $attempt = ['claim_key' => $this->origin['identity'], 'seal' => $this->sealed() ? 'sealed' : 'absent',
            'send_state' => $this->sealed() ? $this->facts->sendState->value : 'definitely_unsent'];
        if (!$this->sealed()) { return [...$attempt, ...$history]; }
        $admission = $this->rows['admission']['evidence'] ?? throw new \DomainException('Acquisition admission evidence unavailable.');
        $admissionKey = $action === 'transfer' ? 'transfer_admission' : 'registration_admission';
        return [...$attempt, ...$history, 'dispatch_attempted_at' => $this->facts->sealedAt->format(DATE_ATOM),
            'authorized_cost' => ['amount' => $this->facts->authorizedAmount, 'currency' => $this->facts->authorizedCurrency],
            $admissionKey => $admission[$admissionKey], ...array_diff_key($this->rows['reply']['evidence'] ?? [], ['reason' => true]),
            ...($this->rows['settlement']['evidence'] ?? [])];
    }

    /** An older code path may save the projection with new, separately owned operation facts. */
    public static function retain(array $projection, ?array $value): ?array
    {
        if (array_intersect_key($value ?? [], $projection) !== $projection) {
            // Array key order is not part of these independent facts.
            foreach ($projection as $key => $fact) {
                if (!array_key_exists($key, $value ?? []) || $value[$key] !== $fact) {
                    throw new \DomainException('Attempt evidence is write-once.');
                }
            }
        }
        return array_diff_key($value ?? [], $projection) ?: null;
    }

    /** Raw claim facts on a step-backed acquisition are contradictory. Refuse any stale worker that sees them. */
    public static function conflicts(Operation $op): bool
    {
        if ($op->getRawOriginal('started_at') !== null || $op->getRawOriginal('request_fingerprint') !== null
            || $op->getRawOriginal('lease_expires_at') !== null) { return true; }
        if (!($raw = $op->getRawOriginal('attempt'))) { return false; }
        $stored = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($raw), true, 32, JSON_THROW_ON_ERROR);
        return (bool) array_intersect_key($stored, array_flip(['claim_key', 'seal', 'dispatch_attempted_at', 'execution']));
    }
}
