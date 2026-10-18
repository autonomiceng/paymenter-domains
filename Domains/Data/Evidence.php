<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Server-only normalized facts, never raw provider bodies, credentials, contacts or wire parameters. */
final readonly class Evidence
{
    public array $candidates;
    public function __construct(public ?DomainSnapshot $domain = null, public ?QuoteEvidence $quote = null,
        public ?Money $charge = null, public ?Money $balance = null, public ?bool $available = null,
        public ?bool $premium = null, public RenewalStatus $renewal = RenewalStatus::Unknown,
        array $candidates = [], public ?RegistrationEvidence $registration = null)
    {
        if (count($candidates) > 200) { throw new \InvalidArgumentException('Reconciliation candidates exceed the bounded budget.'); }
        $copy = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof OrderEvidence) { throw new \InvalidArgumentException('Invalid reconciliation candidate.'); }
            $copy[] = $candidate;
        }
        $this->candidates = $copy;
    }
}
