<?php
// Adapted from NameSilo Api/RegistrarResult.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Provider disposition is independent of local operation lifecycle; acknowledgement is not completion. */
final class DriverResult implements \JsonSerializable
{
    public readonly array $discrepancies;
    private static ?\WeakMap $facts = null;
    public function __construct(public readonly AccountBinding $binding, public readonly Disposition $disposition, public readonly SendState $sendState,
        public readonly ResultReason $reason, #[\SensitiveParameter] Evidence $evidence = new Evidence,
        array $discrepancies = [], public readonly ?RejectionBasis $rejectionBasis = null)
    {
        $copy = [];
        foreach ($discrepancies as $item) {
            if (!$item instanceof Discrepancy) { throw new \InvalidArgumentException('Invalid discrepancy.'); }
            $copy[] = $item;
        }
        $this->discrepancies = $copy;
        $chargedCandidate = false;
        foreach ($evidence->candidates as $candidate) { $chargedCandidate = $chargedCandidate || $candidate->charge !== null; }
        $allowedReasons = match ($disposition) {
            Disposition::Accepted => [ResultReason::Acknowledged],
            Disposition::AcceptedWithDiscrepancy => [ResultReason::Fallback],
            Disposition::Pending => [ResultReason::Processing],
            Disposition::Rejected => $rejectionBasis === RejectionBasis::DefinitelyUnsent
                ? [ResultReason::Unsent, ResultReason::Unsupported, ResultReason::BindingMismatch, ResultReason::Unverified]
                : [ResultReason::ProvenRejected],
            Disposition::Unknown => [ResultReason::Unresolved, ResultReason::Unverified],
        };
        if (!in_array($reason, $allowedReasons, true)
            || ($disposition === Disposition::Unknown && $sendState === SendState::DefinitelyUnsent)
            || ($rejectionBasis === RejectionBasis::ProviderProof && $sendState !== SendState::Sent)
            || (($disposition === Disposition::Rejected) !== ($rejectionBasis !== null))
            || ($rejectionBasis === RejectionBasis::DefinitelyUnsent && $sendState !== SendState::DefinitelyUnsent)
            || ($disposition === Disposition::Rejected && ($evidence->charge !== null || $chargedCandidate))
            || (in_array($disposition, [Disposition::Accepted, Disposition::AcceptedWithDiscrepancy, Disposition::Pending], true)
                && $sendState !== SendState::Sent)
            || ($disposition === Disposition::AcceptedWithDiscrepancy && $discrepancies === [])
            || ($disposition !== Disposition::AcceptedWithDiscrepancy && $discrepancies !== [])) {
            throw new \InvalidArgumentException('Contradictory registrar disposition evidence.');
        }
        $binding->requireMatch($evidence->domain?->binding ?? $binding);
        $binding->requireMatch($evidence->registration?->readback?->binding ?? $binding);
        foreach ($evidence->candidates as $candidate) { $binding->requireMatch($candidate->binding); }
        $facts = self::$facts ??= new \WeakMap;
        $facts[$this] = $evidence;
    }
    public function evidence(): Evidence { return self::$facts[$this]; }
    public function jsonSerialize(): array
    {
        return ['disposition' => $this->disposition->value, 'send_state' => $this->sendState->value,
            'reason' => $this->reason->value, 'discrepancies' => array_map(fn ($d) => $d->value, $this->discrepancies)];
    }
    public function __debugInfo(): array { return $this->jsonSerialize(); }
    public function __serialize(): array { throw new \LogicException('Persist driver evidence through an encrypted storage adapter.'); }
    public function __clone(): void { throw new \LogicException('Driver results cannot be cloned.'); }
}
