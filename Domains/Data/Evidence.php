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
        array $candidates = [], public ?RegistrationEvidence $registration = null, public bool $historyComplete = false, public ?RenewalEvidence $renewalExecution = null, public TransferStatus $transfer = TransferStatus::Unknown, public ?string $transferReference = null,
        public ?\DateTimeImmutable $renewalUntil = null, public bool $transferPrivacyBlocked = false, public ?RegistrarReply $registrarReply = null, public ?RegistrationRefusal $registrationRefusal = null,
        public ?HistoryScan $historyScan = null, public ?array $billZoneSample = null, public array $expiryReads = [],
        // The order detail read for the attempt's own order number completed, whether or not it found that order.
        public bool $orderRead = false)
    {
        if ($billZoneSample !== null && (!in_array($billZoneSample['zone'] ?? null, ['America/Los_Angeles', 'America/Denver'], true)
            || !is_string($billZoneSample['order'] ?? null) || !preg_match('/^[1-9][0-9]{0,19}$/D', $billZoneSample['order']))) { throw new \InvalidArgumentException('Invalid bill zone sample.'); }
        // Each expiry date one read reported for the domain, or null when that read had none. Kept apart so a disagreement stays visible.
        if (!array_is_list($expiryReads) || count($expiryReads) > 8) { throw new \InvalidArgumentException('Invalid expiry reads.'); }
        foreach ($expiryReads as $read) { if ($read !== null && (!is_string($read) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $read))) { throw new \InvalidArgumentException('Invalid expiry reads.'); } }
        if (count($candidates) > 200) { throw new \InvalidArgumentException('Reconciliation candidates exceed the bounded budget.'); }
        $copy = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof OrderEvidence) { throw new \InvalidArgumentException('Invalid reconciliation candidate.'); }
            $copy[] = $candidate;
        }
        $this->candidates = $copy;
    }
}
