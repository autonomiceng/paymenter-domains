<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Disposition, DomainSnapshot, OrderEvidence, SendState};

/** Decisions for a worker holding the current operation facts. */
final class RenewalDecision
{
    public static function awaitsAdmission(string $state): bool
    {
        return in_array($state, ['awaiting_payment', 'queued'], true);
    }

    /** The caller reads registrar evidence only for work that can enter admission. */
    public static function admission(string $state, ?\DateTimeInterface $lease, ?\DateTimeInterface $admissionUntil,
        \DateTimeImmutable $now): ?string
    {
        if ($state === 'running' && ($lease === null || $lease <= $now)) { return 'quarantine'; }
        if (self::awaitsAdmission($state) && ($admissionUntil === null || $admissionUntil <= $now)) { return 'admit'; }
        return null;
    }

    public static function canSend(int $reservedRevision, int $currentRevision, string $state,
        bool $reservationMatches, bool $fundingMatches, ?\DateTimeInterface $lease,
        \DateTimeImmutable $deadline, \DateTimeImmutable $now): bool
    {
        return $reservedRevision === $currentRevision && $state === 'running' && $reservationMatches && $fundingMatches
            && $lease !== null && $lease > $now && $deadline >= $now->modify('+25 seconds');
    }

    public static function reply(string $state, ?string $code, ?SendState $send, ?Disposition $disposition,
        bool $inTime): array
    {
        if ($state !== 'running') {
            return ['state' => $state, 'safe_code' => $code];
        }
        if ($send === SendState::DefinitelyUnsent) {
            return ['state' => 'held', 'safe_code' => 'renewal_definitely_unsent'];
        }
        $accepted = $inTime && in_array($disposition, [Disposition::Accepted, Disposition::Pending], true);
        return ['state' => $accepted ? 'pending' : 'uncertain', 'safe_code' => 'renewal_confirmation_required'];
    }

    public static function invoiceRefusal(?string $due, ?string $issuedDue, string $status): ?string
    {
        if ($due === null || $due !== $issuedDue) { return 'renewal_funding_or_authority'; }
        return $status === 'paid' ? null : 'renewal_invoice_unpaid';
    }

    public static function hold(string $reason, bool $retry, bool $hasPayment): array
    {
        return ['state' => $retry ? 'queued' : 'held', 'safe_code' => $reason,
            'renewal_execution' => ['reason' => $reason, 'staff_alert' => true, 'refundable_later' => !$retry && $hasPayment]];
    }

    /** A single positive reply blocks an automatic return even when it cannot prove completion. */
    public static function positiveObservation(AccountBinding $account, string $name, ?string $beforeExpiry,
        DomainSnapshot|OrderEvidence $seen): ?array
    {
        if (!$seen->binding->matches($account)) { return null; }
        if ($seen instanceof OrderEvidence) {
            if ($seen->query->name !== $name || $seen->charge === null) { return null; }
            return ['kind' => 'charged_order', 'amount' => $seen->charge->amount, 'currency' => $seen->charge->currency,
                'reference' => $seen->providerReference];
        }
        if ($seen->name !== $name || $seen->owned !== true || $beforeExpiry === null || $seen->expiry === null
            || substr($seen->expiry->value, 0, 10) <= substr($beforeExpiry, 0, 10)) { return null; }
        return ['kind' => 'advanced_expiry', 'expiry' => $seen->expiry->value, 'precision' => $seen->expiry->precision->value];
    }

    /** Charge and owned expiry have already been proved against this reservation. */
    public static function settlement(string $registrar, int $years, string $actual, string $authorized,
        string $cap, ?string $retail, bool $corrections): array
    {
        $change = WriteMoney::compare($actual, $authorized);
        $over = WriteMoney::compare($actual, $cap) > 0;
        $belowCost = $retail !== null && WriteMoney::compare($actual, $retail) > 0;
        $reason = match (true) {
            $over => 'actual_cost_overrun',
            $change !== 0 => 'actual_charge_changed',
            $belowCost => 'below_cost',
            default => 'confirmed',
        };
        // Enom's multi-year annual-rate rule requires review of any larger incurred charge.
        $review = $registrar === 'enom' && $years > 1 && $change > 0;
        return ['state' => $review ? 'uncertain' : 'succeeded',
            'safe_code' => $review ? $reason : ($change !== 0 ? 'actual_charge_changed' : null),
            'pause' => $review || $over, 'renewal_execution' => ['reason' => $reason,
                'staff_alert' => $over || $change !== 0 || $belowCost || $corrections, 'refundable_later' => false]];
    }

    public static function requireFunding(int $totalMinor, int $netMinor): void
    {
        if ($totalMinor <= 0) {
            throw new \DomainException('Invalid renewal invoice total.');
        }
        if ($netMinor < $totalMinor) {
            throw new RenewalHold('renewal_invoice_unpaid', 'The full invoice must remain paid.');
        }
    }
}
