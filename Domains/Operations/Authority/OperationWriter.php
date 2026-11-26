<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\SendState;

/**
 * The one writer of steps, attempts and evidence. Callers hold the operation row lock in their transaction
 * (after the native and holding locks); the writer then locks the step and the attempt, in that order.
 * Seal time, attempt identity and frozen terms are set once, and evidence rows are only appended.
 */
final readonly class OperationWriter
{
    public function __construct(private Clock $clock) {}

    /** A new pre-seal claim. A live claim is busy; a sealed attempt keeps the step, so no second claim exists. */
    public function claim(int $operationId, StepKey $key, \DateTimeImmutable $until): AttemptToken|Transition
    {
        self::requireTransaction(); $now = $this->clock->now();
        $step = OperationAttempts::step($operationId, $key, true);
        if (!$step) {
            DB::table(OperationSchema::STEPS)->insert(['operation_id' => $operationId, 'step_type' => $key->type, 'sequence' => $key->sequence]);
            $step = OperationAttempts::step($operationId, $key, true);
        }
        $latest = OperationAttempts::latest($operationId, $key, true);
        if ($latest?->sealed()) { return Transition::Stale; }
        if ($latest?->claiming($now)) { return Transition::Busy; }
        // An expired pre-seal claim never held a grant: it stays as history and loses the step.
        if ($latest?->current) { $this->archive($latest, $now); }
        $claim = bin2hex(random_bytes(16)); $ordinal = ($latest?->token->ordinal ?? 0) + 1;
        $id = (int) DB::table(OperationSchema::ATTEMPTS)->insertGetId(['step_id' => $step->id, 'ordinal' => $ordinal, 'claim_key' => $claim,
            'claimed_at' => OperationAttempts::at($now), 'claim_until' => OperationAttempts::at($until), 'send_state' => SendState::DefinitelyUnsent->value]);
        $this->point((int) $step->id, $id);
        return new AttemptToken($operationId, (int) $step->id, $id, $ordinal, $claim);
    }

    /** Ends a current pre-seal claim. A sealed or replaced attempt is stale and keeps its state. */
    public function releasePreSeal(AttemptToken $token): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->current || $facts->sealed() || $facts->released) { return Transition::Stale; }
        $this->archive($facts, $this->clock->now());
        return Transition::Applied;
    }

    /** Ends the step's current pre-seal claim, if any. A sealed attempt keeps its state. */
    public function releaseCurrent(int $operationId, StepKey $key): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::current($operationId, $key, true);
        return $facts ? $this->releasePreSeal($facts->token) : Transition::Stale;
    }

    /** A writer whose history times are the caller's locked decision time. */
    public static function at(\DateTimeImmutable $now): self
    { return new self(new class($now) implements Clock { public function __construct(private \DateTimeImmutable $now) {} public function now(): \DateTimeImmutable { return $this->now; } }); }

    /** Seals the current live claim. From here the send is possibly sent until a reply or local refusal proves otherwise. */
    public function seal(AttemptToken $token, SealTerms $terms): Transition
    {
        self::requireTransaction(); $now = $this->clock->now(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->claiming($now) || $terms->dispatchUntil <= $now) { return Transition::Stale; }
        DB::table(OperationSchema::ATTEMPTS)->where('id', $token->attemptId)->whereNull('sealed_at')->update([
            'operation_revision' => $terms->operationRevision, 'request_digest' => $terms->requestDigest, 'sealed_at' => OperationAttempts::at($now),
            'dispatch_until' => OperationAttempts::at($terms->dispatchUntil), 'send_state' => SendState::PossiblySent->value,
            'authorized_amount' => $terms->authorized->amount, 'authorized_currency' => $terms->authorized->currency, 'mode' => $terms->mode]);
        $this->bump($token->stepId);
        return Transition::Applied;
    }

    /** Spends the sealed attempt's one wire permission. A second consumption is stale. */
    public function consumeGrant(AttemptToken $token): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->current || !$facts->sealed() || $facts->grantConsumedAt !== null || $facts->sendState !== SendState::PossiblySent) { return Transition::Stale; }
        $updated = DB::table(OperationSchema::ATTEMPTS)->where('id', $token->attemptId)->whereNull('grant_consumed_at')
            ->update(['grant_consumed_at' => OperationAttempts::at($this->clock->now())]);
        return $updated === 1 ? Transition::Applied : Transition::Stale;
    }

    /** Records the sealed attempt's one wire outcome. Only a proved local refusal makes it definitely unsent. */
    public function recordReply(AttemptToken $token, WireOutcome $outcome): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->sealed()) { return Transition::Stale; }
        if (!$this->append($token->operationId, $token->attemptId, 'reply', $outcome->kind, $outcome->outcome, $outcome->evidence, 'reply')) { return Transition::Stale; }
        if ($facts->sendState === SendState::PossiblySent && $outcome->sendState !== SendState::PossiblySent) {
            DB::table(OperationSchema::ATTEMPTS)->where('id', $token->attemptId)->update(['send_state' => $outcome->sendState->value]);
            $this->bump($token->stepId);
        }
        return Transition::Applied;
    }

    /** A new read claim on the sealed attempt. A live read is busy. An earlier claim that was never closed leaves uncertainty. */
    public function openRead(AttemptToken $token, \DateTimeImmutable $until): ReadToken|Transition
    {
        self::requireTransaction(); $now = $this->clock->now(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->sealed()) { return Transition::Stale; }
        if ($facts->reading($now)) { return Transition::Busy; }
        $inherited = $facts->readUnresolved || $facts->readToken !== null; $read = bin2hex(random_bytes(16));
        DB::table(OperationSchema::ATTEMPTS)->where('id', $token->attemptId)->update(['read_token' => $read,
            'read_until' => OperationAttempts::at($until), 'read_unresolved' => $inherited]);
        $this->bump($token->stepId);
        return new ReadToken($token, $read, $until, $inherited);
    }

    /** True while this read owns the attempt, inherited no uncertainty and is inside its deadline. */
    public function readCurrent(ReadToken $read): bool
    {
        $facts = OperationAttempts::attempt($read->attempt, DB::transactionLevel() > 0); $now = $this->clock->now();
        return $facts !== null && $facts->readToken !== null && hash_equals($facts->readToken, $read->token)
            && !$facts->readUnresolved && $facts->reading($now);
    }

    /** True while this read still owns the attempt and is inside its deadline, inherited uncertainty or not. */
    private function readHeld(ReadToken $read): bool
    {
        $facts = OperationAttempts::attempt($read->attempt, true);
        return $facts !== null && $facts->readToken !== null && hash_equals($facts->readToken, $read->token) && $facts->reading($this->clock->now());
    }

    /** Releases this read. A stale finisher cannot clear a successor; inherited uncertainty stays. */
    public function closeRead(ReadToken $read): bool
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($read->attempt, true);
        if (!$facts || $facts->readToken === null || !hash_equals($facts->readToken, $read->token)) { return false; }
        DB::table(OperationSchema::ATTEMPTS)->where('id', $read->attempt->attemptId)->update(['read_token' => null, 'read_until' => null]);
        $this->bump($read->attempt->stepId);
        return true;
    }

    /**
     * Retains the first positive observation of a sealed attempt. Any reader of the attempt may append it,
     * even after a successor took the read claim, so it changes no claim or stamp.
     */
    public function recordObservation(AttemptToken $token, array $observation, \DateTimeImmutable $observedAt): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->sealed()) { return Transition::Stale; }
        return $this->append($token->operationId, $token->attemptId, 'observation', 'registrar_read', 'positive', $observation, 'observation', $observedAt)
            ? Transition::Applied : Transition::Stale;
    }

    /** A settlement that resolves an uncertain send as not sent. Only a current read that inherited no uncertainty may record it. */
    public const NOT_SENT = 'not_sent';

    /**
     * Records the attempt's settlement through the read that found it. New positive proof only needs the read it came
     * from; resolving the send as not sent also needs a read that inherited no uncertainty.
     */
    public function settle(ReadToken $read, string $outcome, array $settlement): Transition
    {
        self::requireTransaction();
        if (!($outcome === self::NOT_SENT ? $this->readCurrent($read) : $this->readHeld($read))) { return Transition::Stale; }
        return $this->append($read->attempt->operationId, $read->attempt->attemptId, 'settlement', 'registrar_read', $outcome, $settlement, 'settlement')
            ? Transition::Applied : Transition::Stale;
    }

    /** Positive proof a read captured that waits for charge review. Only the read that found it may record it, once. */
    public function hold(ReadToken $read, array $proof): Transition
    {
        self::requireTransaction();
        if (!$this->readHeld($read)) { return Transition::Stale; }
        return $this->append($read->attempt->operationId, $read->attempt->attemptId, 'held', 'registrar_read', 'review', $proof, 'held')
            ? Transition::Applied : Transition::Stale;
    }

    /** Operation-level local terminal proof, such as a never-sent unpaid closure. Recorded once. */
    public function recordTerminal(int $operationId, string $outcome, array $proof): Transition
    {
        self::requireTransaction();
        return $this->append($operationId, null, 'terminal', 'local', $outcome, $proof, 'terminal') ? Transition::Applied : Transition::Stale;
    }

    private function archive(AttemptFacts $facts, \DateTimeImmutable $now): void
    {
        DB::table(OperationSchema::ATTEMPTS)->where('id', $facts->token->attemptId)->whereNull('sealed_at')->whereNull('released_at')
            ->update(['released_at' => OperationAttempts::at($now)]);
        $this->point($facts->token->stepId, null);
    }

    private function point(int $stepId, ?int $attemptId): void
    { DB::table(OperationSchema::STEPS)->where('id', $stepId)->update(['current_attempt_id' => $attemptId, 'revision' => DB::raw('revision + 1')]); }

    private function bump(int $stepId): void
    { DB::table(OperationSchema::STEPS)->where('id', $stepId)->update(['revision' => DB::raw('revision + 1')]); }

    /** One row per authority identity; false when that identity already has evidence. */
    private function append(int $operationId, ?int $attemptId, string $kind, string $source, string $outcome, array $evidence,
        string $identity, ?\DateTimeImmutable $observedAt = null): bool
    {
        $key = hash('sha256', implode('|', [$operationId, $attemptId ?? '-', $identity]));
        if (DB::table(OperationSchema::EVIDENCE)->where('authority_key', $key)->exists()) { return false; }
        $now = $this->clock->now();
        DB::table(OperationSchema::EVIDENCE)->insert(['operation_id' => $operationId, 'attempt_id' => $attemptId, 'source' => $source,
            'kind' => $kind, 'outcome' => $outcome, 'observed_at' => $observedAt ? OperationAttempts::at($observedAt) : null,
            'recorded_at' => OperationAttempts::at($now), 'authority_key' => $key,
            'evidence' => Crypt::encryptString(json_encode($evidence, JSON_THROW_ON_ERROR))]);
        return true;
    }

    private static function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) { throw new \LogicException('Operation writes require the caller\'s locked transaction.'); }
    }
}
