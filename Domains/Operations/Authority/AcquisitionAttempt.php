<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Contracts\Encryption\DecryptException;
use Paymenter\Extensions\Servers\Domains\Data\SendState;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\ContactProfileHold;

/** Acquisition evidence as seen by checkout, staff, profile and refund readers. */
final readonly class AcquisitionAttempt
{
    private const KINDS = [...AcquisitionLifecycle::EVIDENCE, 'capacity'];
    private const PROJECTED = ['claim' => true, 'admission' => true, 'reply' => true, 'decision' => true,
        'test_capacity' => true, 'capacity' => true, 'answer' => true, 'settlement' => true];
    private const HISTORY_KINDS = ['claim' => true, 'capacity' => true, 'test_capacity' => true];

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
        return self::fromRows($facts, $action, OperationAttempts::evidenceMany([$id], self::KINDS, $lock)[$id] ?? []);
    }

    /** A seal or a retired continuation of a proved-unsent staff release needs the durable purchase proof. */
    public static function dispositionCandidate(Operation $op, bool $lock = false): bool
    {
        $attempt = $op->acquisitionAttemptFacts();
        if ($attempt?->sealed()) { return true; }
        if (!$attempt?->released) { return false; }
        $window = AcquisitionResult::window($op, $lock);
        return $window?->expiryReason === 'acquisition_definitely_unsent'
            && AcquisitionResult::ownsAttempt($op, $window, $attempt, $lock);
    }

    /** The latest purchase and its complete lineage prove no purchase left the registrar client. */
    public static function purchaseRefusal(Operation $op, bool $lock = false): ?AttemptFacts
    {
        $key = self::key($op->action);
        if (!$key || !$op->exists || $op->acquisitionStepAbsent()) { return null; }
        try {
            if (!self::dispositionCandidate($op, $lock)) { return null; }
            $id = (int) $op->id;
            $history = OperationAttempts::purchaseHistory($id, $key, $lock);
            if (!$history) { return null; }
            $latest = end($history);
            $rows = OperationAttempts::evidenceMany([$id], self::KINDS, $lock)[$id] ?? [];
            $now = now()->toImmutable();
            if ($latest->token != $op->acquisitionAttemptFacts()?->token
                || !AcquisitionLifecycle::unsentHistory($history, $rows, $now)
                || self::conflicts($op)
                || array_intersect_key($op->receiptFunding(), ['claim' => true, 'seal' => true])
                || array_intersect_key($op->cost ?? [], ['actual' => true, 'execution' => true])
                || $op->provider_order_id !== null || $op->confirmed_expires_at !== null
                || array_key_exists('dispatch_attempted_at', $op->operationPolicy())) { return null; }
            $attempt = self::fromRows($latest, $op->action, $rows);
            $claim = $attempt->rows['claim']['evidence'] ?? [];
            $admission = $attempt->rows['admission']['evidence'] ?? [];
            $funding = $attempt->origin['funding'] ?? null;
            // At cutoff, a paid transfer can use its retained capacity refusal for closure after Release used its retry.
            $cutoffRefusal = $op->action === 'transfer' && $op->registrar_key === 'enom' && $op->funding_basis === 'paid_invoice'
                && in_array($op->safe_code, ['transfer_setup_review_required', 'refund_approved'], true)
                && $attempt->lifecycle($now)->reason === 'test_capacity';
            if (($claim['identity'] ?? null) !== $latest->token->claimKey || !is_string($attempt->origin['digest'] ?? null)
                || !is_array($funding) || (!$attempt->hasOriginalClaim() && !$cutoffRefusal)
                || ($latest->sealed() && ($attempt->origin['digest'] !== $latest->requestDigest || ($admission['funding'] ?? null) !== $funding))
                || !ContactProfileHold::allowsPurchaseDisposition($op, $lock)) { return null; }
            return $latest;
        } catch (DecryptException|\JsonException|\DomainException|\ValueError|\DateException) { return null; }
    }

    /** One bad attempt must fail closed at its own reader without hiding the other rows. */
    public static function many(array $facts, bool $lock = false): array
    {
        $rows = OperationAttempts::evidenceMany(array_keys($facts), self::KINDS, $lock);
        $attempts = [];
        foreach ($facts as $id => ['facts' => $attempt, 'action' => $action]) {
            try { $attempts[$id] = self::fromRows($attempt, $action, $rows[$id] ?? []); }
            catch (DecryptException|\DomainException $error) { $attempts[$id] = $error; }
        }
        return $attempts;
    }

    private static function fromRows(AttemptFacts $facts, string $action, array $rows): self
    {
        $history = [];
        ksort($rows);
        foreach ($rows as $id => $attempt) {
            $evidence = array_intersect_key($attempt, $id === $facts->token->attemptId ? self::PROJECTED : self::HISTORY_KINDS);
            if (in_array(OperationAttempts::UNREADABLE, $evidence, true)) { throw new DecryptException('Acquisition attempt evidence is unreadable.'); }
            if (isset($attempt['capacity']['recorded_at'])) { $history['capacity_deferrals'][] = $attempt['capacity']['recorded_at']; }
            if (($attempt['test_capacity']['outcome'] ?? null) === 'fresh') {
                $history['test_call_refusals'][] = $attempt['test_capacity']['evidence'];
            }
        }
        $current = $rows[$facts->token->attemptId] ?? [];
        $origin = $current['claim']['evidence'] ?? throw new \DomainException('Acquisition claim evidence unavailable.');
        if (isset($origin['origin_attempt_id'])) {
            $original = $rows[$origin['origin_attempt_id']]['claim']['evidence'] ?? throw new \DomainException('Original acquisition claim unavailable.');
            $origin = [...$original, 'origin_attempt_id' => $origin['origin_attempt_id']];
        }
        if (!is_string($origin['identity'] ?? null)) { throw new \DomainException('Original acquisition identity unavailable.'); }
        return new self($facts, $action, $current, $origin, $history);
    }

    public function originId(): int { return $this->origin['origin_attempt_id'] ?? $this->facts->token->attemptId; }

    public function contactRecheckSince(): ?\DateTimeImmutable
    {
        $claim = $this->rows['claim'];
        return ($claim['evidence']['contact_recheck'] ?? false) === true
            ? new \DateTimeImmutable($claim['recorded_at']) : null;
    }

    /** Immutable bounds on the active purchase seal. */
    public function sealedWindow(): ?array
    {
        return $this->facts->current && $this->sealed() ? ($this->rows['admission']['evidence']['window'] ?? null) : null;
    }

    public function hasOriginalClaim(): bool
    { return ($this->rows['test_capacity']['outcome'] ?? null) !== 'fresh'; }

    public function lifecycle(\DateTimeImmutable $now): AcquisitionLifecycle
    { return AcquisitionLifecycle::reduce($this->facts, $this->rows, $now); }

    public function sealed(): bool { return $this->facts->sealed() && !$this->facts->released; }

    /** The caller validated the exact refusal execution against the funding and holding. */
    public function locallyRefused(array $execution): bool
    {
        if (!$this->facts->sealed()) { return false; }
        if ($reply = $this->rows['reply'] ?? null) {
            return $this->facts->sendState === SendState::DefinitelyUnsent
                && $reply['source'] === 'local_refusal' && $reply['outcome'] === 'unsent';
        }
        $claim = $this->rows['claim'];
        return $claim['source'] === 'retained' && $this->facts->sendState !== SendState::Sent
            && ($claim['evidence']['legacy_attempt']['execution'] ?? null) === $execution;
    }

    public function digest(): ?string { return $this->hasOriginalClaim() ? $this->origin['digest'] : null; }

    public function answer(): ?array
    {
        return $this->rows['settlement']['evidence']['answer'] ?? $this->rows['answer']['evidence']['answer'] ?? null;
    }

    public function funding(): array
    {
        if (!$this->hasOriginalClaim()) { return []; }
        if (isset($this->origin['legacy_funding'])) { return $this->origin['legacy_funding']; }
        $funding = [];
        if (array_key_exists('funding', $this->origin)) { $funding['claim'] = $this->origin['funding']; }
        if ($this->sealed() && array_key_exists('funding', $this->rows['admission']['evidence'] ?? [])) {
            $funding['seal'] = $this->rows['admission']['evidence']['funding'];
        }
        return $funding;
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
            ...array_intersect_key($admission, [$admissionKey => true]), ...array_diff_key($this->rows['reply']['evidence'] ?? [], ['reason' => true]),
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
        $this->guardOperationClaimFacts($field, self::operationClaimFacts($field, $stored));
        return $stored;
    }

    /** Identify the claim facts that an operation column could write. */
    public static function operationClaimFacts(string $field, ?array $value): array
    {
        $reserved = match ($field) {
            'attempt' => ['claim_key', 'dispatch_attempted_at', 'authorized_cost', 'registration_admission', 'transfer_admission',
                'execution', 'send_failure', 'evidence', 'charge', 'order_candidates', 'registration', 'corrections', 'reconciliation',
                'test_call_refusals', 'capacity_deferrals', 'seal', 'send_state'],
            'funding' => ['claim', 'seal'],
            default => [],
        };
        return array_intersect_key($value ?? [], array_flip($reserved));
    }

    public function guardOperationClaimFacts(string $field, array $facts): void
    {
        if ($field === 'attempt' && !$this->hasOriginalClaim()) { unset($facts['seal'], $facts['send_state']); }
        if ($facts) {
            throw new \DomainException('Acquisition evidence belongs to its operation attempt.');
        }
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
        return self::lockedClaim($a, $b) !== null;
    }
    public static function lockedClaim(Operation $a, Operation $b): ?AttemptFacts
    {
        return $a->state === 'running' && $b->state === 'running' && $a->transition_revision === $b->transition_revision
            ? self::lockedWorker($a, $b) : null;
    }
    public static function sameWorker(Operation $a, Operation $b): bool
    {
        return self::lockedWorker($a, $b) !== null;
    }
    private static function lockedWorker(Operation $a, Operation $b): ?AttemptFacts
    {
        if (!($token = $b->acquisitionWorker) || $token->operationId !== (int) $a->id) { return null; }
        $facts = OperationAttempts::attempt($token, true);
        return $facts?->current && !$facts->released && !self::conflicts($a) ? $facts : null;
    }
}
