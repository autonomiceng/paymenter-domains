<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Contracts\Encryption\DecryptException;
use Paymenter\Extensions\Servers\Domains\Models\Operation;

/** Acquisition evidence as seen by checkout, staff, profile and refund readers. */
final readonly class AcquisitionAttempt
{
    private function __construct(public AttemptFacts $facts, private string $action, private array $rows, private array $origin, private array $history) {}

    public static function key(string $action): ?StepKey
    {
        return in_array($action, ['register', 'transfer'], true) ? new StepKey($action, 1) : null;
    }

    public static function of(int $id, string $action, bool $lock = false): ?self
    {
        $key = self::key($action);
        $facts = $key ? OperationAttempts::latest($id, $key, $lock) : null;
        if (!$facts) { return null; }
        $rows = OperationAttempts::evidenceMany([$id], ['claim', 'admission', 'reply', 'decision', 'test_capacity',
            'capacity', 'answer', 'settlement'])[$id] ?? [];
        foreach ($rows as $evidence) {
            if (in_array(OperationAttempts::UNREADABLE, $evidence, true)) { throw new DecryptException('Acquisition attempt evidence is unreadable.'); }
        }
        $current = $rows[$facts->token->attemptId] ?? [];
        $origin = $current['claim']['evidence'] ?? throw new \DomainException('Acquisition claim evidence unavailable.');
        if (isset($origin['origin_attempt_id'])) {
            $original = $rows[$origin['origin_attempt_id']]['claim']['evidence'] ?? throw new \DomainException('Original acquisition claim unavailable.');
            $origin = [...$original, 'origin_attempt_id' => $origin['origin_attempt_id']];
        }
        $origin['identity'] ??= isset($origin['origin_attempt_id']) ? OperationAttempts::claimKey($facts->token->stepId, $origin['origin_attempt_id'])
            : $facts->token->claimKey;
        $history = [];
        ksort($rows);
        foreach ($rows as $attempt) {
            foreach ($attempt['claim']['evidence']['history'] ?? [] as $kind => $events) { $history[$kind] = $events; }
            if (isset($attempt['capacity']['recorded_at'])) { $history['capacity_deferrals'][] = $attempt['capacity']['recorded_at']; }
            if (($attempt['test_capacity']['outcome'] ?? null) === 'fresh') {
                $history['test_call_refusals'][] = $attempt['test_capacity']['evidence'];
            }
        }
        return new self($facts, $action, $current, $origin, $history);
    }

    public function originId(): int { return $this->origin['origin_attempt_id'] ?? $this->facts->token->attemptId; }

    public function hasOriginalClaim(): bool
    { return ($this->rows['test_capacity']['outcome'] ?? null) !== 'fresh'; }

    public function sealed(): bool { return $this->facts->sealed() && !$this->facts->released; }

    public function start(): ?\DateTimeImmutable
    { return $this->hasOriginalClaim() ? new \DateTimeImmutable($this->origin['started_at']) : null; }

    public function digest(): ?string { return $this->hasOriginalClaim() ? $this->origin['digest'] : null; }

    public function answer(): ?array
    {
        return $this->rows['settlement']['evidence']['answer'] ?? $this->rows['answer']['evidence']['answer'] ?? null;
    }

    public function funding(): array
    {
        if (!$this->hasOriginalClaim()) { return []; }
        if (isset($this->origin['legacy_funding'])) { return $this->origin['legacy_funding']; }
        return ['claim' => $this->origin['funding'], ...($this->sealed() ? ['seal' => $this->rows['admission']['evidence']['funding']] : [])];
    }

    public function attempt(): array
    {
        if (!$this->hasOriginalClaim()) { return $this->history; }
        $settlement = array_intersect_key($this->rows['settlement']['evidence'] ?? [], ['reconciliation' => true]);
        if (isset($this->origin['legacy_attempt'])) { return [...$this->origin['legacy_attempt'], ...$settlement]; }
        $attempt = ['claim_key' => $this->origin['identity'], 'seal' => $this->sealed() ? 'sealed' : 'absent',
            'send_state' => $this->sealed() ? $this->facts->sendState->value : 'definitely_unsent'];
        if (!$this->sealed()) { return [...$attempt, ...$this->history]; }
        $admission = $this->rows['admission']['evidence'] ?? throw new \DomainException('Acquisition admission evidence unavailable.');
        $admissionKey = $this->action === 'transfer' ? 'transfer_admission' : 'registration_admission';
        return [...$attempt, ...$this->history, 'dispatch_attempted_at' => $this->facts->sealedAt->format(DATE_ATOM),
            'authorized_cost' => ['amount' => $this->facts->authorizedAmount, 'currency' => $this->facts->authorizedCurrency],
            $admissionKey => $admission[$admissionKey], ...array_diff_key($this->rows['reply']['evidence'] ?? [], ['reason' => true]),
            ...($this->rows['decision']['evidence'] ?? []),
            ...$settlement];
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

    /** Legacy readers may carry the projection when saving other facts. No new authority belongs in those columns. */
    public function keepOperationFacts(string $field, array $projection, ?array $value): ?array
    {
        $stored = $projection ? self::retain($projection, $value) : $value;
        $reserved = match ($field) {
            'attempt' => ['claim_key', 'dispatch_attempted_at', 'authorized_cost', 'registration_admission', 'transfer_admission',
                'execution', 'send_failure', 'evidence', 'charge', 'order_candidates', 'registration', 'corrections', 'reconciliation',
                'test_call_refusals', 'capacity_deferrals', ...($this->hasOriginalClaim() ? ['seal', 'send_state'] : [])],
            'funding' => ['claim', 'seal'],
            default => [],
        };
        if (array_intersect(array_keys($stored ?? []), $reserved)) {
            throw new \DomainException('Acquisition evidence belongs to its operation attempt.');
        }
        return $stored;
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
    public static function sameClaim(Operation $a, Operation $b): bool
    {
        return $a->state === 'running' && $b->state === 'running' && $a->transition_revision === $b->transition_revision
            && self::sameWorker($a, $b);
    }
    public static function sameWorker(Operation $a, Operation $b): bool
    {
        if (!($token = $b->acquisitionWorker) || $token->operationId !== (int) $a->id) { return false; }
        $facts = OperationAttempts::attempt($token, true);
        return $facts?->current && !$facts->released && !self::conflicts($a);
    }
}
