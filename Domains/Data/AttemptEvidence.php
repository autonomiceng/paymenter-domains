<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Immutable reconciliation input; operation keys are never provider idempotency claims. */
final readonly class AttemptEvidence
{
    /** The reply or its order reference never arrived, so only accepted history can place the order. */
    public function replyLost(): bool { return !$this->executionFacts || $this->providerReference === null; }
    public function __construct(public AccountBinding $binding, public Query $query, public string $operationKey,
        public int $authorityGeneration, public ExecutionRecord $execution, public SendState $sendState,
        public \DateTimeImmutable $windowStart, public \DateTimeImmutable $windowEnd,
        public ?DateEvidence $beforeExpiry = null, public ?Money $expectedCharge = null, public DispatchSeal $seal = DispatchSeal::Unknown,
        public ?string $providerReference = null, public ?DateEvidence $expectedExpiry = null, public ?PersistedExecution $executionFacts = null,
        public ?HistoryAcceptance $historyAcceptance = null, public ?string $transferReference = null, public ?bool $expectedPrivacy = null)
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $operationKey) || $authorityGeneration < 1 || $windowEnd < $windowStart
            || ($providerReference !== null && !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $providerReference))
            || ($sendState === SendState::DefinitelyUnsent
                && ($execution !== ExecutionRecord::Absent || $seal !== DispatchSeal::Absent || $providerReference !== null))) {
            throw new \InvalidArgumentException('Invalid original attempt evidence.');
        }
    }
}
