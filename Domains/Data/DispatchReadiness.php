<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Read evidence only; the engine must still revalidate current financial authority and seal. */
final readonly class DispatchReadiness
{
    public function __construct(public AccountBinding $binding, public Query $query, public Capabilities $capabilities,
        public Money $cost, public bool $eligible, public ?bool $premium,
        public \DateTimeImmutable $observedAt, public \DateTimeImmutable $validUntil, public string $payloadDigest) {}

    public function requireCurrent(AccountBinding $binding, Query $query, string $digest, \DateTimeImmutable $now): void
    {
        $binding->requireMatch($this->binding); $binding->requireMatch($this->capabilities->binding);
        if (!$query->matches($this->query) || !$this->capabilities->get($query->action->value)->allows($query->years)
            || !$this->eligible || $this->premium !== false || $this->cost->currency !== 'USD'
            || $this->observedAt > $now || $this->validUntil <= $now || $this->validUntil <= $this->observedAt
            || $this->payloadDigest !== $digest) { throw new \DomainException('Dispatch preflight is not current.'); }
    }
}
