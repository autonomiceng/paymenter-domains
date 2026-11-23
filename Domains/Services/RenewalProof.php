<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AttemptEvidence, DomainSnapshot, OrderEvidence};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};

/** An attempt's registrar read claim and any positive evidence retained from its replies. */
final class RenewalProof
{
    private const CLAIM_GRACE_SECONDS = 300;
    private bool $saved = false;
    private ?array $observation = null;
    private bool $superseded = false;

    private function __construct(private readonly int $operationId, private readonly string $identity, private readonly string $claimKey, private readonly string $token,
        private readonly AttemptEvidence $attempt, private readonly \DateTimeImmutable $deadline, private readonly Clock $clock) {}

    private static function claim(Operation $op): ?array
    {
        $claim = $op->renewal_check;
        return self::sameIdentity($claim, 'identity', RegistrarAnswers::identity($op)) ? $claim : null;
    }

    public static function claimed(Operation $op): bool { return self::claim($op) !== null; }

    public static function running(Operation $op, \DateTimeImmutable $now): bool
    { return (int) (self::claim($op)['until'] ?? 0) > $now->getTimestamp(); }

    /** Call under the holding locks after admission. An expired claim may belong to a worker that saw unsaved proof. */
    public static function open(Operation $op, AttemptEvidence $attempt, \DateTimeImmutable $deadline, Clock $clock): self
    {
        $read = new self((int) $op->id, RegistrarAnswers::identity($op), $op->attempt['claim_key'], bin2hex(random_bytes(16)), $attempt, $deadline, $clock);
        $op->renewal_check = ['identity' => $read->identity, 'token' => $read->token,
            'until' => $deadline->getTimestamp() + self::CLAIM_GRACE_SECONDS, 'unresolved' => self::claimed($op)];
        if (!$op->save()) { throw new \RuntimeException('Renewal read claim could not be saved.'); }
        return $read;
    }

    private function matches(Operation $op): bool
    {
        return (int) $op->id === $this->operationId && (int) $op->server_id === $this->attempt->binding->serverId
            && $op->account_fingerprint === $this->attempt->binding->fingerprint
            && hash_equals($this->identity, RegistrarAnswers::identity($op));
    }

    private function ownedClaim(Operation $op): ?array
    {
        $claim = $op->renewal_check;
        return $this->matches($op) && self::sameIdentity($claim, 'identity', $this->identity)
            && ($claim['token'] ?? null) === $this->token ? $claim : null;
    }

    /** Recheck the registrar read deadline under the final holding locks. */
    public function allowsAnswer(Operation $op, AccountBinding $binding): bool
    {
        $claim = $this->ownedClaim($op);
        $now = $this->clock->now();
        return $claim !== null && ($claim['unresolved'] ?? false) === false && $this->attempt->binding->matches($binding)
            && $now < $this->deadline && (int) ($claim['until'] ?? 0) > $now->getTimestamp();
    }

    /** A stale finisher cannot clear a successor's claim. Keep uncertainty inherited from an earlier read. */
    public function releaseLocked(Operation $op): bool
    {
        $claim = $this->ownedClaim($op);
        if ($claim === null) { return false; }
        $op->renewal_check = ($claim['unresolved'] ?? false) === true
            ? ['identity' => $this->identity, 'unresolved' => true] : null;
        self::saveEvidence($op);
        return true;
    }

    /** Release safe reads independently of owner locks, projection, scheduling and the staff audit. */
    public function release(): bool
    { return $this->withLockedOperation(fn (Operation $op) => $this->releaseLocked($op)); }

    /** Observe each reply before a later exchange can fail or run out of time. */
    public function saw(DomainSnapshot|OrderEvidence $seen): void
    {
        if ($this->observation !== null || $this->superseded) { return; }
        $this->observation = RenewalDecision::positiveObservation($this->attempt->binding, $this->attempt->query->name,
            $this->attempt->beforeExpiry?->value, $seen);
        if ($this->observation !== null) { $this->write(); }
    }

    /** Retry a failed proof write before settling the read. Unsaved evidence leaves the claim in place. */
    public function requireSaved(): void
    {
        if ($this->observation === null || $this->saved) { return; }
        $this->write();
        if (!$this->saved) { throw new \RuntimeException('Renewal proof could not be saved.'); }
    }

    private function write(): void
    {
        if ($this->superseded) { return; }
        try {
            $this->saved = $this->withLockedOperation(function (Operation $op): bool {
                if ((int) $op->id !== $this->operationId || ($op->attempt['claim_key'] ?? null) !== $this->claimKey
                    || $op->account_fingerprint !== $this->attempt->binding->fingerprint || (int) $op->server_id !== $this->attempt->binding->serverId) { return false; }
                $account = Account::where('server_id', $op->server_id)->first();
                if (!$account || !AccountBindings::binding($account)->matches($this->attempt->binding)) { return false; }
                // A late reply can still prove this attempt even if a different worker now owns its read claim.
                if (!self::held($op)) {
                    $op->renewal_proof = ['claim_key' => $this->claimKey, 'identity' => $this->identity,
                        'at' => $this->clock->now()->format(DATE_ATOM), 'observation' => $this->observation];
                    self::saveEvidence($op);
                }
                return true;
            });
            $this->superseded = !$this->saved;
        } catch (\Throwable) {
            // The registrar result still counts as observed; a failed transaction is retried at the end of the read.
        }
    }

    private function withLockedOperation(\Closure $step): bool
    {
        for ($try = 1; ; $try++) {
            try {
                return FreshTransaction::run(function () use ($step): bool {
                    $op = Operation::whereKey($this->operationId)->lockForUpdate()->first();
                    return $op !== null && $step($op);
                });
            } catch (\Throwable $error) {
                if ($try >= 3 || !MoneyDecisions::contention($error)) { throw $error; }
            }
        }
    }

    private static function saveEvidence(Operation $op): void
    {
        $timestamps = $op->timestamps;
        $op->timestamps = false;
        try {
            if (!$op->saveQuietly()) { throw new \RuntimeException('Renewal read evidence could not be saved.'); }
        } finally { $op->timestamps = $timestamps; }
    }

    public static function held(Operation $op): bool
    {
        $claimKey = $op->attempt['claim_key'] ?? null;
        return is_string($claimKey) && self::sameIdentity($op->renewal_proof, 'claim_key', $claimKey);
    }

    private static function sameIdentity(mixed $saved, string $key, string $identity): bool
    { return is_array($saved) && is_string($saved[$key] ?? null) && hash_equals($identity, $saved[$key]); }

    /** Holding and review stamps omit proof, so a check's own proof write never makes its save stale. */
    public static function stampRow(Operation $op): array
    {
        $row = $op->getRawOriginal();
        unset($row['renewal_proof']);
        // Equivalent re-encryption of policy or the read claim preserves the review stamp.
        try {
            $row['policy'] = $op->policy;
            $row['renewal_check'] = $op->renewal_check;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) { return $row; }
        return $row;
    }
}
