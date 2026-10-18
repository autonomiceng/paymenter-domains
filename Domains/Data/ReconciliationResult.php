<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
/** Bound provider observations; local unsent attribution belongs exclusively to the engine. */
final class ReconciliationResult implements \JsonSerializable
{
    private static ?\WeakMap $facts = null;
    public readonly AccountBinding $binding;
    public readonly string $operationKey;
    public readonly Disposition $disposition;
    public function __construct(#[\SensitiveParameter] AttemptEvidence $attempt, #[\SensitiveParameter] DriverResult $result)
    {
        $attempt->binding->requireMatch($result->binding);
        if ($result->sendState === SendState::DefinitelyUnsent || $result->rejectionBasis === RejectionBasis::DefinitelyUnsent) {
            throw new \InvalidArgumentException('Provider reconciliation cannot establish local unsent attribution.');
        }
        $evidence = $result->evidence();
        if (($evidence->domain !== null && $evidence->domain->name !== $attempt->query->name)
            || ($evidence->quote !== null && !$evidence->quote->query->matches($attempt->query))) {
            throw new \InvalidArgumentException('Reconciliation facts do not match the original query.');
        }
        foreach ($evidence->candidates as $candidate) {
            if (!$candidate->query->matches($attempt->query)
                || ($attempt->providerReference !== null && $candidate->providerReference !== $attempt->providerReference)) {
                throw new \InvalidArgumentException('Reconciliation candidate does not match the original attempt.');
            }
        }
        $this->binding = $attempt->binding; $this->operationKey = $attempt->operationKey; $this->disposition = $result->disposition;
        $facts = self::$facts ??= new \WeakMap; $facts[$this] = [$attempt, $result];
    }
    public function attempt(): AttemptEvidence { return self::$facts[$this][0]; }
    public function result(): DriverResult { return self::$facts[$this][1]; }
    public function requireAttempt(AttemptEvidence $attempt): void
    {
        if ($this->attempt() !== $attempt) { throw new \InvalidArgumentException('Reconciliation belongs to a different frozen attempt.'); }
    }
    public function jsonSerialize(): array { return $this->result()->jsonSerialize(); }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('Persist reconciliation through an encrypted storage adapter.'); }
    public function __clone(): void { throw new \LogicException('Reconciliation results cannot be cloned.'); }
}
