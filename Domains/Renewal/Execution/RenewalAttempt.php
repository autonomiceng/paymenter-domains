<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Renewal\Execution;

use Paymenter\Extensions\Servers\Domains\Data\{Money, SendState};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\Operation;

/**
 * The attempt facts of one renewal, read from the operation's retained attempt record. Read-only: it never grants a
 * send, a refund or a settlement, and a caller that acts on it holds the operation lock and rechecks through the writer.
 * Positive proof (a settlement) and negative proof (the prewire local refusal) are separate facts.
 * Other actions have no renewal attempt, so they read as never sealed.
 */
final readonly class RenewalAttempt
{
    private function __construct(private array $attempt, private ?string $state = null, private ?string $safeCode = null,
        private ?\DateTimeImmutable $startedAt = null, private ?\DateTimeImmutable $completedAt = null, private bool $observed = false) {}

    /** The renewal's attempt facts from this row as loaded. A renewal that was never sealed has no send facts but may have terminal proof. */
    public static function of(Operation $op): self
    {
        if ($op->action !== 'renew') { return new self([]); }
        $attempt = $op->attempt;
        return new self(is_array($attempt) ? $attempt : [], $op->state, $op->safe_code, $op->started_at, $op->completed_at, RenewalProof::held($op));
    }

    /** Attempt facts of many renewals in one read, keyed by every given operation id. */
    public static function many(array $operationIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $operationIds)));
        $rows = $ids ? Operation::whereKey($ids)->get(['id', 'action', 'state', 'safe_code', 'attempt', 'renewal_proof', 'started_at', 'completed_at'])->keyBy('id') : collect();
        $facts = [];
        foreach ($ids as $id) { $facts[$id] = ($row = $rows->get($id)) ? self::of($row) : new self([]); }
        return $facts;
    }

    /** True once the renewal was sealed for dispatch. From then on it is possibly sent until proved otherwise. */
    public function sealed(): bool { return ($this->attempt['seal'] ?? null) === 'sealed'; }

    /** When the renewal was sealed for dispatch, in UTC; null when never sealed. */
    public function sentAt(): ?\DateTimeImmutable
    {
        $at = $this->sealed() ? ($this->attempt['dispatch_attempted_at'] ?? null) : null;
        try { return is_string($at) ? (new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('UTC')) : null; }
        catch (\Exception) { return null; }
    }

    /** The sealed attempt's claim key, its exact identity; null when never sealed. */
    public function claimKey(): ?string
    {
        $key = $this->sealed() ? ($this->attempt['claim_key'] ?? null) : null;
        return is_string($key) ? $key : null;
    }

    /** The registrar order reference the original reply recorded for this renewal; null when none was recorded. */
    public function orderReference(): ?string
    {
        $reference = $this->sealed() ? ($this->attempt['renewal']['order_reference'] ?? null) : null;
        return is_string($reference) ? $reference : null;
    }

    /** Start of the sealed attempt's registrar read window; null when never sealed. */
    public function windowStart(): ?\DateTimeImmutable { return $this->sealed() ? $this->startedAt : null; }

    /** End of the sealed attempt's registrar read window, in UTC; null when never sealed. */
    public function windowEnd(): ?\DateTimeImmutable
    { return $this->windowStart()?->setTimezone(new \DateTimeZone('UTC'))->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds'); }

    /** True when a check of this sealed attempt retained a positive registrar observation. */
    public function observed(): bool { return $this->sealed() && $this->observed; }

    /** The sealed attempt's send state; null when never sealed. A recorded reply that settled the send state wins. */
    public function sendState(): ?SendState
    {
        if (!$this->sealed()) { return null; }
        $replied = is_array($this->attempt['execution'] ?? null) ? SendState::tryFrom((string) ($this->attempt['execution']['send_state'] ?? '')) : null;
        if ($replied !== null && $replied !== SendState::PossiblySent) { return $replied; }
        return SendState::tryFrom((string) ($this->attempt['send_state'] ?? '')) ?? SendState::PossiblySent;
    }

    /** The registrar cost the seal authorized; null when never sealed. */
    public function authorizedCost(): ?Money
    {
        $cost = $this->sealed() ? ($this->attempt['authorized_cost'] ?? null) : null;
        if (!is_array($cost) || !is_string($cost['amount'] ?? null) || !is_string($cost['currency'] ?? null)) { return null; }
        try { return new Money($cost['amount'], $cost['currency']); } catch (\InvalidArgumentException) { return null; }
    }

    /** The sealed send mode, ordinary or grace; null when never sealed. */
    public function mode(): ?string
    {
        $mode = $this->sealed() ? ($this->attempt['renewal_mode'] ?? null) : null;
        return is_string($mode) ? $mode : null;
    }

    /** Negative proof: the final transport proved it stopped before the wire. No reply, settlement or answer gives this. */
    public function locallyRefused(): bool
    { return $this->sealed() && $this->replyOutcome() === 'unsent'; }

    /** The recorded wire outcome: a registrar disposition, unsent (local refusal) or unknown; null when no reply was recorded. */
    public function replyOutcome(): ?string
    {
        if (!array_key_exists('execution', $this->attempt)) { return null; }
        $reply = $this->attempt['execution'];
        if (!is_array($reply)) { return 'unknown'; }
        if (($reply['send_state'] ?? null) === SendState::DefinitelyUnsent->value) { return 'unsent'; }
        return is_string($reply['disposition'] ?? null) ? $reply['disposition'] : 'unknown';
    }

    /**
     * The settlement recorded through a registrar read: outcome (succeeded), evidence and recorded_at; null before one
     * is recorded. Captured proof still under charge review is not a settlement. This record keeps no read-resolved
     * not_sent settlement.
     */
    public function settlement(): ?array
    {
        $proof = $this->attempt['reconciliation'] ?? null;
        return is_array($proof) && $this->state === 'succeeded'
            ? ['outcome' => 'succeeded', 'evidence' => $proof, 'recorded_at' => self::recorded($this->completedAt)] : null;
    }

    /** Operation-level local terminal proof, such as an unpaid or funds-deadline closure: outcome, evidence and recorded_at. */
    public function terminal(): ?array
    {
        $proof = $this->attempt['terminal'] ?? null;
        return is_array($proof) && $this->safeCode !== null
            ? ['outcome' => $this->safeCode, 'evidence' => $proof, 'recorded_at' => self::recorded($this->completedAt)] : null;
    }

    private static function recorded(?\DateTimeImmutable $at): ?string
    { return $at?->setTimezone(new \DateTimeZone(config('app.timezone') ?: 'UTC'))->format('Y-m-d H:i:s'); }
}
