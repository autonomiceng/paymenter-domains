<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Paymenter\Extensions\Servers\Domains\Data\SendState;

/** Read-only projection of one attempt. Never accepted as authority by a send, refund or settlement. */
final readonly class AttemptFacts
{
    public function __construct(public AttemptToken $token, public bool $current, public bool $released,
        public ?\DateTimeImmutable $claimUntil, public ?\DateTimeImmutable $sealedAt, public ?int $operationRevision,
        public ?string $requestDigest, public SendState $sendState, public ?\DateTimeImmutable $grantConsumedAt,
        public ?string $authorizedAmount, public ?string $authorizedCurrency, public ?string $mode,
        public ?string $readToken, public ?\DateTimeImmutable $readUntil, public bool $readUnresolved, public ?\DateTimeImmutable $claimedAt,
        public ?\DateTimeImmutable $releasedAt, public ?\DateTimeImmutable $dispatchUntil) {}

    public function sealed(): bool { return $this->sealedAt !== null; }

    /** A live pre-seal claim: current, unreleased and inside its deadline. */
    public function claiming(\DateTimeImmutable $now): bool
    { return $this->current && !$this->released && !$this->sealed() && $this->claimUntil !== null && $this->claimUntil > $now; }

    public function reading(\DateTimeImmutable $now): bool
    { return $this->readToken !== null && $this->readUntil !== null && $this->readUntil > $now; }
}
