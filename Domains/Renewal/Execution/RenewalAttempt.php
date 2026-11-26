<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Renewal\Execution;

use Paymenter\Extensions\Servers\Domains\Data\{ExecutionRecord, Money, SendState};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptFacts, OperationAttempts, StepKey};

/**
 * The attempt facts of one renewal, read from its sealed attempt row and evidence rows. Read-only: it never grants a
 * send, a refund or a settlement, and a caller that acts on it holds the operation lock and rechecks through the writer.
 * Positive proof (a settlement) and negative proof (the prewire local refusal) are separate facts.
 * Other actions have no renewal attempt, so they read as never sealed.
 */
final readonly class RenewalAttempt
{
    /** Evidence kinds of the sealed attempt this reader carries; operation-level terminal proof is read beside them. */
    private const KINDS = ['reply', 'observation', 'held', 'settlement', 'answer', 'terminal'];

    private function __construct(private ?AttemptFacts $sealed = null, private array $evidence = [], private ?array $terminalRow = null,
        private ?\DateTimeImmutable $startedAt = null) {}

    /** The renewal's attempt facts, in two reads. A renewal that was never sealed has no send facts but may have terminal proof. */
    public static function of(Operation $op): self
    { return $op->action === 'renew' ? self::loaded([$op])[(int) $op->id] : new self; }

    /** Attempt facts of many renewals, keyed by every given operation id. Other actions and missing rows read as never sealed. */
    public static function many(array $operationIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $operationIds)));
        $rows = $ids ? Operation::whereKey($ids)->where('action', 'renew')->get(['id', 'action', 'started_at'])->keyBy('id') : collect();
        $loaded = self::loaded($rows); $facts = [];
        foreach ($ids as $id) { $facts[$id] = $loaded[$id] ?? new self; }
        return $facts;
    }

    /**
     * Attempt facts of these loaded renewals in two reads, keyed by operation id: their sealed attempts, then the evidence
     * of those attempts and the operations' terminal proof. Other actions are left out.
     */
    public static function loaded(iterable $ops): array
    {
        $renewals = [];
        foreach ($ops as $op) { if ($op instanceof Operation && $op->action === 'renew') { $renewals[(int) $op->id] = $op; } }
        if (!$renewals) { return []; }
        $ids = array_keys($renewals);
        $sealed = OperationAttempts::sealedMany($ids, StepKey::renew());
        $evidence = OperationAttempts::evidenceMany($ids, self::KINDS);
        $facts = [];
        foreach ($renewals as $id => $op) {
            $attempt = isset($sealed[$id]) ? ($evidence[$id][$sealed[$id]->token->attemptId] ?? []) : [];
            $facts[$id] = new self($sealed[$id] ?? null, $attempt, $evidence[$id][0]['terminal'] ?? null, $op->started_at);
        }
        return $facts;
    }

    /** True while nothing is recorded for this renewal's attempt: never sealed and never closed. A released pre-seal claim is history only. */
    public function untouched(): bool { return $this->sealed === null && $this->terminalRow === null; }

    /** Local closure proof: the renewal stopped before any seal and its terminal proof was recorded. */
    public function closedUnsent(): bool { return $this->sealed === null && $this->terminalRow !== null; }

    /** True when the renewal closed before any seal and its terminal evidence is exactly $evidence. */
    public function closedOnlyWith(array $evidence): bool
    { return $this->closedUnsent() && $this->terminalRow['evidence'] === $evidence; }

    /** True while the renewal was never sealed, so no reply can exist. */
    public function unsealed(): bool { return $this->sealed === null; }

    /**
     * Proof the renewal never went out: closed before any seal, or the sealed send locally refused.
     * For display only; a refund or credit needs the gate's own proof.
     */
    public function provedUnsent(): bool { return $this->sealed() ? $this->locallyRefused() : $this->closedUnsent(); }

    /** True once the renewal was sealed for dispatch. From then on it is possibly sent until proved otherwise. */
    public function sealed(): bool { return $this->sealed !== null; }

    /** The sealed attempt's identity, for callers that bind a write to that exact attempt; null when never sealed. */
    public function attempt(): ?AttemptFacts { return $this->sealed; }

    /** When the renewal was sealed for dispatch, in UTC; null when never sealed. */
    public function sentAt(): ?\DateTimeImmutable { return $this->sealed?->sealedAt->setTimezone(new \DateTimeZone('UTC')); }

    /** When the sealed attempt started running, as stored; null when never sealed. The registrar read window cannot open before it. */
    public function claimedAt(): ?\DateTimeImmutable { return $this->sealed ? $this->startedAt : null; }

    /** The request digest the sealed attempt was claimed with; null when never sealed or when none was recorded. */
    public function requestDigest(): ?string
    { $digest = $this->sealed?->requestDigest; return $digest !== null && $digest !== '' && $digest !== '0' ? $digest : null; }

    /** The sealed attempt's claim key, its exact identity; null when never sealed. */
    public function claimKey(): ?string { return $this->sealed?->token->claimKey; }

    /** The acceptance the original reply recorded for the sealed query: its query and order_reference; null when none was recorded. */
    public function acceptance(): ?array
    {
        $acceptance = $this->replyEvidence()['acceptance'] ?? null;
        return is_array($acceptance) ? $acceptance : null;
    }

    /** The registrar order reference the original reply recorded for the sealed query; null when none was recorded. */
    public function orderReference(): ?string
    {
        $reference = $this->acceptance()['order_reference'] ?? null;
        return is_string($reference) ? $reference : null;
    }

    /** Start of the sealed attempt's registrar read window: its seal time, in the stored zone; null when never sealed. */
    public function windowStart(): ?\DateTimeImmutable { return $this->sealed?->sealedAt; }

    /** End of the sealed attempt's registrar read window, in UTC: the attempt start plus the execution limit; null when never sealed. */
    public function windowEnd(): ?\DateTimeImmutable
    {
        return $this->sealed ? $this->startedAt?->setTimezone(new \DateTimeZone('UTC'))->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds') : null;
    }

    /** True when a check of this sealed attempt retained a positive registrar observation. */
    public function observed(): bool { return isset($this->evidence['observation']); }

    /** The sealed attempt's send state; null when never sealed. A recorded reply that settled the send state is on the row. */
    public function sendState(): ?SendState { return $this->sealed?->sendState; }

    /** The registrar cost the seal authorized; null when never sealed. */
    public function authorizedCost(): ?Money
    {
        $s = $this->sealed;
        if ($s?->authorizedAmount === null || $s->authorizedCurrency === null) { return null; }
        try { return new Money($s->authorizedAmount, $s->authorizedCurrency); } catch (\InvalidArgumentException) { return null; }
    }

    /** The sealed send mode, ordinary or grace; null when never sealed. */
    public function mode(): ?string { return $this->sealed?->mode; }

    /** Negative proof: the final transport proved it stopped before the wire. No reply, settlement or answer gives this. */
    public function locallyRefused(): bool
    {
        $reply = $this->evidence['reply'] ?? null;
        return $this->sealed?->sendState === SendState::DefinitelyUnsent && ($reply['source'] ?? null) === 'local_refusal' && ($reply['outcome'] ?? null) === 'unsent';
    }

    /** Whether the original reply was recorded: absent, recorded as lost, or recorded. */
    public function replyRecord(): ExecutionRecord
    {
        $reply = $this->evidence['reply'] ?? null;
        if ($reply === null) { return ExecutionRecord::Absent; }
        return is_array($reply['evidence']['result'] ?? null) ? ExecutionRecord::Recorded : ExecutionRecord::RecordedNull;
    }

    /** The recorded original reply as the driver gave it: disposition, send_state, reason and discrepancies; null when none. */
    public function reply(): ?array
    { $result = $this->replyEvidence()['result'] ?? null; return is_array($result) ? $result : null; }

    /** The safe facts of a send that failed without a reply; null when none were recorded. */
    public function sendFailure(): ?array
    { $failure = $this->replyEvidence()['send_failure'] ?? null; return is_array($failure) ? $failure : null; }

    /** The recorded wire outcome: a registrar disposition, unsent (local refusal) or unknown; null when no reply was recorded. */
    public function replyOutcome(): ?string { return $this->evidence['reply']['outcome'] ?? null; }

    /** The positive proof a registrar read recorded for this attempt, settled or still under charge review; null before one. */
    public function readProof(): ?array
    {
        $proof = ($this->evidence['settlement'] ?? $this->evidence['held'] ?? null)['evidence']['settlement'] ?? null;
        return is_array($proof) ? $proof : null;
    }

    /**
     * The audited not-processed answer recorded on the sealed attempt while no positive proof existed; null when none.
     * It is return proof only, never local no-send proof. Positive proof retained after it voids it.
     */
    public function unprocessedAnswer(): ?array
    {
        $row = $this->evidence['answer'] ?? null;
        if ($row === null || isset($this->evidence['observation']) || isset($this->evidence['held']) || isset($this->evidence['settlement'])) { return null; }
        $answer = $row['evidence']['answer'] ?? null;
        return is_array($answer) ? $answer : null;
    }

    /** True when a registrar read captured proof that waits for charge review and no settlement was recorded. */
    public function heldForReview(): bool { return isset($this->evidence['held']) && !isset($this->evidence['settlement']); }

    /**
     * The settlement recorded on the sealed attempt: outcome, evidence (the proof the read or review settled on) and
     * recorded_at; null before one is recorded. Captured proof still under charge review is not a settlement.
     */
    public function settlement(): ?array
    {
        $row = $this->evidence['settlement'] ?? null;
        return $row ? ['outcome' => $row['outcome'], 'evidence' => $row['evidence']['settlement'] ?? null, 'recorded_at' => $row['recorded_at']] : null;
    }

    /** Operation-level local terminal proof, such as an unpaid or funds-deadline closure: outcome, evidence and recorded_at. */
    public function terminal(): ?array
    {
        $row = $this->terminalRow;
        return $row ? ['outcome' => $row['outcome'], 'evidence' => $row['evidence'], 'recorded_at' => $row['recorded_at']] : null;
    }

    /**
     * The retained facts that identify this attempt for an answer, approval or retry: the sealed attempt, its terms,
     * its reply and terminal proof. Read claims and observations are left out, and read proof unless $withProof.
     */
    public function identity(bool $withProof = false): array
    {
        $s = $this->sealed;
        return ['attempt' => $s ? [$s->token->attemptId, $s->token->claimKey, $s->sealedAt->format(DATE_ATOM), $s->requestDigest, $s->operationRevision,
            $s->authorizedAmount, $s->authorizedCurrency, $s->mode] : null,
            'reply' => $this->evidence['reply'] ?? null, 'terminal' => $this->terminalRow, 'proof' => $withProof ? $this->readProof() : null];
    }

    private function replyEvidence(): array
    { $evidence = $this->evidence['reply']['evidence'] ?? null; return is_array($evidence) ? $evidence : []; }
}
