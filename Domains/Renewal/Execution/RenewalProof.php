<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Renewal\Execution;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AttemptEvidence, DomainSnapshot, OrderEvidence};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptFacts, OperationAttempts, OperationWriter, ReadToken, StepKey, Transition};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, FreshTransaction, MoneyDecisions, RegistrarAnswers};

/** The sealed attempt's registrar read claim and the first positive observation retained from its replies. */
final class RenewalProof
{
    private const CLAIM_GRACE_SECONDS = 300;
    private bool $saved = false;
    private ?array $observation = null;
    private bool $superseded = false;

    private function __construct(private readonly int $operationId, private readonly string $identity, private readonly ReadToken $read,
        private readonly AttemptEvidence $attempt, private readonly \DateTimeImmutable $deadline, private readonly Clock $clock) {}

    private static function sealed(Operation $op): ?AttemptFacts
    { return OperationAttempts::sealed((int) $op->id, StepKey::renew(), DB::transactionLevel() > 0); }

    /** A read was opened on the sealed attempt and is still live, or ended without settling its uncertainty. */
    public static function claimed(Operation $op): bool
    { $facts = self::sealed($op); return $facts !== null && ($facts->readToken !== null || $facts->readUnresolved); }

    public static function running(Operation $op, \DateTimeImmutable $now): bool
    { return self::sealed($op)?->reading($now) ?? false; }

    /** Call under the holding locks after admission. An expired claim may belong to a worker that saw unsaved proof. */
    public static function open(Operation $op, AttemptEvidence $attempt, \DateTimeImmutable $deadline, Clock $clock): self
    {
        $sealed = self::sealed($op);
        $read = $sealed ? (new OperationWriter($clock))->openRead($sealed->token, $deadline->modify('+'.self::CLAIM_GRACE_SECONDS.' seconds')) : null;
        if (!$read instanceof ReadToken) { throw new \RuntimeException('Renewal read claim could not be saved.'); }
        return new self((int) $op->id, RegistrarAnswers::identity($op), $read, $attempt, $deadline, $clock);
    }

    private function matches(Operation $op): bool
    {
        return (int) $op->id === $this->operationId && (int) $op->server_id === $this->attempt->binding->serverId
            && $op->account_fingerprint === $this->attempt->binding->fingerprint;
    }

    /** Recheck the registrar read deadline under the final holding locks. */
    public function allowsAnswer(Operation $op, AccountBinding $binding): bool
    {
        return $this->matches($op) && $this->attempt->binding->matches($binding) && $this->clock->now() < $this->deadline
            && (new OperationWriter($this->clock))->readCurrent($this->read);
    }

    /** A stale finisher cannot clear a successor's claim. Uncertainty inherited from an earlier read stays. */
    public function releaseLocked(Operation $op): bool
    { return $this->matches($op) && (new OperationWriter($this->clock))->closeRead($this->read); }

    /** Records confirmed completion through this read, under the caller's holding locks. False when the read lost the attempt. */
    public function settleLocked(Operation $op, array $settlement): bool
    {
        return $this->matches($op) && (new OperationWriter($this->clock))->settle($this->read, 'succeeded',
            ['identity' => $this->identity, 'settlement' => $settlement]) === Transition::Applied;
    }

    /** The sealed attempt's retained settlement, or null before one is recorded. */
    public static function settlement(Operation $op): ?array
    { return OperationAttempts::sealedEvidence((int) $op->id, StepKey::renew(), 'settlement'); }

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
                if (!$this->matches($op)) { return false; }
                $account = Account::where('server_id', $op->server_id)->first();
                if (!$account || !AccountBindings::binding($account)->matches($this->attempt->binding)) { return false; }
                if (!OperationAttempts::attempt($this->read->attempt, true)?->sealed()) { return false; }
                // A late reply can still prove this attempt even if a different worker now owns its read claim; the first one is kept.
                (new OperationWriter($this->clock))->recordObservation($this->read->attempt,
                    ['identity' => $this->identity, 'observation' => $this->observation], $this->clock->now());
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

    /** The retained positive observation of the sealed attempt: identity, observation, observed time. */
    public static function observation(Operation $op): ?array
    {
        $row = OperationAttempts::sealedEvidence((int) $op->id, StepKey::renew(), 'observation');
        return $row ? [...$row['evidence'], 'observed_at' => $row['observed_at']] : null;
    }

    public static function held(Operation $op): bool { return self::observation($op) !== null; }

    /** Holding and review stamp of an operation row. Equivalent re-encryption of policy preserves it. */
    public static function stampRow(Operation $op): array
    {
        $row = $op->getRawOriginal();
        try { $row['policy'] = $op->policy; }
        catch (\Illuminate\Contracts\Encryption\DecryptException) { return $row; }
        return $row;
    }
}
