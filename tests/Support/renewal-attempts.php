<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{Money, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptToken, OperationAttempts, OperationSchema, OperationWriter, SealTerms, StepKey, Transition, WireOutcome};
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\{RenewalAttempt, RenewalProof};

// Renewal attempt fixtures. Tests seal and read a renewal only through these functions and the RenewalAttempt reader.
// A seal goes through the operation writer; the renewal writes no JSON attempt copy.

if (!class_exists('RenewalReply', false)) {
    /** Test value: what one sealed renewal's wire call proved. A missing reply is unknown, never unsent. */
    final readonly class RenewalReply
    {
        private function __construct(public string $kind, public SendState $sendState, public string $outcome, public array $evidence) {}

        /** A registrar reply for the sealed request: its send state, disposition and the reason and discrepancies it gave. */
        public static function reply(SendState $state, string $disposition, array $evidence = []): self
        {
            if ($state === SendState::DefinitelyUnsent) { throw new InvalidArgumentException('A registrar reply cannot prove a local refusal.'); }
            return new self('reply', $state, $disposition, $evidence);
        }

        /** The final transport proved it stopped before the wire. */
        public static function localRefusal(string $reason = 'unsent'): self
        { return new self('local_refusal', SendState::DefinitelyUnsent, 'unsent', ['reason' => $reason]); }

        /** No reply, or one that cannot be tied to the request. The send stays possibly sent. */
        public static function unknown(array $evidence = []): self
        { return new self('unknown_reply', SendState::PossiblySent, 'unknown', $evidence); }

        /** The writer's outcome for this reply, carrying the driver result as the renewal writer records it. */
        public function outcome(): WireOutcome
        {
            return match ($this->kind) {
                'reply' => WireOutcome::reply($this->sendState, $this->outcome, ['result' => $this->record(), 'acceptance' => $this->evidence['acceptance'] ?? null]),
                'local_refusal' => WireOutcome::localRefusal($this->evidence['reason'], ['result' => $this->record()]),
                default => WireOutcome::unknown($this->evidence),
            };
        }

        /** The reply as the attempt record saves it; a lost reply is saved as null. */
        public function record(): ?array
        {
            if ($this->kind === 'unknown_reply') { return null; }
            return ['disposition' => $this->kind === 'local_refusal' ? 'rejected' : $this->outcome, 'send_state' => $this->sendState->value,
                'reason' => $this->evidence['reason'] ?? $this->outcome, 'discrepancies' => $this->evidence['discrepancies'] ?? []];
        }
    }
}

if (!function_exists('sealRenewal')) {
    /**
     * Fixture: a renewal sealed at $at for $amount USD in $mode through the operation writer. A non-empty $attempt is
     * saved as a misleading JSON envelope that no reader may trust, and $columns sets other operation columns in the same save.
     * A $reply is recorded on the sealed attempt; a registrar reply first spends the wire grant, a local refusal does not.
     */
    function sealRenewal(Operation $op, array $attempt = [], array $columns = [], ?RenewalReply $reply = null,
        ?DateTimeImmutable $at = null, string $amount = '8.95', string $mode = 'ordinary'): Operation
    {
        expect($op->action === 'renew', 'Only a renewal has a renewal attempt');
        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cost = new Money($amount, 'USD');
        DB::transaction(static function () use ($op, $attempt, $columns, $reply, $at, $cost, $mode): void {
            $writer = OperationWriter::at($at);
            $row = DB::table('domains_operations')->where('id', $op->id)->lockForUpdate()->first(['id', 'transition_revision', 'request_fingerprint', 'operation_key']);
            expect($row !== null, 'The sealed renewal row exists');
            // Sealing again replaces the earlier fixture seal, as the JSON copy is replaced.
            $steps = DB::table(OperationSchema::STEPS)->where(['operation_id' => $op->id, 'step_type' => StepKey::renew()->type])->pluck('id');
            if ($steps->isNotEmpty()) {
                $attempts = DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', $steps)->pluck('id');
                DB::table(OperationSchema::EVIDENCE)->whereIn('attempt_id', $attempts)->delete();
                DB::table(OperationSchema::ATTEMPTS)->whereIn('id', $attempts)->delete();
                DB::table(OperationSchema::STEPS)->whereIn('id', $steps)->delete();
            }
            $token = $writer->claim((int) $row->id, StepKey::renew(), $at->modify('+360 seconds'));
            expect($token instanceof AttemptToken, 'The renewal fixture claims its step');
            $digest = is_string($row->request_fingerprint) && preg_match('/^[a-f0-9]{64}$/D', $row->request_fingerprint)
                ? $row->request_fingerprint : hash('sha256', 'fixture|'.$row->operation_key);
            expect($writer->seal($token, new SealTerms((int) $row->transition_revision, $digest, $cost, $mode, $at->modify('+360 seconds'))) === Transition::Applied,
                'The renewal fixture seals its claim');
            if ($reply !== null) {
                if ($reply->kind === 'reply') { $writer->consumeGrant($token); }
                expect($writer->recordReply($token, $reply->outcome()) === Transition::Applied, 'The renewal fixture records its reply');
            }
            $columns = $attempt ? [...$columns, 'attempt' => $attempt] : $columns;
            // One quiet write with the model's casts, as saveQuietly writes it, without reading the model first.
            if ($columns) { Operation::whereKey($op->id)->update((new Operation)->forceFill($columns)->getAttributes()); }
        });
        return $op->refresh();
    }

    /**
     * Runs $check against a temporarily sealed renewal with an optional $reply, then puts the operation row and every step,
     * attempt and evidence row back as they were, including earlier rows the seal replaced.
     */
    function withSealedRenewal(Operation $op, Closure $check, array $attempt = [], ?DateTimeImmutable $at = null, ?RenewalReply $reply = null): mixed
    {
        $raw = (array) DB::table('domains_operations')->where('id', $op->id)->first();
        $steps = DB::table(OperationSchema::STEPS)->where('operation_id', $op->id)->get();
        $attempts = DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', $steps->pluck('id'))->get();
        $evidence = DB::table(OperationSchema::EVIDENCE)->where('operation_id', $op->id)->get();
        try { return $check(sealRenewal($op, $attempt, reply: $reply, at: $at)); }
        finally {
            // Remove what the seal added, children first, then put each earlier row back parents first.
            DB::table(OperationSchema::EVIDENCE)->where('operation_id', $op->id)->whereNotIn('id', $evidence->pluck('id'))->delete();
            $now = DB::table(OperationSchema::STEPS)->where('operation_id', $op->id)->pluck('id');
            DB::table(OperationSchema::STEPS)->whereIn('id', $now)->update(['current_attempt_id' => null]);
            DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', $now)->whereNotIn('id', $attempts->pluck('id'))->delete();
            DB::table(OperationSchema::STEPS)->whereIn('id', $now)->whereNotIn('id', $steps->pluck('id'))->delete();
            $put = static function (string $table, object $row): void {
                DB::table($table)->where('id', $row->id)->exists() ? DB::table($table)->where('id', $row->id)->update((array) $row) : DB::table($table)->insert((array) $row);
            };
            foreach ($steps as $row) { $put(OperationSchema::STEPS, (object) [...(array) $row, 'current_attempt_id' => null]); }
            foreach ($attempts as $row) { $put(OperationSchema::ATTEMPTS, $row); }
            foreach ($evidence as $row) { $put(OperationSchema::EVIDENCE, $row); }
            foreach ($steps as $row) { DB::table(OperationSchema::STEPS)->where('id', $row->id)->update(['current_attempt_id' => $row->current_attempt_id]); }
            DB::table('domains_operations')->where('id', $op->id)->update($raw);
            $op->refresh();
        }
    }

    /** The renewal's sealed attempt as the production reader reads it now; null before the seal. */
    function renewalAttempt(Operation $op): ?RenewalAttempt
    { $facts = RenewalAttempt::of(Operation::findOrFail($op->id)); return $facts->sealed() ? $facts : null; }

    /**
     * The renewal's retained attempt record as the reader reads it: the sealed attempt and its terms, its reply, terminal
     * proof and read proof. Retention checks compare it before and after; an unsealed renewal has a null attempt.
     */
    function renewalRecord(Operation $op): array
    { return RenewalAttempt::of(Operation::findOrFail($op->id))->identity(true); }

    /** The sealed attempt's recorded wire outcome: a registrar disposition, unsent or unknown; null when no reply is recorded. */
    function renewalReply(Operation $op): ?array
    { $outcome = renewalAttempt($op)?->replyOutcome(); return $outcome === null ? null : ['outcome' => $outcome]; }

    /** When the renewal was sealed for dispatch, in UTC. */
    function renewalSentAt(Operation $op): DateTimeImmutable
    { $at = renewalAttempt($op)?->sentAt(); expect($at !== null, 'The renewal has a sealed attempt'); return $at; }

    /** The sealed renewal attempt's read claim: token (while held), until and unresolved; null when no read is recorded. */
    function renewalRead(Operation $op): ?array
    {
        $facts = OperationAttempts::sealed((int) $op->id, StepKey::renew());
        if (!$facts || ($facts->readToken === null && !$facts->readUnresolved)) { return null; }
        return [...($facts->readToken !== null ? ['token' => $facts->readToken] : []),
            'until' => $facts->readUntil?->getTimestamp(), 'unresolved' => $facts->readUnresolved];
    }

    /** Fixture for a lost or replaced worker: puts a read claim from renewalRead() back on the sealed attempt. */
    function setRenewalRead(Operation $op, ?array $read): void
    {
        $facts = OperationAttempts::sealed((int) $op->id, StepKey::renew());
        expect($facts !== null, 'A read claim needs a sealed renewal attempt');
        DB::table(OperationSchema::ATTEMPTS)->where('id', $facts->token->attemptId)->update(['read_token' => $read['token'] ?? null,
            'read_until' => isset($read['until']) ? OperationAttempts::at((new DateTimeImmutable)->setTimestamp($read['until'])) : null,
            'read_unresolved' => (bool) ($read['unresolved'] ?? false)]);
    }

    /** The retained positive observation of the sealed attempt, with that attempt's claim key. */
    function renewalObservation(Operation $op): ?array
    {
        $proof = RenewalProof::observation($op);
        return $proof ? [...$proof, 'claim_key' => OperationAttempts::sealed((int) $op->id, StepKey::renew())->token->claimKey] : null;
    }
}
