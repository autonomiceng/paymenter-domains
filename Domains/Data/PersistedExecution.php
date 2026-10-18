<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Neutral retained response identity, not a protocol reply or a spending grant. */
final readonly class PersistedExecution
{
    public function __construct(public AccountBinding $binding, public Query $query, public string $operationKey,
        public Disposition $disposition, public SendState $sendState, public ?string $orderReference = null, public ?string $renewalMode = null)
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $operationKey)
            || ($orderReference !== null && !preg_match('/^[1-9][0-9]{0,19}$/D', $orderReference))) {
            throw new \InvalidArgumentException('Invalid retained execution identity.');
        }
    }
    public function requireAttempt(AttemptEvidence $attempt): void
    {
        $this->binding->requireMatch($attempt->binding);
        if (($this->query->action === Action::Renew && !in_array($this->renewalMode, ['ordinary', 'grace'], true)) || !$this->query->matches($attempt->query) || $this->operationKey !== $attempt->operationKey
            || $this->orderReference !== $attempt->providerReference || $attempt->execution !== ExecutionRecord::Recorded
            || $this->sendState !== SendState::Sent
            || !in_array($this->disposition, [Disposition::Accepted, Disposition::AcceptedWithDiscrepancy], true)) {
            throw new \InvalidArgumentException('Execution does not prove this original attempt.');
        }
    }
}
