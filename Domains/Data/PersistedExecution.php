<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Retained reply or support answer identity; never a spending grant. */
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
    /** A captured ID authorizes exact reads, never acceptance, replay or a refund. */
    public function capturedUnknown(): bool
    {
        return $this->binding->registrarKey === 'enom' && $this->query->action === Action::Register
            && $this->disposition === Disposition::Unknown && $this->sendState === SendState::Sent && $this->orderReference !== null;
    }
    public function requireAttempt(AttemptEvidence $attempt): void
    {
        $this->binding->requireMatch($attempt->binding);
        if (($this->query->action === Action::Renew && !in_array($this->renewalMode, ['ordinary', 'grace'], true)) || !$this->query->matches($attempt->query) || $this->operationKey !== $attempt->operationKey
            || $this->orderReference !== $attempt->providerReference || !in_array($attempt->execution,[ExecutionRecord::Recorded,ExecutionRecord::StaffAnswer],true)
            || ($attempt->execution===ExecutionRecord::StaffAnswer&&!in_array($attempt->binding->registrarKey,['enom','namesilo'],true))
            || $this->sendState !== SendState::Sent
            || (!in_array($this->disposition, [Disposition::Accepted, Disposition::AcceptedWithDiscrepancy], true)
                && !($this->capturedUnknown() && $attempt->execution === ExecutionRecord::Recorded && $attempt->seal === DispatchSeal::Sealed && $attempt->sendState === SendState::Sent))) {
            throw new \InvalidArgumentException('Execution does not prove this original attempt.');
        }
    }
}
