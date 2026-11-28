<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\SendState;

/**
 * The one writer of steps, attempts and evidence. Callers hold the operation row lock in their transaction
 * (after the native and holding locks); the writer then locks the step and the attempt, in that order.
 * Seal time, attempt identity and frozen terms are set once, and evidence rows are only appended.
 */
final readonly class OperationWriter
{
    use WritesOperationEvidence, WritesManagementEvidence;

    public function __construct(private Clock $clock) {}

    /** A new pre-seal claim. A live claim is busy; a sealed attempt keeps the step, so no second claim exists. */
    public function claim(int $operationId, StepKey $key, \DateTimeImmutable $until, ?array $claimEvidence = null,
        ?\DateTimeImmutable $claimedAt = null): AttemptToken|Transition
    {
        self::requireTransaction(); $now = $this->clock->now();
        $stepId = $this->stepId($operationId, $key);
        $latest = OperationAttempts::latest($operationId, $key, true);
        if ($latest?->claiming($now)) { return Transition::Busy; }
        if ($latest ? !OperationAttempts::replaceable($latest, $now, lock: true)
            : OperationAttempts::step($operationId, $key, true)->current_attempt_id !== null) { return Transition::Stale; }
        // An expired pre-seal claim never held a grant: it stays as history and loses the step.
        if ($latest?->current) { $this->archive($latest, $now); }
        $claim = bin2hex(random_bytes(16)); $ordinal = ($latest?->token->ordinal ?? 0) + 1;
        $id = (int) DB::table(OperationSchema::ATTEMPTS)->insertGetId(['step_id' => $stepId, 'ordinal' => $ordinal, 'claim_key' => $claim,
            'claimed_at' => OperationAttempts::at($claimedAt ?? $now), 'claim_until' => OperationAttempts::at($until), 'send_state' => SendState::DefinitelyUnsent->value]);
        $this->point($stepId, $id);
        if ($claimEvidence !== null) {
            $this->append($operationId, $id, 'claim', 'local', 'claimed', ['identity' => $claim, ...$claimEvidence], 'claim');
        }
        return new AttemptToken($operationId, $stepId, $id, $ordinal, $claim);
    }

    /** Preserve a historical seal. Its dispatch window is closed and no send permission can be issued. */
    public function adoptAcquisition(int $id, int $revision, RetainedAcquisition $record): void
    {
        self::requireTransaction();
        if (OperationAttempts::latest($id, $record->step, true)) { throw new \DomainException('Acquisition attempt already exists.'); }
        $stepId = $this->stepId($id, $record->step);
        $sealed = OperationAttempts::at($record->sealedAt);
        $cost = $record->envelope['authorized_cost'] ?? [];
        $attemptId = (int) DB::table(OperationSchema::ATTEMPTS)->insertGetId([
            'step_id' => $stepId, 'ordinal' => 1, 'claim_key' => $record->claimKey,
            'claimed_at' => OperationAttempts::at($record->startedAt), 'claim_until' => $sealed,
            'sealed_at' => $sealed, 'dispatch_until' => $sealed, 'operation_revision' => $revision,
            'request_digest' => $record->digest, 'send_state' => $record->sendState->value,
            'authorized_amount' => $cost['amount'] ?? null, 'authorized_currency' => $cost['currency'] ?? null, 'mode' => $record->step->type,
        ]);
        $this->append($id, $attemptId, 'claim', 'retained', 'sealed', [
            'identity' => $record->claimKey, 'digest' => $record->digest,
            'legacy_attempt' => $record->envelope, 'legacy_funding' => $record->funding,
        ], 'claim');
        $this->point($stepId, $attemptId);
    }

    /** Records the exact admission evaluated by AcquisitionResult in this transaction. */
    public function recordAcquisitionAdmission(int $operationId, AcquisitionAdmission $admission, int $revision,
        ?AttemptToken $attempt, array $proof): int
    {
        self::requireTransaction();
        return $this->append($operationId, $attempt?->attemptId, 'admission_window', 'local', $admission->value,
            $proof, 'admission_window:'.$revision)
            ?? throw new \DomainException('Acquisition admission already recorded.');
    }

    private function stepId(int $operationId, StepKey $key): int
    {
        return (int) (OperationAttempts::step($operationId, $key, true)?->id
            ?? DB::table(OperationSchema::STEPS)->insertGetId(['operation_id' => $operationId, 'step_type' => $key->type, 'sequence' => $key->sequence]));
    }

    /** Ends a current pre-seal claim. A sealed or replaced attempt is stale and keeps its state. */
    public function releasePreSeal(AttemptToken $token): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($token, true);
        $now = $this->clock->now();
        if (!$facts?->current || $facts->sealed() || $facts->released || !OperationAttempts::definitelyUnsent($facts)
            || !OperationAttempts::recordedLease($facts) || $now < $facts->claimedAt) { return Transition::Stale; }
        $this->archive($facts, $now);
        return Transition::Applied;
    }

    /** Retire a purchase only after the ledger proves the sealed attempt never reached the wire. */
    public function releaseUnsentAcquisition(AttemptToken $token, bool $requireUnusedGrant = true): Transition
    {
        self::requireTransaction();
        $facts = OperationAttempts::attempt($token, true);
        $now = $this->clock->now();
        if (!$facts?->current || $facts->released || $facts->claimedAt === null || $now < $facts->claimedAt
            || $requireUnusedGrant && $facts->grantConsumedAt !== null
            || !DB::table(OperationSchema::STEPS)->where('id', $token->stepId)->whereIn('step_type', ['register', 'transfer'])->exists()
            || !OperationAttempts::locallyRefused($facts, lock: true)) { return Transition::Stale; }

        $this->release($facts, $now);
        return Transition::Applied;
    }

    /** Apply the exact unused-grant decision already folded under the operation lock. */
    public function retireUnusedPurchase(AcquisitionLifecycle $state): Transition
    {
        self::requireTransaction();
        $facts = $state->facts;
        if (!$state->retireSeal || !$facts || !OperationAttempts::purchaseStep($facts->token)
            || $this->recordReply($facts->token, WireOutcome::localRefusal('expired_unused_grant')) !== Transition::Applied) {
            return Transition::Stale;
        }
        $this->clearRead($facts, unresolved: false);
        $this->release($facts, $this->clock->now());
        return Transition::Applied;
    }

    /** Keep the last possible wire time for pacing. Registration may still retain a timely charge. */
    public function fencePurchaseRead(AttemptFacts $facts, bool $preserveTimelyCharge): bool
    {
        self::requireTransaction();
        if (!$facts->current || $facts->readToken === null || $facts->readUntil === null || $facts->reading($this->clock->now())
            || $preserveTimelyCharge && $facts->readUnresolved) { return false; }
        $changed = DB::table(OperationSchema::ATTEMPTS)->where('id', $facts->token->attemptId)->where('read_token', $facts->readToken)
            ->update(['read_unresolved' => true, ...($preserveTimelyCharge ? [] : ['read_token' => null])]);
        if ($changed === 1) { $this->bump($facts->token->stepId); }
        return $changed === 1;
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
    public function seal(AttemptToken $token, SealTerms $terms, ?array $admission = null): Transition
    {
        self::requireTransaction(); $now = $this->clock->now(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->claiming($now) || $terms->dispatchUntil <= $now) { return Transition::Stale; }
        DB::table(OperationSchema::ATTEMPTS)->where('id', $token->attemptId)->whereNull('sealed_at')->update([
            'operation_revision' => $terms->operationRevision, 'request_digest' => $terms->requestDigest, 'sealed_at' => OperationAttempts::at($now),
            'dispatch_until' => OperationAttempts::at($terms->dispatchUntil), 'send_state' => SendState::PossiblySent->value,
            'authorized_amount' => $terms->authorized->amount, 'authorized_currency' => $terms->authorized->currency, 'mode' => $terms->mode]);
        $this->bump($token->stepId);
        if ($admission !== null) {
            $this->append($token->operationId, $token->attemptId, 'admission', 'local', 'sealed', $admission, 'admission');
        }
        return Transition::Applied;
    }

    /** Retain a local capacity decision on its worker attempt before dropping the current pointer. */
    public function deferClaim(AttemptToken $token): Transition
    {
        self::requireTransaction();
        $facts = OperationAttempts::attempt($token, true);
        $now = $this->clock->now();
        if (!$facts?->claiming($now) || !OperationAttempts::definitelyUnsent($facts)
            || $facts->claimedAt === null || $now < $facts->claimedAt) { return Transition::Stale; }
        $this->append($token->operationId, $token->attemptId, 'capacity', 'local', 'deferred', [], 'capacity');
        $this->archive($facts, $now);
        return Transition::Applied;
    }

    /** The caller proved that the TEST purchase counter stopped this worker before any provider exchange. */
    public function refuseTestCapacity(AttemptToken $token, array $proof, bool $balance): Transition
    {
        self::requireTransaction();
        $facts = OperationAttempts::attempt($token, true);
        $now = $this->clock->now();
        if (!$facts?->current || $facts->released || $facts->claimUntil === null || $facts->claimUntil <= $now
            || $facts->claimedAt === null || $now < $facts->claimedAt
            || ($facts->sealed() ? OperationAttempts::unresolvedRead($facts) : !OperationAttempts::definitelyUnsent($facts))) {
            return Transition::Stale;
        }
        if ($facts->sealed() && $this->recordReply($token, WireOutcome::localRefusal('test_capacity')) !== Transition::Applied) {
            return Transition::Stale;
        }
        $this->append($token->operationId, $token->attemptId, 'test_capacity', 'local', $balance ? 'balance' : 'fresh', $proof, 'test_capacity');
        $this->release($facts, $now);
        return Transition::Applied;
    }

    /**
     * Spends the sealed attempt's one wire permission before its dispatch deadline. A second consumption is stale,
     * and so is one after any settlement, which the locking read sees even past an earlier snapshot.
     */
    public function consumeGrant(AttemptToken $token): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->current || $facts->released || !$facts->sealed() || $facts->claimUntil === null || $facts->dispatchUntil === null
            || $facts->grantConsumedAt !== null || $facts->sendState !== SendState::PossiblySent) { return Transition::Stale; }
        if (DB::table(OperationSchema::EVIDENCE)->where(['attempt_id' => $token->attemptId, 'kind' => 'settlement'])
            ->lockForUpdate()->first(['id'])) { return Transition::Stale; }
        $now = OperationAttempts::at($this->clock->now());
        $updated = DB::table(OperationSchema::ATTEMPTS)->where('id', $token->attemptId)->whereNull('grant_consumed_at')
            ->where('dispatch_until', '>', $now)->update(['grant_consumed_at' => $now]);
        return $updated === 1 ? Transition::Applied : Transition::Stale;
    }

    /** Records the sealed attempt's one wire outcome. Only a proved local refusal makes it definitely unsent. */
    public function recordReply(AttemptToken $token, WireOutcome $outcome): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->sealed() || !$facts->current) { return Transition::Stale; }
        if (!$this->append($token->operationId, $token->attemptId, 'reply', $outcome->kind, $outcome->outcome, $outcome->evidence, 'reply')) { return Transition::Stale; }
        if ($facts->sendState === SendState::PossiblySent && $outcome->sendState !== SendState::PossiblySent) {
            DB::table(OperationSchema::ATTEMPTS)->where('id', $token->attemptId)->update(['send_state' => $outcome->sendState->value]);
            $this->bump($token->stepId);
        }
        return Transition::Applied;
    }

    /** A registrar's immediate acquisition outcome after it has been correlated to the holding. */
    public function recordAcquisitionDecision(AttemptToken $token, string $state, array $proof): Transition
    {
        self::requireTransaction();
        $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->sealed() || !$facts->current
            || !OperationAttempts::evidence($token->operationId, $token->attemptId, 'reply')) { return Transition::Stale; }
        if (!$this->append($token->operationId, $token->attemptId, 'decision', 'reply', $state, $proof, 'decision')) { return Transition::Stale; }
        if (in_array($state, ['succeeded', 'rejected'], true)) { $this->clearRead($facts, $facts->readUnresolved); }
        return Transition::Applied;
    }

    /** An audited answer belongs to the sealed acquisition. Final answers use its one settlement identity. */
    public function recordAcquisitionAnswer(AttemptToken $token, array $answer): Transition
    {
        self::requireTransaction();
        $facts = OperationAttempts::attempt($token, true);
        $outcome = $answer['outcome'];
        if (!$facts?->current || !$facts->sealed() || $facts->reading($this->clock->now()) || OperationAttempts::purchaseClosed($token)
            || ($outcome === 'not_processed' && (($facts->readToken !== null && $facts->readUntil === null)
                || OperationAttempts::hasAcquisitionSettlementEvidence($token, lock: true)))) { return Transition::Stale; }
        $kind = $outcome === 'accepted' ? 'answer' : 'settlement';
        if (!$this->append($token->operationId, $token->attemptId, $kind, 'staff_answer', $outcome, ['answer' => $answer], $kind)) {
            return Transition::Stale;
        }
        if ($kind === 'settlement') { $this->clearRead($facts, $facts->readUnresolved || $facts->readToken !== null); }
        return Transition::Applied;
    }

    /** A new read claim on the sealed attempt. A live read is busy. An earlier claim that was never closed leaves uncertainty. */
    public function openRead(AttemptToken $token, \DateTimeImmutable $until): ReadToken|Transition
    {
        self::requireTransaction(); $now = $this->clock->now(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->sealed() || !$facts->current || $until <= $now) { return Transition::Stale; }
        if (OperationAttempts::purchaseStep($token)
            && !AcquisitionLifecycle::read($facts, $now, lock: true, includeClosure: true)->permitsRead()) { return Transition::Stale; }
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

    /** A stale finisher cannot change the schedule or use its evidence once a successor owns the read. */
    public function ownsRead(ReadToken $read, bool $allowExpired = false): bool
    {
        $facts = OperationAttempts::attempt($read->attempt, true);
        return $facts?->current && $facts->readToken !== null && hash_equals($facts->readToken, $read->token)
            && ($allowExpired || $facts->reading($this->clock->now()));
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

    private function clearRead(AttemptFacts $facts, bool $unresolved): void
    {
        if (!OperationAttempts::unresolvedRead($facts)) { return; }
        DB::table(OperationSchema::ATTEMPTS)->where('id', $facts->token->attemptId)->update([
            'read_token' => null, 'read_until' => null, 'read_unresolved' => $unresolved,
        ]);
        $this->bump($facts->token->stepId);
    }

    /**
     * Retains the first positive observation of the current sealed attempt. Any reader of that attempt may append it,
     * even after a successor took the read claim, so it changes no claim or stamp.
     */
    public function recordObservation(AttemptToken $token, array $observation, \DateTimeImmutable $observedAt): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->current || !$facts->sealed()
            || OperationAttempts::purchaseStep($token) && OperationAttempts::purchaseClosed($token)) { return Transition::Stale; }
        return $this->append($token->operationId, $token->attemptId, 'observation', 'registrar_read', 'positive', $observation, 'observation', $observedAt)
            ? Transition::Applied : Transition::Stale;
    }

    /**
     * A settlement that resolves an uncertain send as not sent. Only a current read that inherited no uncertainty
     * may record it, and only while the grant is unspent.
     */
    public const NOT_SENT = 'not_sent';

    public function completeAcquisition(AcquisitionReadPermit $permit, array $proof): Transition
    {
        self::requireTransaction();
        $read = $permit->token;
        $token = $read->attempt;
        if (!$this->append($token->operationId, $token->attemptId, 'settlement', 'registrar_read', 'succeeded', $proof, 'settlement')) {
            return Transition::Stale;
        }
        if (!$this->closeRead($read)) { throw new \DomainException('Acquisition read authority changed.'); }
        return Transition::Applied;
    }

    /**
     * Records the attempt's settlement through the read that found it. New positive proof only needs the read it came
     * from; resolving the send as not sent also needs a read that inherited no uncertainty and an unspent grant,
     * since a spent grant's send may still complete.
     */
    public function settle(ReadToken $read, string $outcome, array $settlement): Transition
    {
        self::requireTransaction();
        if (OperationAttempts::purchaseStep($read->attempt)) { return Transition::Stale; }
        $owned = $outcome === self::NOT_SENT
            ? $this->readCurrent($read) && OperationAttempts::attempt($read->attempt, true)?->grantConsumedAt === null
            : $this->ownsRead($read);
        if (!$owned) { return Transition::Stale; }
        return $this->append($read->attempt->operationId, $read->attempt->attemptId, 'settlement', 'registrar_read', $outcome, $settlement, 'settlement')
            ? Transition::Applied : Transition::Stale;
    }

    /** Positive proof a read captured that waits for charge review. Only the read that found it may record it, once. */
    public function hold(ReadToken $read, array $proof): Transition
    {
        self::requireTransaction();
        if (OperationAttempts::purchaseStep($read->attempt) || !$this->ownsRead($read)) { return Transition::Stale; }
        return $this->append($read->attempt->operationId, $read->attempt->attemptId, 'held', 'registrar_read', 'review', $proof, 'held')
            ? Transition::Applied : Transition::Stale;
    }

    /**
     * Settles the exact proof a read held for charge review, through its staff approval. The approval carries the
     * staff and read evidence and becomes the attempt's one settlement.
     */
    public function approveHeld(AttemptToken $token, array $approval): Transition
    {
        self::requireTransaction(); $facts = OperationAttempts::attempt($token, true);
        if (!$facts?->sealed()) { return Transition::Stale; }
        $held = OperationAttempts::evidence($token->operationId, $token->attemptId, 'held')['evidence']['settlement'] ?? null;
        if ($held === null || $held !== ($approval['settlement'] ?? null)) { return Transition::Stale; }
        return $this->append($token->operationId, $token->attemptId, 'settlement', 'staff_review', 'succeeded', $approval, 'settlement')
            ? Transition::Applied : Transition::Stale;
    }

    /**
     * Audited negative proof: staff recorded the registrar's answer that the sealed send was not processed. Only a
     * current read that inherited no uncertainty may record it, while the attempt retains no positive proof. It takes
     * the attempt's one settlement, so no later settlement can follow it.
     */
    public function recordUnprocessed(ReadToken $read, array $answer): Transition
    {
        self::requireTransaction(); $a = $read->attempt;
        if (OperationAttempts::purchaseStep($a) || !$this->readCurrent($read)) { return Transition::Stale; }
        if (OperationAttempts::hasAcquisitionSettlementEvidence($a, lock: true)) { return Transition::Stale; }
        return $this->append($a->operationId, $a->attemptId, 'answer', 'staff_answer', 'not_processed', $answer, 'settlement')
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
        if ($facts->sealed()) { throw new \LogicException('A sealed attempt requires local refusal proof.'); }
        $this->release($facts, $now);
    }

    private function release(AttemptFacts $facts, \DateTimeImmutable $now): void
    {
        DB::table(OperationSchema::ATTEMPTS)->where('id', $facts->token->attemptId)->whereNull('released_at')
            ->update(['released_at' => OperationAttempts::at($now)]);
        $this->point($facts->token->stepId, null);
    }

    private function point(int $stepId, ?int $attemptId): void
    { DB::table(OperationSchema::STEPS)->where('id', $stepId)->update(['current_attempt_id' => $attemptId, 'revision' => DB::raw('revision + 1')]); }

    private function bump(int $stepId): void
    { DB::table(OperationSchema::STEPS)->where('id', $stepId)->update(['revision' => DB::raw('revision + 1')]); }

}
