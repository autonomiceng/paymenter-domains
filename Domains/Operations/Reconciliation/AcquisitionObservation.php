<?php
// Enom 444f31c reconciliation-registration/provider-disposition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

use Paymenter\Extensions\Servers\Domains\Data\{Action, Evidence, HistoryScan, NameserverEvidence, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Services\{ReconciliationEvidence, WriteMoney};

final readonly class AcquisitionObservation
{
    public function __construct(public ?HistoryScan $scan = null, public ?Evidence $facts = null, public bool $covered = false,
        public ?array $proof = null, public ?NameserverEvidence $nameservers = null, public bool $transferConflict = false,
        public bool $chargeAnomaly = false, public ?AcquisitionCharges $charges = null, public ?\DateTimeImmutable $observedAt = null) {}

    public function transfer(): TransferStatus { return $this->facts?->transfer ?? TransferStatus::Unknown; }

    public static function from(AcquisitionRead $read, Evidence $facts, \DateTimeImmutable $now): self
    {
        $attempt = $read->attempt;
        $owned = $facts->domain?->owned === true;
        $transfer = $facts->transfer;
        $covered = ($attempt->query->action === Action::Transfer && $transfer !== TransferStatus::Unknown)
            || ($owned && $facts->domain->expiry !== null
                && ($facts->historyComplete || ($attempt->executionFacts && $attempt->providerReference !== null && $facts->candidates)));
        $count = count($facts->candidates);
        $charge = $count === 1 ? $facts->candidates[0]->charge : null;
        $expected = $attempt->expectedCharge;
        $chargeAnomaly = $count > 1 || ($charge && $expected
            && ($charge->currency !== $expected->currency || WriteMoney::compare($charge->amount, $expected->amount) !== 0));
        // One Enom charge can be a failed fee. It proves neither finality nor a settled refund.
        $allowed = $attempt->binding->registrarKey === 'enom' && $transfer === TransferStatus::Rejected ? 1 : 0;
        $conflict = in_array($transfer, [TransferStatus::Rejected, TransferStatus::NeedsAction], true) && ($owned || $count > $allowed);
        $proof = $read->followup && $chargeAnomaly ? null : ReconciliationEvidence::completion($attempt, $facts, $now);
        return new self($facts->historyScan, $facts, $covered, $proof, $facts->domain?->nameservers ?? $read->requestedNameservers,
            $conflict, $chargeAnomaly, AcquisitionCharges::from($attempt, $facts, $now), $now);
    }
}
